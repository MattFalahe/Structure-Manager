<?php

namespace StructureManager\Integrations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use StructureManager\Handlers\StructureEventHandler;
use StructureManager\Models\StructureManagerSettings;

/**
 * Integration point with Manager Core.
 *
 * All Manager Core awareness flows through this class. Nothing else in
 * Structure Manager should directly reference ManagerCore namespaces —
 * go through here so detection logic stays in one place.
 *
 * Design goals:
 *  - Structure Manager works identically whether Manager Core is present or absent.
 *  - When Manager Core IS present, Structure Manager opts into MC's 2-minute
 *    fast-poll for the notification types it cares about.
 *  - When Manager Core is NOT present, Structure Manager falls back to reading
 *    from SeAT's native character_notifications table (ProcessStructureNotifications job).
 */
class ManagerCoreIntegration
{
    /**
     * Detection mode setting key (in structure_manager_settings).
     *
     * Values:
     *   'auto'        — default; use MC fast-poll if available, otherwise SeAT native sweep
     *   'seat_native' — always use SeAT native sweep, even if MC is installed
     *                   (opt-out of MC fast-poll for operators who prefer to
     *                    keep notification detection inside SeAT's native path)
     *   'off'         — don't run any detection (operator manually disabled)
     *
     * Operators may also leave the legacy boolean `esi_polling_enabled` set
     * to false to disable detection — kept honored for backward-compat. New
     * deployments should use `esi_detection_mode` instead.
     */
    public const MODE_AUTO        = 'auto';
    public const MODE_SEAT_NATIVE = 'seat_native';
    public const MODE_OFF         = 'off';

    /**
     * Is Manager Core installed and its ESI notification registry available?
     *
     * We check the registry class specifically (not just any MC class) so
     * we only return true when the ESI infrastructure is present. An older
     * MC without the ESI system would return false here.
     */
    public static function isAvailable(): bool
    {
        return class_exists('\ManagerCore\Services\ESI\EsiNotificationRegistry');
    }

    /**
     * Read the operator's chosen detection mode, defaulting to 'auto'.
     * Falls through to the legacy `esi_polling_enabled` boolean: if that's
     * explicitly false AND no mode is set, treat as 'off'.
     */
    public static function detectionMode(): string
    {
        $mode = StructureManagerSettings::get('esi_detection_mode', null);
        if ($mode !== null && in_array($mode, [self::MODE_AUTO, self::MODE_SEAT_NATIVE, self::MODE_OFF], true)) {
            return $mode;
        }

        // Legacy fallback — old installs without esi_detection_mode set
        $polling = StructureManagerSettings::get('esi_polling_enabled', true);
        return $polling ? self::MODE_AUTO : self::MODE_OFF;
    }

    /**
     * Should SM register its handler with MC's fast-poll?
     *
     * True only when:
     *   - MC is installed (registry class exists)
     *   - Detection mode is 'auto' (default)
     *
     * Operators can deliberately opt out of MC fast-poll by setting mode to
     * 'seat_native' even when MC is installed — falls back to SeAT's native
     * notification table. Use cases:
     *   - Don't want a director key in MC's shared pool
     *   - Privacy / least-privilege concerns about cross-plugin polling
     *   - Other tools depending on SeAT's native cadence
     *   - Want to keep notifications under SeAT's native rate limits only
     */
    public static function isFastPollEnabled(): bool
    {
        return self::isAvailable() && self::detectionMode() === self::MODE_AUTO;
    }

    /**
     * Should the SM-side `ProcessStructureNotifications` sweep run? True when:
     *   - Detection mode is 'auto' AND MC is absent (sweep is the fallback)
     *   - Detection mode is 'seat_native' (sweep is the chosen path even with MC)
     *
     * False when:
     *   - Detection mode is 'off' (operator disabled all detection)
     *   - MC is present AND mode is 'auto' (MC is doing the work)
     */
    public static function isNativeSweepEnabled(): bool
    {
        $mode = self::detectionMode();
        if ($mode === self::MODE_OFF) {
            return false;
        }
        if ($mode === self::MODE_SEAT_NATIVE) {
            return true;
        }
        // mode is 'auto' — sweep runs only as fallback when MC is absent
        return !self::isAvailable();
    }

    /**
     * Register Structure Manager's event handler with MC's notification registry.
     *
     * Called at service-provider boot. No-op if MC is absent OR the operator
     * has set detection mode to 'seat_native' / 'off'.
     *
     * After this returns (when fast-poll is enabled), any time MC's fast-poll
     * finds one of our types, StructureEventHandler::handle() will be invoked.
     */
    public static function registerStructureEventHandler(): void
    {
        if (!self::isFastPollEnabled()) {
            // MC absent OR operator chose seat_native / off — no fast-poll
            // registration. SM either uses its own sweep (modes auto-without-MC
            // or seat_native) or does nothing (mode off).
            return;
        }

        try {
            $registry = app('\ManagerCore\Services\ESI\EsiNotificationRegistry');
            $registry->register(
                StructureEventHandler::registeredTypes(),
                StructureEventHandler::class,
                'structure-manager'
            );

            Log::info('[Structure Manager] Registered ' . count(StructureEventHandler::registeredTypes()) . ' notification types with Manager Core (mode=auto)');
        } catch (\Throwable $e) {
            Log::warning('[Structure Manager] Could not register with Manager Core: ' . $e->getMessage());
        }
    }

    /**
     * Does the admin need the one-time migration of SM's key pool into MC?
     *
     * True when:
     *  - MC's table exists (MC is installed)
     *  - SM's old table exists and has rows
     *  - MC's table is empty (we haven't migrated yet)
     */
    public static function isKeyPoolMigrationNeeded(): bool
    {
        if (!self::isAvailable()) {
            return false;
        }

        if (!Schema::hasTable('manager_core_esi_key_holders')) {
            return false;
        }

        if (!Schema::hasTable('structure_manager_esi_key_holders')) {
            return false;
        }

        $smCount = DB::table('structure_manager_esi_key_holders')->count();
        $mcCount = DB::table('manager_core_esi_key_holders')->count();

        return $smCount > 0 && $mcCount === 0;
    }

    /**
     * Copy SM's key holder pool into MC's table.
     *
     * Safe to run multiple times — skips characters already in MC.
     * Returns the number of rows inserted into MC.
     */
    public static function migrateKeyPoolToManagerCore(): int
    {
        if (!Schema::hasTable('manager_core_esi_key_holders')) {
            return 0;
        }

        if (!Schema::hasTable('structure_manager_esi_key_holders')) {
            return 0;
        }

        $migrated = 0;

        DB::table('structure_manager_esi_key_holders')
            ->orderBy('id')
            ->get()
            ->each(function ($row) use (&$migrated) {
                $exists = DB::table('manager_core_esi_key_holders')
                    ->where('character_id', $row->character_id)
                    ->exists();
                if ($exists) {
                    return;
                }

                DB::table('manager_core_esi_key_holders')->insert([
                    'character_id' => $row->character_id,
                    'corporation_id' => $row->corporation_id,
                    'character_name' => $row->character_name,
                    'enabled' => $row->enabled,
                    'last_polled_at' => $row->last_polled_at,
                    'last_poll_status' => $row->last_poll_status,
                    'last_error' => $row->last_error,
                    'consecutive_failures' => $row->consecutive_failures ?? 0,
                    'total_polls' => $row->total_polls ?? 0,
                    'total_notifications_found' => $row->total_notifications_found ?? 0,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ]);

                $migrated++;
            });

        if ($migrated > 0) {
            Log::info("[Structure Manager] Migrated {$migrated} key holder(s) from Structure Manager's pool into Manager Core's shared pool.");
        }

        return $migrated;
    }

    /**
     * Summary for diagnostics / settings view. Reports the EFFECTIVE detection
     * mode (what's actually happening) plus the configured mode (what the
     * operator chose), since the two can diverge — e.g. configured=auto but
     * MC isn't installed, so effective is native_sweep.
     */
    public static function status(): array
    {
        $available     = self::isAvailable();
        $configured    = self::detectionMode();
        $fastPoll      = self::isFastPollEnabled();
        $nativeSweep   = self::isNativeSweepEnabled();

        if ($fastPoll) {
            $effectiveLabel = 'fast_poll (Manager Core, ~2 min)';
        } elseif ($nativeSweep) {
            $effectiveLabel = 'native_sweep (SeAT, ~20-30 min)';
        } else {
            $effectiveLabel = 'off (no detection)';
        }

        $data = [
            'available'              => $available,
            'configured_mode'        => $configured,
            'effective_mode'         => $fastPoll ? 'fast_poll' : ($nativeSweep ? 'native_sweep' : 'off'),
            'detection_mode'         => $effectiveLabel, // legacy key kept for view compat
            'handler_registered'     => false,
            'key_pool_route'         => $available ? 'manager-core.esi-key-pool.index' : null,
            'mc_available_but_native' => $available && $configured === self::MODE_SEAT_NATIVE,
        ];

        if ($available) {
            try {
                $registry = app('\ManagerCore\Services\ESI\EsiNotificationRegistry');
                $data['handler_registered'] = $registry->hasHandlersForType('StructureUnderAttack');
            } catch (\Throwable $e) {
                // ignore
            }
        }

        return $data;
    }

    /**
     * Dispatch Manager Core's ESI fast-poll job immediately.
     *
     * Called from the diagnostic page's "Run Now" button. Dispatches the
     * job directly via Laravel's queue rather than going through
     * Artisan::call('manager-core:poll-esi-notifications'), because MC's
     * ServiceProvider registers its commands inside a
     * `$this->app->runningInConsole()` guard — so those commands don't
     * exist in Artisan's registry during HTTP requests.
     *
     * Dispatching the job directly works in both CLI and HTTP contexts.
     *
     * @return bool true if dispatched, false if MC is not available
     */
    public static function triggerFastPoll(): bool
    {
        if (!self::isAvailable()) {
            return false;
        }
        if (!class_exists('\ManagerCore\Jobs\ESI\PollEsiNotifications')) {
            return false;
        }

        dispatch(new \ManagerCore\Jobs\ESI\PollEsiNotifications());
        return true;
    }

    /**
     * Dispatch Manager Core's SeAT-notification sweep job.
     *
     * Same rationale as triggerFastPoll — avoid Artisan::call from HTTP.
     *
     * @return bool true if dispatched, false if MC is not available
     */
    public static function triggerSweep(): bool
    {
        if (!self::isAvailable()) {
            return false;
        }
        if (!class_exists('\ManagerCore\Jobs\ESI\SweepSeatNotifications')) {
            return false;
        }

        dispatch(new \ManagerCore\Jobs\ESI\SweepSeatNotifications());
        return true;
    }
}
