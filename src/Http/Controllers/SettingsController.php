<?php

namespace StructureManager\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use StructureManager\Models\StructureManagerSettings;
use StructureManager\Models\WebhookConfiguration;

/**
 * Controller for plugin settings management
 * 
 * UPDATED: Added support for multiple webhooks with corporation filtering
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
        
        // Get all webhooks
        $webhooks = WebhookConfiguration::all();
        
        // Get available corporations for dropdown
        $corporations = $this->getAvailableCorporations();
        
        return view('structure-manager::settings.index', compact(
            'notificationSettings',
            'thresholdSettings',
            'generalSettings',
            'reservesSettings',
            'webhooks',
            'corporations'
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
            // Validate POS thresholds
            $request->validate([
                'pos_strontium_critical_hours' => 'required|integer|min:1|max:72',
                'pos_strontium_warning_hours' => 'required|integer|min:1|max:72',
                'pos_strontium_good_hours' => 'required|integer|min:1|max:72',
                'pos_fuel_critical_days' => 'required|integer|min:1|max:90',
                'pos_fuel_warning_days' => 'required|integer|min:1|max:90',
                'pos_charter_critical_days' => 'required|integer|min:1|max:90',
                'pos_fuel_notification_interval' => 'required|integer|min:0|max:24',
                'pos_strontium_notification_interval' => 'required|integer|min:0|max:12',
                // Upwell thresholds (nullable: only present when Upwell tab is submitted)
                'upwell_fuel_critical_days' => 'nullable|integer|min:1|max:90',
                'upwell_fuel_warning_days' => 'nullable|integer|min:1|max:90',
                'upwell_fuel_notification_interval' => 'nullable|integer|min:0|max:24',
            ]);

            // Validate POS threshold relationships
            if ($request->pos_strontium_critical_hours >= $request->pos_strontium_warning_hours) {
                return redirect()
                    ->back()
                    ->with('error', 'Critical threshold must be less than warning threshold for strontium');
            }

            if ($request->pos_strontium_warning_hours >= $request->pos_strontium_good_hours) {
                return redirect()
                    ->back()
                    ->with('error', 'Warning threshold must be less than good threshold for strontium');
            }

            if ($request->pos_fuel_critical_days >= $request->pos_fuel_warning_days) {
                return redirect()
                    ->back()
                    ->with('error', 'Critical threshold must be less than warning threshold for fuel');
            }

            // Validate Upwell threshold relationships
            if ($request->has('upwell_fuel_critical_days') && $request->has('upwell_fuel_warning_days')) {
                if ((int) $request->upwell_fuel_critical_days >= (int) $request->upwell_fuel_warning_days) {
                    return redirect()
                        ->back()
                        ->with('error', 'Upwell fuel critical threshold must be less than warning threshold');
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
            $allowedKeys = [
                // POS settings
                'pos_strontium_critical_hours',
                'pos_strontium_warning_hours',
                'pos_strontium_good_hours',
                'pos_fuel_critical_days',
                'pos_fuel_warning_days',
                'pos_charter_critical_days',
                'pos_fuel_notification_interval',
                'pos_strontium_notification_interval',
                'pos_strontium_zero_notify_once',
                'pos_strontium_zero_grace_period',
                'pos_discord_role_mention',
                // Upwell settings
                'upwell_fuel_critical_days',
                'upwell_fuel_warning_days',
                'upwell_fuel_notification_interval',
                // ESI polling settings
                'esi_polling_enabled',
                'esi_polling_interval',
                'esi_attack_role_mention',
                'notify_structure_attack',
                'notify_structure_lifecycle',
                'notify_structure_fuel_events',
                // Structure Board (v2)
                'command_board_default_window_days',
                'command_board_default_opsec_role_id',
                'command_board_retention_days',
                'command_board_autodismiss_elapsed_hours',
            ];

            foreach ($allowedKeys as $key) {
                if ($request->has($key)) {
                    StructureManagerSettings::set($key, $request->input($key));
                }
            }

            // Clear cache
            StructureManagerSettings::clearCache();
            
            return redirect()
                ->route('structure-manager.settings')
                ->with('success', 'Settings updated successfully');
                
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
            
            // Check webhook limit
            $webhookCount = WebhookConfiguration::count();
            if ($webhookCount >= 10) {
                return redirect()
                    ->back()
                    ->with('error', 'Maximum of 10 webhooks allowed');
            }
            
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
    public function reset(Request $request)
    {
        try {
            // Reset threshold settings
            StructureManagerSettings::set('pos_strontium_critical_hours', 6);
            StructureManagerSettings::set('pos_strontium_warning_hours', 12);
            StructureManagerSettings::set('pos_strontium_good_hours', 24);
            StructureManagerSettings::set('pos_fuel_critical_days', 7);
            StructureManagerSettings::set('pos_fuel_warning_days', 14);
            StructureManagerSettings::set('pos_charter_critical_days', 7);
            
            // Reset notification intervals
            StructureManagerSettings::set('pos_fuel_notification_interval', 0);
            StructureManagerSettings::set('pos_strontium_notification_interval', 0);
            
            // Reset role mention
            StructureManagerSettings::set('pos_discord_role_mention', null);
            
            // Reset strontium zero settings
            StructureManagerSettings::set('pos_strontium_zero_notify_once', 1);
            StructureManagerSettings::set('pos_strontium_zero_grace_period', 2);
            
            // Reset reserves tracking settings
            StructureManagerSettings::set('excluded_hangars', '', 'string', 'reserves');

            // Reset Upwell thresholds
            StructureManagerSettings::set('upwell_fuel_critical_days', 7);
            StructureManagerSettings::set('upwell_fuel_warning_days', 14);
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
