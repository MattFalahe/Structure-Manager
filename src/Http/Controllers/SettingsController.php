<?php

namespace StructureManager\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use StructureManager\Models\StructureManagerSettings;

/**
 * Controller for plugin settings management
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
        
        return view('structure-manager::settings.index', compact(
            'notificationSettings',
            'thresholdSettings',
            'generalSettings',
            'reservesSettings'
        ));
    }
    
    /**
     * Update settings
     */
    public function update(Request $request)
    {
        try {
            // Validate webhook URL if provided
            if ($request->has('pos_webhook_url') && $request->pos_webhook_url) {
                $request->validate([
                    'pos_webhook_url' => 'url',
                ]);
            }
            
            // Validate thresholds
            $request->validate([
                'pos_strontium_critical_hours' => 'required|integer|min:1|max:72',
                'pos_strontium_warning_hours' => 'required|integer|min:1|max:72',
                'pos_strontium_good_hours' => 'required|integer|min:1|max:72',
                'pos_fuel_critical_days' => 'required|integer|min:1|max:90',
                'pos_fuel_warning_days' => 'required|integer|min:1|max:90',
                'pos_charter_critical_days' => 'required|integer|min:1|max:90',
                'pos_fuel_notification_interval' => 'required|integer|min:0|max:24',
                'pos_strontium_notification_interval' => 'required|integer|min:0|max:12',
            ]);
            
            // Validate threshold relationships
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
            
            // Handle checkbox explicitly (unchecked checkboxes don't send values)
            $webhookEnabled = $request->has('pos_webhook_enabled') ? 1 : 0;
            StructureManagerSettings::set('pos_webhook_enabled', $webhookEnabled);
            
            // Handle excluded hangars
            // The form sends checked hangars as an array, we need to convert to excluded hangars
            // Hangars 1-7 exist, checked ones are TRACKED, unchecked are EXCLUDED
            $allHangars = [1, 2, 3, 4, 5, 6, 7];
            $trackedHangars = $request->input('excluded_hangars', []);
            
            // Calculate excluded hangars (all hangars minus tracked ones)
            $excludedHangars = array_diff($allHangars, $trackedHangars);
            
            // Save as comma-separated string for easier querying
            StructureManagerSettings::set(
                'excluded_hangars', 
                implode(',', $excludedHangars),
                'string',
                'reserves'
            );
            
            // Update all other settings
            foreach ($request->except(['_token', 'pos_webhook_enabled', 'excluded_hangars']) as $key => $value) {
                StructureManagerSettings::set($key, $value);
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
     * Test webhook
     */
    public function testWebhook(Request $request)
    {
        try {
            $webhookUrl = StructureManagerSettings::get('pos_webhook_url');
            
            if (!$webhookUrl) {
                return response()->json([
                    'success' => false,
                    'message' => 'Webhook URL not configured'
                ]);
            }
            
            // Send test message
            $message = [
                'content' => '**Structure Manager - Test Notification**',
                'embeds' => [[
                    'title' => 'POS Fuel Alert Test',
                    'description' => 'This is a test notification from Structure Manager.',
                    'color' => 3447003, // Blue
                    'fields' => [
                        [
                            'name' => 'Status',
                            'value' => 'Webhook is working correctly!',
                            'inline' => false
                        ]
                    ],
                    'timestamp' => date('c'),
                ]]
            ];
            
            $ch = curl_init($webhookUrl);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            
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
            // Reset notification settings
            StructureManagerSettings::set('pos_webhook_enabled', 0);
            StructureManagerSettings::set('pos_webhook_url', null);
            StructureManagerSettings::set('pos_discord_role_mention', null);
            
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
            
            // Reset reserves tracking settings
            StructureManagerSettings::set('excluded_hangars', '', 'string', 'reserves');
            
            StructureManagerSettings::clearCache();
            
            return redirect()
                ->route('structure-manager.settings')
                ->with('success', 'Settings reset to defaults');
                
        } catch (\Exception $e) {
            return redirect()
                ->back()
                ->with('error', 'Error resetting settings: ' . $e->getMessage());
        }
    }
}
