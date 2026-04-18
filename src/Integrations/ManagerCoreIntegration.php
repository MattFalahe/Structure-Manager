<?php

namespace StructureManager\Integrations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use StructureManager\Handlers\StructureEventHandler;

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
     * Register Structure Manager's event handler with MC's notification registry.
     *
     * Called at service-provider boot. No-op if MC is absent.
     *
     * After this returns, any time MC's fast-poll finds one of our types,
     * StructureEventHandler::handle() will be invoked with the notification.
     */
    public static function registerStructureEventHandler(): void
    {
        if (!self::isAvailable()) {
            return;
        }

        try {
            $registry = app('\ManagerCore\Services\ESI\EsiNotificationRegistry');
            $registry->register(
                StructureEventHandler::registeredTypes(),
                StructureEventHandler::class,
                'structure-manager'
            );

            Log::info('[Structure Manager] Registered ' . count(StructureEventHandler::registeredTypes()) . ' notification types with Manager Core');
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
     * Summary for diagnostics / settings view.
     */
    public static function status(): array
    {
        $available = self::isAvailable();

        $data = [
            'available' => $available,
            'detection_mode' => $available ? 'fast_poll (Manager Core)' : 'native_sweep (SeAT ~20-30 min)',
            'handler_registered' => false,
            'key_pool_route' => $available ? 'manager-core.esi-key-pool.index' : null,
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
