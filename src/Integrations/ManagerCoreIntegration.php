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
            // IMPORTANT: resolve via the class constant (`::class`) not a
            // single-quoted string with a leading backslash. Laravel 11's
            // container no longer normalises leading backslashes — so
            // `app('\\ManagerCore\\Services\\ESI\\EsiNotificationRegistry')`
            // and `app(\\ManagerCore\\Services\\ESI\\EsiNotificationRegistry::class)`
            // are TWO DIFFERENT BINDING KEYS. MC binds the singleton with the
            // class constant form (no leading backslash). Resolving with a
            // leading backslash bypasses the binding entirely and Laravel
            // auto-builds a fresh instance every call — the handler we register
            // lands on that throwaway instance and MC's poll job sees an empty
            // registry. See the 2026-05-11 debug session for the trace.
            $registry = app(\ManagerCore\Services\ESI\EsiNotificationRegistry::class);
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
     * The fuel-related typeIDs Structure Manager needs market prices for
     * to compute the Economics page. Sent to MC via `pricing.subscribeTypes`
     * at boot so MC's price-refresh job knows to fetch them.
     *
     * Without this subscription, MCs `manager_core_market_prices` table
     * stays empty for these types and the Economics page renders 0 ISK
     * across the board (priceForPlugin returns null for every row, the
     * service skips the row, totals end up zero).
     *
     * Mirrors the type list FuelEconomicsService walks:
     *   - 4 fuel blocks (Upwell + POS racial fuel)
     *   - magmatic gas (Metenox dual-fuel)
     *   - strontium clathrates (POS reinforce reagent)
     *   - 6 charter types (POS in high-sec)
     */
    public const REQUIRED_PRICING_TYPE_IDS = [
        4051,  // Nitrogen Fuel Block (Caldari)
        4246,  // Hydrogen Fuel Block (Minmatar)
        4247,  // Helium Fuel Block (Amarr)
        4312,  // Oxygen Fuel Block (Gallente)
        81143, // Magmatic Gas (Metenox)
        16275, // Strontium Clathrates (POS)
        24592, // Amarr Empire Starbase Charter
        24593, // Caldari State Starbase Charter
        24594, // Gallente Federation Starbase Charter
        24595, // Minmatar Republic Starbase Charter
        24596, // Khanid Kingdom Starbase Charter
        24597, // Ammatar Mandate Starbase Charter
    ];

    /**
     * Pricing-integration mode setting key + values. Controls whether SM
     * registers a pricing preference with MC and whether the Economics
     * page is reachable. Two values:
     *
     *   'auto'     (default) — register at boot if MC pricing is available;
     *                          show Economics in sidebar.
     *   'disabled'           — skip registration, hide Economics from
     *                          sidebar even if MC is installed.
     *
     * Operator opts out via SM Settings > Economics tab. Distinct from
     * the ESI detection mode: an admin might use SeAT-native ESI while
     * still wanting the Economics page (or vice versa). The two
     * integrations are independent.
     */
    public const ECONOMICS_MODE_AUTO     = 'auto';
    public const ECONOMICS_MODE_DISABLED = 'disabled';

    /**
     * Is MC's pricing infrastructure available (for the Economics page)?
     *
     * Distinct from isAvailable(): we need the PluginBridge plus the
     * PricingService class plus the PricingPreference model. An older MC
     * without the per-plugin pricing system would fail this check while
     * still satisfying isAvailable() (which gates ESI fast-poll).
     */
    public static function isPricingAvailable(): bool
    {
        return class_exists('\ManagerCore\Services\PluginBridge')
            && class_exists('\ManagerCore\Services\PricingService')
            && class_exists('\ManagerCore\Models\PricingPreference');
    }

    /**
     * Read the operator's chosen Economics integration mode, defaulting
     * to 'auto'. Validates the stored value so a stray DB edit can't
     * surface an unknown mode.
     */
    public static function economicsPricingMode(): string
    {
        $mode = StructureManagerSettings::get('economics_pricing_mode', self::ECONOMICS_MODE_AUTO);
        if (!in_array($mode, [self::ECONOMICS_MODE_AUTO, self::ECONOMICS_MODE_DISABLED], true)) {
            return self::ECONOMICS_MODE_AUTO;
        }
        return $mode;
    }

    /**
     * Should the Economics page be reachable for this install?
     *
     * True only when:
     *   - MC pricing infrastructure is installed (class_exists check)
     *   - Operator hasn't explicitly opted out via SM Settings
     *
     * Used by the sidebar to hide the entry, the controller to redirect
     * to a notice page, and the boot logic to skip registration.
     */
    public static function isEconomicsEnabled(): bool
    {
        return self::isPricingAvailable()
            && self::economicsPricingMode() === self::ECONOMICS_MODE_AUTO;
    }

    /**
     * Register Structure Manager's pricing preference with Manager Core.
     *
     * Called at service-provider boot. No-op when MC pricing isn't
     * available. Idempotent: PricingPreference::registerDefault inserts
     * on first run, refreshes on subsequent runs ONLY if the admin has
     * not overridden via the MC admin UI.
     *
     * Default: Jita sell. Operator can change in MC > Pricing Preferences.
     *
     * IMPLEMENTATION NOTE: We call PricingPreference::registerDefault()
     * directly rather than going through the PluginBridge capability
     * `pricing.registerPreference`. Reason: boot-order. SM's provider
     * boots in an undefined order relative to MC's. If SM boots first,
     * MC's capability isn't registered on the bridge yet — and
     * bridge->call() returns null silently on missing capability instead
     * of throwing, so the previous bridge-based implementation looked
     * successful from SM's side while writing nothing.
     *
     * Calling the model directly works because PHP class autoloading is
     * lazy (PricingPreference is loaded when first referenced, not at
     * MC-provider-boot time) and the DB connection + migrated table are
     * both available by the time any provider boots. The bridge
     * capability `pricing.registerPreference` still exists for any other
     * plugin (e.g. a future Mining Manager appraisal feature) that needs
     * to register from outside MC's class namespace.
     */
    public static function registerPricingPreference(): void
    {
        if (!self::isPricingAvailable()) {
            return;
        }

        try {
            $pref = \ManagerCore\Models\PricingPreference::registerDefault(
                'structure-manager',
                'jita',
                'sell',
                'Default for Structure Manager Economics page (Jita sell prices)'
            );
            Log::info(sprintf(
                '[Structure Manager] Registered pricing preference with Manager Core (id=%d, market=%s, price_type=%s, admin_overridden=%s)',
                $pref->id,
                $pref->market,
                $pref->price_type,
                $pref->admin_overridden ? 'yes' : 'no'
            ));
        } catch (\Throwable $e) {
            Log::warning('[Structure Manager] Could not register pricing preference with Manager Core: ' . $e->getMessage());
        }
    }

    /**
     * Subscribe SM's required typeIDs (fuel blocks, magmatic gas, strontium,
     * charters) to MC's price-refresh system.
     *
     * Without this call, MC's `manager_core_market_prices` table never gets
     * populated for these types and the Economics page renders zero across
     * the board even though the rest of the integration works.
     *
     * Subscribes against whichever market the current preference points to
     * (defaults to jita). When admin changes the market in MC's pricing-
     * preferences page, call this again (re-register button does it) so
     * MC starts fetching from the new market.
     *
     * Same direct-service-call pattern as registerPricingPreference: bypass
     * the bridge to avoid the boot-order silent-fail mode. PricingService is
     * a singleton, lazy-loaded; it works at any lifecycle moment.
     *
     * The underlying registerTypes is idempotent (updateOrCreate per row).
     *
     * Why $immediateRefresh defaults to FALSE:
     *
     *   Service providers boot per-HTTP-request in Laravel. If the boot
     *   call passes immediateRefresh=true and any of the subscribed types
     *   has no cached price (e.g. citadel market intermittently failing),
     *   MC dispatches a RefreshMarketPricesJob on EVERY request. With
     *   active browser tabs polling the dashboard/diagnostic pages, this
     *   produces a job storm (one dispatch every 1-2 seconds observed in
     *   Horizon — see commit history around 2026-05-18).
     *
     *   The 4-hourly MC `manager-core:update-prices` cron tick is the
     *   right place to populate cold prices; boot just needs to record
     *   the subscription. Mining Manager solved the same bug the same
     *   way earlier.
     *
     *   When an admin explicitly clicks "Re-register" / saves Economics
     *   settings (SettingsController), pass true to force a one-shot
     *   refresh — that's a deliberate user action, not a per-request
     *   side effect.
     */
    public static function subscribePricingTypes(bool $immediateRefresh = false): void
    {
        if (!self::isPricingAvailable()) {
            return;
        }

        try {
            $market = self::resolveCurrentPricingMarket();
            // Resolve via class constant (no leading backslash) — see note on
            // registerStructureEventHandler() for why the leading-backslash
            // string form would silently break the singleton binding.
            $service = app(\ManagerCore\Services\PricingService::class);
            $service->registerTypes(
                'structure-manager',
                self::REQUIRED_PRICING_TYPE_IDS,
                $market,
                /* priority */ 1,
                /* immediateRefresh */ $immediateRefresh
            );
            Log::info(sprintf(
                '[Structure Manager] Subscribed %d pricing types on market=%s (immediate_refresh=%s)',
                count(self::REQUIRED_PRICING_TYPE_IDS),
                $market,
                $immediateRefresh ? 'true' : 'false'
            ));
        } catch (\Throwable $e) {
            Log::warning('[Structure Manager] Could not subscribe pricing types with Manager Core: ' . $e->getMessage());
        }
    }

    /**
     * Resolve which market the current pricing preference uses. Falls
     * back to 'jita' when no preference row exists or the preference
     * model isn't reachable.
     */
    private static function resolveCurrentPricingMarket(): string
    {
        try {
            if (class_exists('\ManagerCore\Models\PricingPreference')) {
                $pref = \ManagerCore\Models\PricingPreference::forPlugin('structure-manager');
                if ($pref) {
                    return $pref->market;
                }
            }
        } catch (\Throwable $e) {
            // ignore — fall through to default
        }
        return 'jita';
    }

    /**
     * Resolve the ISK price for a single typeId via MC's pricing service,
     * honoring SM's registered preference (or admin override).
     *
     * Returns null when:
     *   - MC pricing isn't available
     *   - Price isn't cached for that type yet (subscriber needs time
     *     to fetch it on the next refresh cycle)
     *
     * Callers should treat null as "unknown cost" (skip from totals)
     * rather than zero (which would silently hide expense).
     */
    public static function priceForType(int $typeId): ?float
    {
        if (!self::isPricingAvailable()) {
            return null;
        }
        try {
            return app(\ManagerCore\Services\PricingService::class)
                ->priceForPlugin('structure-manager', $typeId);
        } catch (\Throwable $e) {
            Log::warning('[Structure Manager] Pricing lookup failed for type ' . $typeId . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Batch version of priceForType. Returns [typeId => ?float].
     * Use this when looking up multiple types in one go (e.g. summing
     * fuel-block + magmatic-gas + strontium + charters for a structure).
     *
     * @param int[] $typeIds
     * @return array<int, ?float>
     */
    public static function pricesForTypes(array $typeIds): array
    {
        if (!self::isPricingAvailable() || empty($typeIds)) {
            return [];
        }
        try {
            return app(\ManagerCore\Services\PricingService::class)
                ->pricesForPlugin('structure-manager', $typeIds);
        } catch (\Throwable $e) {
            Log::warning('[Structure Manager] Batch pricing lookup failed: ' . $e->getMessage());
            return [];
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
            $effectiveLabel = 'native_sweep (SeAT, ~15-20 min)';
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
                // Resolve via class constant — see registerStructureEventHandler note.
                $registry = app(\ManagerCore\Services\ESI\EsiNotificationRegistry::class);
                $data['handler_registered'] = $registry->hasHandlersForType('StructureUnderAttack');
            } catch (\Throwable $e) {
                // ignore
            }
        }

        return $data;
    }

    /**
     * Register the pre-timer reminder subscriber with Manager Core's EventBus.
     *
     * Subscribes PreTimerReminderHandler to the three scheduled `timer.upcoming_*`
     * windows so MC's EventBus dispatches the handler whenever
     * PublishTimerScheduleEvents fires one of those events. Without this
     * subscription, scheduled timer events are published but nothing consumes
     * them — no reminder pings reach Discord.
     *
     * Why a separate subscription per window (instead of `timer.upcoming_*`
     * wildcard): EventBus DOES support wildcards, but pinning the three
     * specific patterns gives us a clean per-window log line and per-window
     * disable-via-unsubscribe pathway if a future setting needs to silence
     * just one window without touching the others.
     *
     * Idempotent: EventBus::subscribeHandler updates the row only if attributes
     * changed, so calling on every boot is cheap (one SELECT per pattern).
     *
     * No-op when Manager Core is absent — no MC, no EventBus, no scheduled
     * timer events. The reminder feature is MC-required by design (MC IS
     * the scheduled-event infrastructure). SM's standalone path still
     * covers under-attack alerts via SeAT's native sweep.
     */
    public static function registerPreTimerReminderSubscriber(): void
    {
        if (!self::isAvailable()) {
            return;
        }
        if (!class_exists('\ManagerCore\Services\EventBus')) {
            return;
        }

        try {
            // Resolve via class constant — same boot-order caveat as elsewhere
            // (single-quoted string with leading backslash creates a different
            // binding key than the class-constant form). Bind through the
            // service container so we always get MC's singleton.
            $bus = app(\ManagerCore\Services\EventBus::class);

            $patterns = [
                'structure_manager.timer.upcoming_24h',
                'structure_manager.timer.upcoming_6h',
                'structure_manager.timer.upcoming_1h',
            ];

            foreach ($patterns as $pattern) {
                $bus->subscribeHandler(
                    'structure-manager',
                    $pattern,
                    \StructureManager\Handlers\PreTimerReminderHandler::class,
                    'handle',
                    [
                        // Async — webhook dispatch shouldn't block the publishing
                        // job (PublishTimerScheduleEvents). Queued via default
                        // queue. Reminders are time-relevant but not time-critical;
                        // a few seconds of queue latency is acceptable.
                        'queued'   => true,
                        'priority' => 10, // medium — above passive subscribers, below alert subscribers
                    ]
                );
            }

            Log::info('[Structure Manager] Pre-timer reminder handler subscribed to 3 timer.upcoming_* event patterns');
        } catch (\Throwable $e) {
            Log::warning('[Structure Manager] Could not register pre-timer reminder subscriber with Manager Core: ' . $e->getMessage());
        }
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
