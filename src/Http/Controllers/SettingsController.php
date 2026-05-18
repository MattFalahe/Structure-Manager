<?php

namespace StructureManager\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use StructureManager\Integrations\ManagerCoreIntegration;
use StructureManager\Models\NotificationCategory;
use StructureManager\Models\StructureManagerSettings;
use StructureManager\Models\WebhookConfiguration;
use StructureManager\Services\DiscordRoleResolver;

/**
 * Controller for plugin settings management
 *
 * UPDATED: Added support for multiple webhooks with corporation filtering
 * UPDATED: Notifications settings (categories + bindings) now folded into the
 *          settings page as a sidebar section, mirroring Mining Manager's pattern.
 */
class SettingsController extends Controller
{
    /**
     * Display settings page
     */
    public function index()
    {
        // Get settings by category
        $notificationSettings = StructureManagerSettings::getByCategory('notifications');
        $thresholdSettings = StructureManagerSettings::getByCategory('thresholds');
        $generalSettings = StructureManagerSettings::getByCategory('general');
        $reservesSettings = StructureManagerSettings::getByCategory('reserves');

        // Get webhooks (ordered for the Notifications section's bindings tables)
        $webhooks = WebhookConfiguration::orderBy('description')
            ->orderBy('id')
            ->get();

        // Get available corporations for dropdown
        $corporations = $this->getAvailableCorporations();

        // === Notifications section data (mirrors NotificationController::index) ===

        $categories = NotificationCategory::orderBy('namespace')
            ->orderBy('sort_order')
            ->orderBy('display_name')
            ->get()
            ->groupBy('namespace');

        // Map category_id -> array of webhook_ids bound (for pre-checking the multi-select)
        $bindings = DB::table('structure_manager_category_webhook')
            ->get()
            ->groupBy('category_id');

        // Role provider detection (may return multiple — we union them)
        $roleProviders          = DiscordRoleResolver::detectAvailableProviders();
        $roleProviderLabel      = DiscordRoleResolver::providerLabel();
        $roleProviderAvailable  = !empty($roleProviders);
        $roleProvider           = $roleProviders[0] ?? null; // primary for legacy checks

        // Discord role lookup map (snowflake => role data). Built once here
        // and shared by both the Notifications panel (role-name badges +
        // Webhooks Summary) and the Notification Routing Map tab, so the
        // underlying role tables are queried a single time per page render.
        $smRoleLookup = DiscordRoleResolver::roleLookupMap();

        // Namespace display metadata (order + labels + legacy hint)
        $namespaces = [
            'upwell' => [
                'label' => 'Upwell Structures',
                'legacy' => false,
                'description' => 'Citadels, engineering complexes, refineries, Metenox moon drills — notifications driven by periodic fuel-bay polling.',
            ],
            'events' => [
                'label' => 'Structure Events (ESI Notifications)',
                'legacy' => false,
                'description' => 'Attack alerts, anchoring, ownership transfers — driven by EVE\'s notification stream via Manager Core fast-poll (or SeAT native if MC absent).',
            ],
            'pos' => [
                'label' => 'POS (Legacy Starbases)',
                'legacy' => true,
                'description' => 'CCP legacy structures. May be removed by CCP in a future patch — kept isolated for clean removal.',
            ],
        ];

        return view('structure-manager::settings.index', compact(
            'notificationSettings',
            'thresholdSettings',
            'generalSettings',
            'reservesSettings',
            'webhooks',
            'corporations',
            'categories',
            'bindings',
            'namespaces',
            'roleProvider',
            'roleProviders',
            'roleProviderLabel',
            'roleProviderAvailable',
            'smRoleLookup'
        ));
    }
    
    /**
     * Get all available corporations
     * Includes corporations with characters AND corporations from POS data
     */
    private function getAvailableCorporations()
    {
        // Get ALL corporations from the SeAT database
        // This allows filtering to any corporation in the system, not just user's corps
        $corporations = \DB::table('corporation_infos')
            ->select('corporation_id', 'name')
            ->orderBy('name')
            ->get();
        
        return $corporations;
    }
    
    /**
     * Update settings
     */
    public function update(Request $request)
    {
        try {
            // POS thresholds remain configurable per install (matches v1.0.11
            // contract). Wormhole / null-sec POS deployments need extended
            // response time that fixed defaults cannot accommodate.
            //
            // UPWELL thresholds (upwell_fuel_critical_days, _warning_days)
            // are LOCKED in code via FuelThresholds — they're newer and the
            // settings UI for them was removed.
            $request->validate([
                // POS thresholds — configurable
                'pos_strontium_critical_hours' => 'nullable|integer|min:1|max:72',
                'pos_strontium_warning_hours'  => 'nullable|integer|min:1|max:72',
                'pos_strontium_good_hours'     => 'nullable|integer|min:1|max:72',
                'pos_fuel_critical_days'       => 'nullable|integer|min:1|max:90',
                'pos_fuel_warning_days'        => 'nullable|integer|min:1|max:90',
                'pos_charter_critical_days'    => 'nullable|integer|min:1|max:90',
                // Cadence (operator-preference, both POS and Upwell)
                'pos_fuel_notification_interval'      => 'nullable|integer|min:0|max:24',
                'pos_strontium_notification_interval' => 'nullable|integer|min:0|max:12',
                'upwell_fuel_notification_interval'   => 'nullable|integer|min:0|max:24',
            ]);

            // POS threshold ordering checks (only run when the form actually
            // submitted these keys — the Upwell tab doesn't include them).
            if ($request->filled('pos_strontium_critical_hours') && $request->filled('pos_strontium_warning_hours')) {
                if ((int) $request->pos_strontium_critical_hours >= (int) $request->pos_strontium_warning_hours) {
                    return redirect()->back()
                        ->with('error', 'POS strontium critical threshold must be less than warning threshold');
                }
            }
            if ($request->filled('pos_strontium_warning_hours') && $request->filled('pos_strontium_good_hours')) {
                if ((int) $request->pos_strontium_warning_hours >= (int) $request->pos_strontium_good_hours) {
                    return redirect()->back()
                        ->with('error', 'POS strontium warning threshold must be less than good target');
                }
            }
            if ($request->filled('pos_fuel_critical_days') && $request->filled('pos_fuel_warning_days')) {
                if ((int) $request->pos_fuel_critical_days >= (int) $request->pos_fuel_warning_days) {
                    return redirect()->back()
                        ->with('error', 'POS fuel critical threshold must be less than warning threshold');
                }
            }
            
            // Handle excluded hangars
            $allHangars = [1, 2, 3, 4, 5, 6, 7];
            $trackedHangars = $request->input('excluded_hangars', []);
            $excludedHangars = array_diff($allHangars, $trackedHangars);

            StructureManagerSettings::set(
                'excluded_hangars',
                implode(',', $excludedHangars),
                'string',
                'reserves'
            );

            // SECURITY: explicit allowlist of settings keys that the settings form is
            // allowed to write. Previously, any non-webhook_ key was blind-written into
            // the settings table, which would let an admin inject arbitrary rows.
            //
            // POS threshold keys are configurable (v1.0.11 contract — wormhole POS
            // deployments need different defaults than high-sec). UPWELL threshold
            // keys are NOT in the allowlist anymore — Upwell side uses locked
            // constants in FuelThresholds.
            $allowedKeys = [
                // POS thresholds — configurable per install
                'pos_strontium_critical_hours',
                'pos_strontium_warning_hours',
                'pos_strontium_good_hours',
                'pos_fuel_critical_days',
                'pos_fuel_warning_days',
                'pos_charter_critical_days',
                // POS notification cadence (operator-preference)
                'pos_fuel_notification_interval',
                'pos_strontium_notification_interval',
                'pos_strontium_zero_notify_once',
                'pos_strontium_zero_grace_period',
                'pos_discord_role_mention',
                // Upwell notification cadence (thresholds locked in code)
                'upwell_fuel_notification_interval',
                // ESI polling settings
                'esi_polling_enabled',
                'esi_polling_interval',
                'esi_detection_mode',          // 'auto' | 'seat_native' | 'off'
                'esi_attack_role_mention',
                'notify_structure_attack',
                'notify_structure_lifecycle',
                'notify_structure_fuel_events',
                // Structure Board (v2)
                'command_board_default_window_days',
                'command_board_default_opsec_role_id',
                'command_board_retention_days',
                'command_board_autodismiss_elapsed_hours',
                // Hide structures whose ESI data is older than this many
                // days (corp removed its token). 0 = feature disabled.
                'stale_structure_threshold_days',
                // Economics page integration with Manager Core pricing
                'economics_pricing_mode',      // 'auto' | 'disabled'
                // Pre-timer reminders (T-24h / T-6h / T-1h scheduled Discord pings).
                // Requires Manager Core (handler is subscribed to MC's EventBus).
                // 'pre_timer_reminders_enabled' is the master kill-switch; default true.
                // Per-event-type routing lives in the Notifications panel via the six
                // events.pre_timer_* categories (armor / hull / sov / nodes /
                // hostile / defense), so there is no separate manual-ops opt-in
                // here — operators flip the manual-op category on/off in the
                // Notifications UI.
                'pre_timer_reminders_enabled',
                // Attacker threat intel (opt-in zKillboard enrichment). When
                // enabled, each under-attack alert fires a follow-up async job
                // that queries zKB for the attacker's threat profile and posts
                // a separate "who is shooting you" embed via the
                // events.attacker_threat_intel category. Default off — opt-in
                // because of the external HTTP call.
                'attacker_threat_intel_enabled',
            ];

            // Snapshot the previous detection mode so we can detect a change
            // and surface the worker-restart reminder only when it matters.
            // ESI detection mode triggers the in-memory registry behaviour
            // documented in Help & Documentation > Notifications — DB write
            // happens here, but the queue worker needs to reboot to apply
            // the new gate (and to clear any prior in-memory registration).
            $previousDetectionMode = StructureManagerSettings::get('esi_detection_mode', 'auto');

            foreach ($allowedKeys as $key) {
                if ($request->has($key)) {
                    StructureManagerSettings::set($key, $request->input($key));
                }
            }

            // Boolean toggle settings need explicit "off" handling. HTML
            // forms don't submit unchecked checkboxes at all, so an unchecked
            // toggle would be skipped by the `has()` check above and the
            // setting would never flip from "on" to "off" — and worse, on a
            // first-time off-by-default toggle the row would never be created.
            //
            // For each key in this list, if the form didn't include the field,
            // treat it as an explicit "0". This is the Laravel-conventional
            // pattern (most form libraries handle this server-side rather than
            // with a hidden-input shadow, which has known browser edge cases
            // with duplicate-name fields).
            $booleanToggleKeys = [
                'pre_timer_reminders_enabled',
                'attacker_threat_intel_enabled',
            ];
            foreach ($booleanToggleKeys as $boolKey) {
                if (!$request->has($boolKey)) {
                    StructureManagerSettings::set($boolKey, '0');
                }
            }

            // Clear cache
            StructureManagerSettings::clearCache();

            // Build the success message. Append a restart reminder only when
            // the detection mode actually changed — most settings saves don't
            // need a worker restart and we shouldn't cry wolf on them.
            $newDetectionMode = StructureManagerSettings::get('esi_detection_mode', 'auto');
            $message = 'Settings updated successfully';
            if ($previousDetectionMode !== $newDetectionMode) {
                $message .= sprintf(
                    '. ESI detection mode changed from "%s" to "%s". Take the SeAT stack down and back up (see the restart note on this page for the exact command) so the new mode applies to the queue worker process.',
                    $previousDetectionMode,
                    $newDetectionMode
                );
            }

            return redirect()
                ->route('structure-manager.settings')
                ->with('success', $message);
                
        } catch (\Exception $e) {
            return redirect()
                ->back()
                ->with('error', 'Error updating settings: ' . $e->getMessage());
        }
    }
    
    /**
     * Add a new webhook
     */
    public function addWebhook(Request $request)
    {
        try {
            // Validate
            $request->validate([
                'webhook_url' => 'required|url',
                'corporation_id' => 'nullable|integer',
                'description' => 'nullable|string|max:255',
                'role_mention' => 'nullable|string|max:100',
            ]);
            
            // No artificial cap — admins decide how many webhooks they need.
            // Each webhook is a cheap DB row; the cost is in the delivery side
            // (Discord/Slack rate limits), which is addressed by category binding
            // on the Notifications page so only relevant categories hit each webhook.

            // Validate webhook URL format
            if (!WebhookConfiguration::isValidWebhookUrl($request->webhook_url)) {
                return redirect()
                    ->back()
                    ->with('error', 'Invalid webhook URL. Must be a Discord or Slack webhook.');
            }
            
            // Create webhook
            $webhook = WebhookConfiguration::create([
                'webhook_url' => $request->webhook_url,
                'corporation_id' => $request->corporation_id === '' ? null : $request->corporation_id,
                'enabled' => $request->has('enabled'),
                'description' => $request->description,
                'role_mention' => $request->role_mention,
            ]);
            
            return redirect()
                ->route('structure-manager.settings')
                ->with('success', 'Webhook added successfully');
                
        } catch (\Exception $e) {
            return redirect()
                ->back()
                ->with('error', 'Error adding webhook: ' . $e->getMessage());
        }
    }
    
    /**
     * Update a webhook
     */
    public function updateWebhook(Request $request, $id)
    {
        try {
            $webhook = WebhookConfiguration::findOrFail($id);
            
            // Validate
            $request->validate([
                'webhook_url' => 'required|url',
                'corporation_id' => 'nullable|integer',
                'description' => 'nullable|string|max:255',
                'role_mention' => 'nullable|string|max:100',
            ]);
            
            // Validate webhook URL format
            if (!WebhookConfiguration::isValidWebhookUrl($request->webhook_url)) {
                return redirect()
                    ->back()
                    ->with('error', 'Invalid webhook URL. Must be a Discord or Slack webhook.');
            }
            
            // Update webhook
            $webhook->update([
                'webhook_url' => $request->webhook_url,
                'corporation_id' => $request->corporation_id === '' ? null : $request->corporation_id,
                'enabled' => $request->has('enabled'),
                'description' => $request->description,
                'role_mention' => $request->role_mention,
            ]);
            
            return redirect()
                ->route('structure-manager.settings')
                ->with('success', 'Webhook updated successfully');
                
        } catch (\Exception $e) {
            return redirect()
                ->back()
                ->with('error', 'Error updating webhook: ' . $e->getMessage());
        }
    }
    
    /**
     * Get a webhook (for editing)
     */
    public function getWebhook($id)
    {
        try {
            $webhook = WebhookConfiguration::findOrFail($id);
            return response()->json($webhook);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Webhook not found'
            ], 404);
        }
    }
    
    /**
     * Delete a webhook
     */
    public function deleteWebhook($id)
    {
        try {
            $webhook = WebhookConfiguration::findOrFail($id);
            $webhook->delete();
            
            return redirect()
                ->route('structure-manager.settings')
                ->with('success', 'Webhook deleted successfully');
                
        } catch (\Exception $e) {
            return redirect()
                ->back()
                ->with('error', 'Error deleting webhook: ' . $e->getMessage());
        }
    }
    
    /**
     * Test webhook
     */
    public function testWebhook(Request $request, $id = null)
    {
        try {
            // If ID provided, test specific webhook, otherwise test first enabled one
            if ($id) {
                $webhook = WebhookConfiguration::findOrFail($id);
                $webhookUrl = $webhook->webhook_url;
                $corpFilter = $webhook->corporation_id ? 
                    "Corporation Filter: {$webhook->getCorporationLabel()}" : 
                    "Corporation Filter: All Corporations";
            } else {
                // Legacy support - test first enabled webhook
                $webhook = WebhookConfiguration::where('enabled', true)->first();
                
                if (!$webhook) {
                    return response()->json([
                        'success' => false,
                        'message' => 'No enabled webhooks configured'
                    ]);
                }
                
                $webhookUrl = $webhook->webhook_url;
                $corpFilter = $webhook->corporation_id ? 
                    "Corporation Filter: {$webhook->getCorporationLabel()}" : 
                    "Corporation Filter: All Corporations";
            }
            
            // Send test message
            $message = [
                'content' => '**Structure Manager - Test Notification**',
                'embeds' => [[
                    'title' => '🎯 POS Fuel Alert Test',
                    'description' => 'This is a test notification from Structure Manager.',
                    'color' => 3447003, // Blue
                    'fields' => [
                        [
                            'name' => 'Status',
                            'value' => '✅ Webhook is working correctly!',
                            'inline' => false
                        ],
                        [
                            'name' => 'Configuration',
                            'value' => $corpFilter,
                            'inline' => false
                        ]
                    ],
                    'timestamp' => date('c'),
                    'footer' => [
                        'text' => 'SeAT Structure Manager',
                    ]
                ]]
            ];
            
            // SECURITY: re-validate the stored URL before sending, in case a DB row
            // was tampered with after creation.
            if (!WebhookConfiguration::isValidWebhookUrl($webhookUrl)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Stored webhook URL is invalid (not a Discord/Slack https URL). Please edit and re-save.'
                ]);
            }

            $ch = curl_init($webhookUrl);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            // SECURITY: verify TLS certificates and hostnames against the system CA bundle.
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            // SECURITY: bound the connect/read phases so a hung endpoint cannot stall admin.
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            // SECURITY: disable redirects so an attacker cannot bounce the request to an
            // arbitrary target after the host check passes.
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode >= 200 && $httpCode < 300) {
                return response()->json([
                    'success' => true,
                    'message' => 'Test notification sent successfully!'
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to send test notification. HTTP Code: ' . $httpCode
                ]);
            }
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ]);
        }
    }
    
    /**
     * Reset settings to defaults
     */
    /**
     * Manually re-register Structure Manager's pricing preference with
     * Manager Core. Triggered from SM Settings > Economics > "Re-register
     * now" button.
     *
     * Why this exists: the boot-time registration call can silently fail
     * if SM's service provider boots before MC's. This endpoint runs in
     * the user-request lifecycle (well after every provider has booted),
     * so it's guaranteed to land. Also useful as a "refresh the row in
     * case anything got corrupted" recovery action.
     *
     * Idempotent. Re-clicking does nothing harmful — the underlying
     * registerDefault() helper updates the existing row only when the
     * admin hasn't overridden via MC's pricing-preferences page.
     */
    public function reRegisterPricing(Request $request)
    {
        // The page's section-switcher JS reads window.location.hash on load,
        // so appending #economics lands the user back on the right tab.
        $back = route('structure-manager.settings') . '#economics';

        if (!ManagerCoreIntegration::isPricingAvailable()) {
            return redirect($back)
                ->with('error', 'Manager Core pricing infrastructure is not installed. Install Manager Core to enable the Economics page.');
        }

        // Refuse to register when operator has explicitly opted out.
        // Otherwise this button could undo their disabled choice.
        if (ManagerCoreIntegration::economicsPricingMode() === ManagerCoreIntegration::ECONOMICS_MODE_DISABLED) {
            return redirect($back)
                ->with('error', 'Economics integration is currently set to Disabled. Switch the mode to Auto and save before re-registering.');
        }

        try {
            // Two operations, in this order:
            //   1. Register/refresh the preference row (market + price_type)
            //   2. Subscribe the fuel typeIDs so MC fetches their prices
            //
            // Type subscription depends on the preference being current
            // (so we subscribe against whichever market the admin picked).
            ManagerCoreIntegration::registerPricingPreference();
            // Admin clicked Re-register / saved Economics settings — explicit
            // user action, so request an immediate price refresh. (Boot
            // path passes false to avoid per-request dispatch storms.)
            ManagerCoreIntegration::subscribePricingTypes(/* immediateRefresh */ true);

            // Read back the row so the success message reflects what's
            // actually in the DB (rather than just claiming success
            // without verifying the write landed).
            $pref = \ManagerCore\Models\PricingPreference::forPlugin('structure-manager');
            if ($pref === null) {
                return redirect($back)
                    ->with('error', 'Re-registration ran but no row appeared in MC. Check laravel.log for details.');
            }

            $typeCount = count(ManagerCoreIntegration::REQUIRED_PRICING_TYPE_IDS);
            $msg = sprintf(
                'Pricing preference re-registered: %s on %s%s. Subscribed %d fuel typeIDs (immediate refresh dispatched). Prices land in MC within a minute or two.',
                strtoupper($pref->price_type),
                strtoupper($pref->market),
                $pref->admin_overridden ? ' (admin override preserved)' : '',
                $typeCount
            );

            return redirect($back)->with('success', $msg);

        } catch (\Throwable $e) {
            Log::error('[Structure Manager] Manual pricing re-registration failed: ' . $e->getMessage());
            return redirect($back)
                ->with('error', 'Re-registration failed: ' . $e->getMessage());
        }
    }

    public function reset(Request $request)
    {
        try {
            // Reset POS threshold settings to FuelThresholds defaults.
            // POS thresholds remain configurable per install (matches v1.0.11
            // contract). Upwell threshold settings are NOT reset — those are
            // locked constants and any old rows are ignored at read time.
            $T = \StructureManager\Helpers\FuelThresholds::class;
            StructureManagerSettings::set('pos_strontium_critical_hours', $T::POS_STRONTIUM_CRITICAL_HOURS_DEFAULT);
            StructureManagerSettings::set('pos_strontium_warning_hours', $T::POS_STRONTIUM_WARNING_HOURS_DEFAULT);
            StructureManagerSettings::set('pos_strontium_good_hours', $T::POS_STRONTIUM_GOOD_HOURS_DEFAULT);
            StructureManagerSettings::set('pos_fuel_critical_days', $T::POS_FUEL_CRITICAL_DAYS_DEFAULT);
            StructureManagerSettings::set('pos_fuel_warning_days', $T::POS_FUEL_WARNING_DAYS_DEFAULT);
            StructureManagerSettings::set('pos_charter_critical_days', $T::POS_CHARTER_CRITICAL_DAYS_DEFAULT);

            // Reset notification cadence
            StructureManagerSettings::set('pos_fuel_notification_interval', 0);
            StructureManagerSettings::set('pos_strontium_notification_interval', 0);

            // Reset role mention
            StructureManagerSettings::set('pos_discord_role_mention', null);

            // Reset strontium zero settings
            StructureManagerSettings::set('pos_strontium_zero_notify_once', 1);
            StructureManagerSettings::set('pos_strontium_zero_grace_period', 2);

            // Reset reserves tracking settings
            StructureManagerSettings::set('excluded_hangars', '', 'string', 'reserves');

            // Reset Upwell notification cadence (thresholds locked, not reset)
            StructureManagerSettings::set('upwell_fuel_notification_interval', 0);

            // Reset ESI polling settings
            StructureManagerSettings::set('esi_polling_enabled', 1);
            StructureManagerSettings::set('esi_polling_interval', 2);
            StructureManagerSettings::set('esi_attack_role_mention', null);
            StructureManagerSettings::set('notify_structure_attack', 1);
            StructureManagerSettings::set('notify_structure_lifecycle', 1);
            StructureManagerSettings::set('notify_structure_fuel_events', 1);

            StructureManagerSettings::clearCache();
            
            return redirect()
                ->route('structure-manager.settings')
                ->with('success', 'Settings reset to defaults (webhooks unchanged)');
                
        } catch (\Exception $e) {
            return redirect()
                ->back()
                ->with('error', 'Error resetting settings: ' . $e->getMessage());
        }
    }

    // ESI Key Holder management moved to Manager Core v1.x.
    // See route('manager-core.esi-key-pool.index').
}
