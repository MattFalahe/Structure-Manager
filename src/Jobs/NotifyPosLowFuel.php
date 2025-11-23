<?php

namespace StructureManager\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use StructureManager\Models\StarbaseFuelHistory;
use StructureManager\Models\StructureManagerSettings;
use Carbon\Carbon;

/**
 * Send Discord/Slack notifications for POSes with low fuel
 * 
 * NOTIFICATION BEHAVIOR:
 * - Sends notifications ONLY on status changes (good → warning → critical)
 * - Optionally sends interval reminders during critical stage (configurable)
 * - Sends final alert 1 hour before POS goes offline
 * - Properly handles Discord role mentions with allowed_mentions
 * 
 * STATUS LEVELS:
 * - good: Above warning threshold (no alerts)
 * - warning: Between warning and critical thresholds
 * - critical: Below critical threshold
 * 
 * TRIGGERS:
 * 1. Status change (always)
 * 2. Final alert at 1 hour (always, once)
 * 3. Critical interval (optional, configurable)
 */
class NotifyPosLowFuel implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Execute the job.
     */
    public function handle()
    {
        try {
            \Log::channel('stack')->info('NotifyPosLowFuel: Job started');
            error_log('NotifyPosLowFuel: Job started - ERROR_LOG');
            
            // Check if notifications are enabled
            $webhookEnabled = StructureManagerSettings::get('pos_webhook_enabled');
            \Log::channel('stack')->info('NotifyPosLowFuel: Webhook enabled setting = ' . var_export($webhookEnabled, true));
            
            if (!$webhookEnabled) {
                \Log::channel('stack')->debug('NotifyPosLowFuel: Notifications disabled');
                error_log('NotifyPosLowFuel: Notifications disabled - ERROR_LOG');
                return;
            }

            $webhookUrl = StructureManagerSettings::get('pos_webhook_url');
            \Log::channel('stack')->info('NotifyPosLowFuel: Webhook URL = ' . substr($webhookUrl ?? 'NULL', 0, 30) . '...');
            
            if (empty($webhookUrl)) {
                \Log::channel('stack')->warning('NotifyPosLowFuel: No webhook URL configured');
                error_log('NotifyPosLowFuel: No webhook URL configured - ERROR_LOG');
                return;
            }
            
            \Log::channel('stack')->info('NotifyPosLowFuel: Webhook enabled and URL configured');
            error_log('NotifyPosLowFuel: Webhook enabled and URL configured - ERROR_LOG');
        } catch (\Exception $e) {
            \Log::channel('stack')->error('NotifyPosLowFuel: Exception in initial setup - ' . $e->getMessage());
            error_log('NotifyPosLowFuel: Exception in initial setup - ' . $e->getMessage());
            throw $e;
        }

        // Get thresholds from settings
        $fuelCriticalDays = StructureManagerSettings::get('pos_fuel_critical_days', 7);
        $fuelWarningDays = StructureManagerSettings::get('pos_fuel_warning_days', 14);
        $strontiumCriticalHours = StructureManagerSettings::get('pos_strontium_critical_hours', 6);
        $strontiumWarningHours = StructureManagerSettings::get('pos_strontium_warning_hours', 12);
        $charterCriticalDays = StructureManagerSettings::get('pos_charter_critical_days', 7);
        $discordRoleMention = StructureManagerSettings::get('pos_discord_role_mention', '');
        
        // Get critical stage alert intervals (in hours, 0 = disabled/only on status change)
        $fuelCriticalInterval = StructureManagerSettings::get('pos_fuel_notification_interval', 0);
        $strontiumCriticalInterval = StructureManagerSettings::get('pos_strontium_notification_interval', 0);

        \Log::channel('stack')->info('NotifyPosLowFuel: Settings loaded', [
            'fuel_critical_days' => $fuelCriticalDays,
            'fuel_warning_days' => $fuelWarningDays,
            'fuel_interval' => $fuelCriticalInterval,
            'strontium_critical_hours' => $strontiumCriticalHours,
            'strontium_warning_hours' => $strontiumWarningHours,
            'strontium_interval' => $strontiumCriticalInterval,
        ]);

        // FIXED: Get latest history for all ONLINE (state = 4) and REINFORCED (state = 3) POSes
        // Query the state from starbase_fuel_history which stores it as integer, not from corporation_starbases which stores it as string
        $allPoses = StarbaseFuelHistory::select('starbase_fuel_history.starbase_id')
            ->whereIn('id', function($query) {
                $query->select(\DB::raw('MAX(id)'))
                    ->from('starbase_fuel_history')
                    ->groupBy('starbase_id');
            })
            ->whereIn('starbase_fuel_history.state', [3, 4]) // Only ONLINE and REINFORCED POSes (from history table, which has integers)
            ->distinct()
            ->get();

        \Log::channel('stack')->info('NotifyPosLowFuel: Found ' . $allPoses->count() . ' ONLINE/REINFORCED POSes to check');
        error_log('NotifyPosLowFuel: Found ' . $allPoses->count() . ' ONLINE/REINFORCED POSes to check - ERROR_LOG');

        $notificationsSent = 0;

        foreach ($allPoses as $pos) {
            // Get the latest history record for this POS
            $latest = StarbaseFuelHistory::where('starbase_id', $pos->starbase_id)
                ->orderBy('created_at', 'desc')
                ->first();

            if (!$latest) {
                continue;
            }
            
            // Double-check POS is still online or reinforced (extra safety check using history table state)
            if (!in_array($latest->state, [3, 4])) {
                \Log::channel('stack')->debug("NotifyPosLowFuel: Skipping POS {$latest->starbase_id} - not online/reinforced (state: {$latest->state})");
                continue;
            }

            \Log::channel('stack')->debug('NotifyPosLowFuel: Checking POS ' . $latest->starbase_id, [
                'state' => $latest->state,
                'fuel_days_remaining' => $latest->fuel_days_remaining,
                'charter_days_remaining' => $latest->charter_days_remaining,
                'last_fuel_status' => $latest->last_fuel_notification_status,
                'last_fuel_at' => $latest->last_fuel_notification_at,
            ]);

            // Process fuel/charter notifications
            if ($this->shouldSendFuelNotification($latest, $fuelCriticalDays, $fuelWarningDays, $fuelCriticalInterval, $charterCriticalDays)) {
                \Log::channel('stack')->info('NotifyPosLowFuel: SENDING fuel notification for POS ' . $latest->starbase_id);
                $this->sendFuelNotification($latest, $webhookUrl, $fuelCriticalDays, $fuelWarningDays, $charterCriticalDays, $discordRoleMention);
                $notificationsSent++;
            }

            // Process strontium notifications (separate tracking)
            if ($this->shouldSendStrontiumNotification($latest, $strontiumCriticalHours, $strontiumWarningHours, $strontiumCriticalInterval)) {
                \Log::channel('stack')->info('NotifyPosLowFuel: SENDING strontium notification for POS ' . $latest->starbase_id);
                $this->sendStrontiumNotification($latest, $webhookUrl, $strontiumCriticalHours, $strontiumWarningHours, $discordRoleMention);
                $notificationsSent++;
            }
        }

        \Log::channel('stack')->info("NotifyPosLowFuel: Job completed, sent {$notificationsSent} notifications");
        error_log("NotifyPosLowFuel: Job completed, sent {$notificationsSent} notifications - ERROR_LOG");
    }

    /**
     * Determine if fuel/charter notification should be sent
     * 
     * @param StarbaseFuelHistory $history Latest fuel history record
     * @param int $criticalDays Critical threshold in days
     * @param int $warningDays Warning threshold in days
     * @param int $criticalInterval Hours between reminders in critical stage (0 = disabled)
     * @param int $charterCriticalDays Charter critical threshold
     * @return bool
     */
    private function shouldSendFuelNotification($history, $criticalDays, $warningDays, $criticalInterval, $charterCriticalDays)
    {
        // Calculate current status based on remaining fuel/charters
        $currentStatus = $this->determineFuelStatus($history, $criticalDays, $warningDays, $charterCriticalDays);
        
        \Log::channel('stack')->debug("NotifyPosLowFuel: shouldSendFuelNotification for POS {$history->starbase_id}", [
            'current_status' => $currentStatus,
            'last_status' => $history->last_fuel_notification_status,
            'fuel_days' => $history->fuel_days_remaining,
            'charter_days' => $history->charter_days_remaining,
            'critical_threshold' => $criticalDays,
            'warning_threshold' => $warningDays,
        ]);
        
        // No alerts for good status
        if ($currentStatus === 'good') {
            return false;
        }
        
        $lastStatus = $history->last_fuel_notification_status;
        $lastNotificationAt = $history->last_fuel_notification_at;
        $finalAlertSent = $history->fuel_final_alert_sent ?? false;
        
        // Check for final alert (1 hour remaining)
        $actualDays = $history->actual_days_remaining ?? $history->fuel_days_remaining;
        $hoursRemaining = $actualDays * 24;
        
        if ($hoursRemaining <= 1 && !$finalAlertSent) {
            \Log::channel('stack')->info("NotifyPosLowFuel: FINAL ALERT triggered for POS {$history->starbase_id} (< 1 hour remaining)");
            return true;
        }
        
        // Trigger #1: Status change (good → warning, warning → critical, or good → critical)
        if ($lastStatus !== $currentStatus) {
            \Log::channel('stack')->info("NotifyPosLowFuel: Status change detected for POS {$history->starbase_id}: {$lastStatus} → {$currentStatus}");
            return true;
        }
        
        // Trigger #2: Critical interval reminders (only in critical stage)
        if ($currentStatus === 'critical' && $criticalInterval > 0 && $lastNotificationAt) {
            $hoursSinceLastNotification = Carbon::now()->diffInHours($lastNotificationAt);
            
            if ($hoursSinceLastNotification >= $criticalInterval) {
                \Log::channel('stack')->info("NotifyPosLowFuel: Critical interval reached for POS {$history->starbase_id} ({$hoursSinceLastNotification}h since last notification, interval: {$criticalInterval}h)");
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Determine fuel/charter status level
     * 
     * @param StarbaseFuelHistory $history
     * @param int $criticalDays
     * @param int $warningDays
     * @param int $charterCriticalDays
     * @return string 'good', 'warning', or 'critical'
     */
    private function determineFuelStatus($history, $criticalDays, $warningDays, $charterCriticalDays)
    {
        // Use actual_days_remaining (considers limiting factor) or fall back to fuel_days_remaining
        $daysRemaining = $history->actual_days_remaining ?? $history->fuel_days_remaining;
        
        // Check if charters are the limiting factor (high-sec only)
        if ($history->requires_charters && $history->charter_days_remaining !== null) {
            $daysRemaining = min($daysRemaining, $history->charter_days_remaining);
        }
        
        if ($daysRemaining < $criticalDays) {
            return 'critical';
        } elseif ($daysRemaining < $warningDays) {
            return 'warning';
        }
        
        return 'good';
    }
    
    /**
     * Determine if strontium notification should be sent
     * 
     * @param StarbaseFuelHistory $history Latest fuel history record
     * @param int $criticalHours Critical threshold in hours
     * @param int $warningHours Warning threshold in hours
     * @param int $criticalInterval Hours between reminders in critical stage (0 = disabled)
     * @return bool
     */
    private function shouldSendStrontiumNotification($history, $criticalHours, $warningHours, $criticalInterval)
    {
        // Calculate current status
        $currentStatus = $this->determineStrontiumStatus($history, $criticalHours, $warningHours);
        
        \Log::channel('stack')->debug("NotifyPosLowFuel: shouldSendStrontiumNotification for POS {$history->starbase_id}", [
            'current_status' => $currentStatus,
            'last_status' => $history->last_strontium_notification_status,
            'strontium_hours' => $history->strontium_hours_available,
            'critical_threshold' => $criticalHours,
            'warning_threshold' => $warningHours,
        ]);
        
        // No alerts for good status
        if ($currentStatus === 'good') {
            return false;
        }
        
        $lastStatus = $history->last_strontium_notification_status;
        $lastNotificationAt = $history->last_strontium_notification_at;
        $finalAlertSent = $history->strontium_final_alert_sent ?? false;
        
        // Check for final alert (30 minutes remaining)
        $hoursRemaining = $history->strontium_hours_available ?? 0;
        
        if ($hoursRemaining <= 0.5 && !$finalAlertSent) {
            \Log::channel('stack')->info("NotifyPosLowFuel: FINAL STRONTIUM ALERT triggered for POS {$history->starbase_id} (< 30 min remaining)");
            return true;
        }
        
        // Trigger #1: Status change
        if ($lastStatus !== $currentStatus) {
            \Log::channel('stack')->info("NotifyPosLowFuel: Strontium status change detected for POS {$history->starbase_id}: {$lastStatus} → {$currentStatus}");
            return true;
        }
        
        // Trigger #2: Critical interval reminders (only in critical stage)
        if ($currentStatus === 'critical' && $criticalInterval > 0 && $lastNotificationAt) {
            $hoursSinceLastNotification = Carbon::now()->diffInHours($lastNotificationAt);
            
            if ($hoursSinceLastNotification >= $criticalInterval) {
                \Log::channel('stack')->info("NotifyPosLowFuel: Critical strontium interval reached for POS {$history->starbase_id} ({$hoursSinceLastNotification}h since last notification, interval: {$criticalInterval}h)");
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Determine strontium status level
     * 
     * @param StarbaseFuelHistory $history
     * @param int $criticalHours
     * @param int $warningHours
     * @return string 'good', 'warning', or 'critical'
     */
    private function determineStrontiumStatus($history, $criticalHours, $warningHours)
    {
        $hoursRemaining = $history->strontium_hours_available ?? 0;
        
        if ($hoursRemaining < $criticalHours) {
            return 'critical';
        } elseif ($hoursRemaining < $warningHours) {
            return 'warning';
        }
        
        return 'good';
    }

    /**
     * Send fuel/charter notification
     * 
     * @param StarbaseFuelHistory $history Latest fuel history
     * @param string $webhookUrl Discord webhook URL
     * @param int $criticalDays Critical threshold
     * @param int $warningDays Warning threshold
     * @param int $charterCriticalDays Charter critical threshold
     * @param string $roleMention Discord role mention
     */
    private function sendFuelNotification($history, $webhookUrl, $criticalDays, $warningDays, $charterCriticalDays, $roleMention)
    {
        $status = $this->determineFuelStatus($history, $criticalDays, $warningDays, $charterCriticalDays);
        
        // Check for final alert (< 1 hour)
        $actualDays = $history->actual_days_remaining ?? $history->fuel_days_remaining;
        $hoursRemaining = $actualDays * 24;
        $isFinalAlert = ($hoursRemaining <= 1);

        $alerts = [];

        // Fuel blocks alert
        $fuelThreshold = ($status === 'critical') ? $criticalDays : $warningDays;
        $alerts[] = [
            'resource' => 'Fuel Blocks',
            'type' => $status,
            'current' => number_format($history->fuel_blocks_quantity ?? 0) . ' blocks',
            'remaining' => $this->formatDaysHours($history->fuel_days_remaining ?? 0),
            'threshold' => $fuelThreshold . ' days',
            'is_limiting' => ($history->limiting_factor === 'fuel'),
        ];

        // Charters alert (if applicable)
        if ($history->requires_charters && $history->charter_days_remaining !== null) {
            if ($history->charter_days_remaining < $charterCriticalDays) {
                $alerts[] = [
                    'resource' => 'Starbase Charters',
                    'type' => 'critical',
                    'current' => number_format($history->charter_quantity ?? 0) . ' charters',
                    'remaining' => $this->formatDaysHours($history->charter_days_remaining),
                    'threshold' => $charterCriticalDays . ' days',
                    'is_limiting' => ($history->limiting_factor === 'charters'),
                ];
            }
        }

        $posData = [
            'starbase_id' => $history->starbase_id,
            'name' => $history->starbase_name ?? 'Unnamed POS',
            'system' => $history->metadata['system_name'] ?? 'Unknown System',
            'tower_type' => $history->metadata['tower_type'] ?? 'Unknown',
            'space_type' => $history->space_type,
            'alerts' => $alerts,
            'updated_at' => $history->created_at,
            'alert_category' => 'fuel',
        ];

        $title = $isFinalAlert ? 'FINAL ALERT: POS Going Offline!' : 
                ($status === 'critical' ? 'Critical: Fuel Low' : 'Warning: Fuel Low');

        $this->sendDiscordNotification($webhookUrl, [$posData], $status, $roleMention, 'Fuel/Charter', $isFinalAlert, $title);

        // Update tracking in database
        $history->last_fuel_notification_status = $status;
        $history->last_fuel_notification_at = Carbon::now();
        if ($isFinalAlert) {
            $history->fuel_final_alert_sent = true;
        }
        $history->save();
    }

    /**
     * Send strontium notification
     * 
     * @param StarbaseFuelHistory $history Latest fuel history
     * @param string $webhookUrl Discord webhook URL
     * @param int $criticalHours Critical threshold
     * @param int $warningHours Warning threshold
     * @param string $roleMention Discord role mention
     */
    private function sendStrontiumNotification($history, $webhookUrl, $criticalHours, $warningHours, $roleMention)
    {
        $status = $this->determineStrontiumStatus($history, $criticalHours, $warningHours);
        
        // Check for final alert (< 30 minutes)
        $hoursRemaining = $history->strontium_hours_available ?? 0;
        $isFinalAlert = ($hoursRemaining <= 0.5);

        $strontiumThreshold = ($status === 'critical') ? $criticalHours : $warningHours;
        
        $alerts = [
            [
                'resource' => 'Strontium Clathrates',
                'type' => $status,
                'current' => number_format($history->strontium_quantity ?? 0) . ' units',
                'remaining' => round($hoursRemaining, 1) . 'h',
                'threshold' => $strontiumThreshold . ' hours',
                'is_limiting' => false,
            ]
        ];

        $posData = [
            'starbase_id' => $history->starbase_id,
            'name' => $history->starbase_name ?? 'Unnamed POS',
            'system' => $history->metadata['system_name'] ?? 'Unknown System',
            'tower_type' => $history->metadata['tower_type'] ?? 'Unknown',
            'space_type' => $history->space_type,
            'alerts' => $alerts,
            'updated_at' => $history->created_at,
            'alert_category' => 'strontium',
        ];

        $title = $isFinalAlert ? 'FINAL ALERT: Strontium Depleted!' : 
                ($status === 'critical' ? 'Critical: Strontium Low' : 'Warning: Strontium Low');

        $this->sendDiscordNotification($webhookUrl, [$posData], $status, $roleMention, 'Strontium', $isFinalAlert, $title);

        // Update tracking in database
        $history->last_strontium_notification_status = $status;
        $history->last_strontium_notification_at = Carbon::now();
        if ($isFinalAlert) {
            $history->strontium_final_alert_sent = true;
        }
        $history->save();
    }

    /**
     * Send Discord webhook notification with proper mention handling
     * 
     * Implements Discord's allowed_mentions API for proper role/user mentions
     * Only mentions in critical and final alerts
     * 
     * @param string $webhookUrl Discord webhook URL
     * @param array $poses Array of POS data with alerts
     * @param string $severity 'critical' or 'warning'
     * @param string $roleMention Discord role/user mention
     * @param string $category 'Fuel/Charter' or 'Strontium'
     * @param bool $isFinalAlert Is this a final 30-min alert?
     * @param string $titleOverride Optional title override
     */
    private function sendDiscordNotification($webhookUrl, $poses, $severity, $roleMention = '', $category = 'POS', $isFinalAlert = false, $titleOverride = null)
    {
        $color = ($severity == 'critical' || $isFinalAlert) ? 15158332 : 16776960; // Red or Yellow
        $icon = $isFinalAlert ? '🚨' : ($severity == 'critical' ? '🔴' : '⚠️');
        $title = $titleOverride ?? ($severity == 'critical' ? "CRITICAL $category Alert" : "Warning: $category Low");

        $embeds = [];

        foreach ($poses as $pos) {
            $fields = [];

            // POS Info
            $fields[] = [
                'name' => '📍 Location',
                'value' => "{$pos['system']} ({$pos['space_type']})",
                'inline' => true,
            ];

            $fields[] = [
                'name' => '🏗️ Tower Type',
                'value' => $pos['tower_type'],
                'inline' => true,
            ];

            $fields[] = [
                'name' => '⏰ Last Update',
                'value' => $pos['updated_at']->diffForHumans(),
                'inline' => true,
            ];

            // Resource alerts
            foreach ($pos['alerts'] as $alert) {
                $emoji = ($alert['type'] == 'critical' || $isFinalAlert) ? '🔴' : '🟡';
                
                // Add limiting factor badge or final alert badge
                $badge = '';
                if ($isFinalAlert) {
                    $badge = ' **[GOING OFFLINE]**';
                } elseif (isset($alert['is_limiting']) && $alert['is_limiting']) {
                    $badge = ' **[LIMITING FACTOR]**';
                }
                
                $fields[] = [
                    'name' => "{$emoji} {$alert['resource']}{$badge}",
                    'value' => "**Current:** {$alert['current']}\n**Remaining:** {$alert['remaining']}\n**Threshold:** {$alert['threshold']}",
                    'inline' => false,
                ];
            }

            $embeds[] = [
                'title' => "{$icon} {$pos['name']}",
                'color' => $color,
                'fields' => $fields,
                'footer' => [
                    'text' => 'SeAT Structure Manager | POS ID: ' . $pos['starbase_id'],
                ],
                'timestamp' => $pos['updated_at']->toIso8601String(),
            ];
        }

        /**
         * Handle mentions properly (roles + users by ID)
         * Only mention for critical and final alerts
         * 
         * Supported formats:
         * - <@&123456789> → Role mention
         * - <@123456789> or <@!123456789> → User mention
         * - 123456789 → Raw ID (treated as role)
         */
        $content = '';
        $allowedMentions = [
            'parse' => [],
            'users' => [],
            'roles' => [],
        ];

        if (($severity === 'critical' || $isFinalAlert) && !empty($roleMention)) {
            $mention = trim($roleMention);
            
            // Role mention: <@&123456789>
            if (preg_match('/^<@&(\d+)>$/', $mention, $m)) {
                $content = "<@&{$m[1]}> ";
                $allowedMentions['roles'][] = $m[1];
            }
            // User mention: <@123456789> or <@!123456789>
            elseif (preg_match('/^<@!?(\d+)>$/', $mention, $m)) {
                $content = "<@{$m[1]}> ";
                $allowedMentions['users'][] = $m[1];
            }
            // Raw numeric ID (assume role by default)
            elseif (preg_match('/^\d+$/', $mention)) {
                $content = "<@&{$mention}> ";
                $allowedMentions['roles'][] = $mention;
            }
        }

        $content .= "**{$title}**" . (count($poses) > 0 ? " - " . count($poses) . " POS(es) need attention" : "");

        $payload = [
            'content' => $content,
            'embeds' => $embeds,
            'username' => 'SeAT Structure Manager',
            'allowed_mentions' => $allowedMentions,
        ];

        try {
            $response = Http::post($webhookUrl, $payload);

            if ($response->successful()) {
                $alertType = $isFinalAlert ? 'FINAL' : $severity;
                Log::info("NotifyPosLowFuel: Successfully sent {$alertType} {$category} notification for " . count($poses) . " POS(es)");
            } else {
                Log::error("NotifyPosLowFuel: Discord webhook failed - " . $response->status() . ": " . $response->body());
            }
        } catch (\Exception $e) {
            Log::error("NotifyPosLowFuel: Discord webhook exception - " . $e->getMessage());
        }
    }

    /**
     * Format days intelligently:
     * - < 1 day: Show only hours (e.g., "6h", "23h")
     * - >= 1 day: Show days + hours (e.g., "2d 3h", "7d 12h")
     * 
     * @param float $days Days remaining (can be fractional)
     * @return string Formatted string like "6h" or "3d 6h"
     */
    private function formatDaysHours($days)
    {
        $wholeDays = floor($days);
        $hours = floor(($days - $wholeDays) * 24);
        
        // If less than 1 day, show only hours for clarity
        if ($wholeDays == 0) {
            return "{$hours}h";
        }
        
        // Otherwise show days + hours
        return "{$wholeDays}d {$hours}h";
    }
}
