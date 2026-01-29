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
use StructureManager\Models\WebhookConfiguration;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Send Discord/Slack notifications for POSes with low fuel
 * 
 * UPDATED VERSION with:
 * - Multiple webhook support (up to 10 webhooks)
 * - Corporation filtering per webhook
 * - Per-webhook role mentions (different role pings per webhook)
 * - Improved strontium notification logic for 0 strontium cases
 * 
 * NOTIFICATION BEHAVIOR:
 * - Sends notifications ONLY on status changes (good → warning → critical)
 * - Optionally sends interval reminders during critical stage (configurable)
 * - Sends final alert 1 hour before POS goes offline
 * - For 0 strontium: only notifies once + on status changes (prevents spam)
 * - Properly handles Discord role mentions with allowed_mentions
 * - Each webhook can have its own role mention configuration
 * 
 * STATUS LEVELS:
 * - good: Above warning threshold (no alerts)
 * - warning: Between warning and critical thresholds
 * - critical: Below critical threshold
 * 
 * TRIGGERS:
 * 1. Status change (always)
 * 2. Final alert at 1 hour (always, once)
 * 3. Critical interval (optional, configurable, disabled for prolonged 0 strontium)
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
            
            // Get all enabled webhooks
            $webhooks = WebhookConfiguration::getEnabled();
            
            if ($webhooks->isEmpty()) {
                \Log::channel('stack')->debug('NotifyPosLowFuel: No enabled webhooks configured');
                return;
            }
            
            \Log::channel('stack')->info('NotifyPosLowFuel: Found ' . $webhooks->count() . ' enabled webhook(s)');
            
        } catch (\Exception $e) {
            \Log::channel('stack')->error('NotifyPosLowFuel: Exception in initial setup - ' . $e->getMessage());
            throw $e;
        }

        // Get thresholds from settings
        $fuelCriticalDays = StructureManagerSettings::get('pos_fuel_critical_days', 7);
        $fuelWarningDays = StructureManagerSettings::get('pos_fuel_warning_days', 14);
        $strontiumCriticalHours = StructureManagerSettings::get('pos_strontium_critical_hours', 6);
        $strontiumWarningHours = StructureManagerSettings::get('pos_strontium_warning_hours', 12);
        $charterCriticalDays = StructureManagerSettings::get('pos_charter_critical_days', 7);
        
        // Get critical stage alert intervals (in hours, 0 = disabled/only on status change)
        $fuelCriticalInterval = StructureManagerSettings::get('pos_fuel_notification_interval', 0);
        $strontiumCriticalInterval = StructureManagerSettings::get('pos_strontium_notification_interval', 0);
        
        // Get strontium zero notification settings
        $strontiumZeroNotifyOnce = StructureManagerSettings::get('pos_strontium_zero_notify_once', true);
        $strontiumZeroGracePeriod = StructureManagerSettings::get('pos_strontium_zero_grace_period', 2);

        \Log::channel('stack')->info('NotifyPosLowFuel: Settings loaded', [
            'fuel_critical_days' => $fuelCriticalDays,
            'fuel_warning_days' => $fuelWarningDays,
            'fuel_interval' => $fuelCriticalInterval,
            'strontium_critical_hours' => $strontiumCriticalHours,
            'strontium_warning_hours' => $strontiumWarningHours,
            'strontium_interval' => $strontiumCriticalInterval,
            'strontium_zero_notify_once' => $strontiumZeroNotifyOnce,
            'strontium_zero_grace_period' => $strontiumZeroGracePeriod,
        ]);

        // Get latest history for all ONLINE (state = 4) and REINFORCED (state = 3) POSes
        // CRITICAL FIX: Only include POSes that STILL EXIST in corporation_starbases
        // This prevents notifications for unanchored/removed POSes that have stale history records
        $allPoses = StarbaseFuelHistory::select('starbase_fuel_history.starbase_id', 'starbase_fuel_history.corporation_id')
            ->whereIn('id', function($query) {
                $query->select(DB::raw('MAX(id)'))
                    ->from('starbase_fuel_history')
                    ->groupBy('starbase_id');
            })
            ->whereIn('starbase_fuel_history.state', [3, 4]) // Only ONLINE and REINFORCED POSes
            // CRITICAL: Verify POS still exists in current corporation holdings (ESI data)
            ->whereExists(function($query) {
                $query->select(DB::raw(1))
                    ->from('corporation_starbases')
                    ->whereColumn('corporation_starbases.starbase_id', 'starbase_fuel_history.starbase_id');
            })
            ->distinct()
            ->get();

        \Log::channel('stack')->info('NotifyPosLowFuel: Found ' . $allPoses->count() . ' ONLINE/REINFORCED POSes to check (verified in corporation_starbases)');

        $notificationsSent = 0;

        // Group POSes by corporation for efficient webhook filtering
        $posesByCorp = $allPoses->groupBy('corporation_id');

        foreach ($posesByCorp as $corpId => $corpPoses) {
            // Get webhooks for this corporation
            $corpWebhooks = WebhookConfiguration::getForCorporation($corpId);
            
            if ($corpWebhooks->isEmpty()) {
                \Log::channel('stack')->debug("NotifyPosLowFuel: No webhooks configured for corporation {$corpId}");
                continue;
            }
            
            foreach ($corpPoses as $pos) {
                // Get the latest history record for this POS
                $latest = StarbaseFuelHistory::where('starbase_id', $pos->starbase_id)
                    ->orderBy('created_at', 'desc')
                    ->first();

                if (!$latest) {
                    continue;
                }
                
                // Double-check POS is still online or reinforced
                if (!in_array($latest->state, [3, 4])) {
                    \Log::channel('stack')->debug("NotifyPosLowFuel: Skipping POS {$latest->starbase_id} - not online/reinforced (state: {$latest->state})");
                    continue;
                }

                // CRITICAL FIX: Secondary check - verify POS still exists in corporation_starbases
                // This catches race conditions or POSes removed after initial query
                $posStillExists = DB::table('corporation_starbases')
                    ->where('starbase_id', $latest->starbase_id)
                    ->exists();

                if (!$posStillExists) {
                    \Log::channel('stack')->warning("NotifyPosLowFuel: Skipping POS {$latest->starbase_id} - no longer exists in corporation_starbases (unanchored/removed)");
                    continue;
                }

                \Log::channel('stack')->debug('NotifyPosLowFuel: Checking POS ' . $latest->starbase_id, [
                    'state' => $latest->state,
                    'corporation_id' => $latest->corporation_id,
                    'fuel_days_remaining' => $latest->fuel_days_remaining,
                    'strontium_hours_available' => $latest->strontium_hours_available,
                    'charter_days_remaining' => $latest->charter_days_remaining,
                    'last_fuel_status' => $latest->last_fuel_notification_status,
                    'last_fuel_at' => $latest->last_fuel_notification_at,
                ]);

                // Process fuel/charter notifications
                if ($this->shouldSendFuelNotification($latest, $fuelCriticalDays, $fuelWarningDays, $fuelCriticalInterval, $charterCriticalDays)) {
                    \Log::channel('stack')->info('NotifyPosLowFuel: SENDING fuel notification for POS ' . $latest->starbase_id . ' to ' . $corpWebhooks->count() . ' webhook(s)');
                    foreach ($corpWebhooks as $webhook) {
                        $this->sendFuelNotification($latest, $webhook->webhook_url, $fuelCriticalDays, $fuelWarningDays, $charterCriticalDays, $webhook->role_mention ?? '');
                        $notificationsSent++;
                    }
                }

                // Process strontium notifications
                if ($this->shouldSendStrontiumNotification($latest, $strontiumCriticalHours, $strontiumWarningHours, $strontiumCriticalInterval, $strontiumZeroNotifyOnce, $strontiumZeroGracePeriod)) {
                    \Log::channel('stack')->info('NotifyPosLowFuel: SENDING strontium notification for POS ' . $latest->starbase_id . ' to ' . $corpWebhooks->count() . ' webhook(s)');
                    foreach ($corpWebhooks as $webhook) {
                        $this->sendStrontiumNotification($latest, $webhook->webhook_url, $strontiumCriticalHours, $strontiumWarningHours, $webhook->role_mention ?? '');
                        $notificationsSent++;
                    }
                }
            }
        }

        \Log::channel('stack')->info("NotifyPosLowFuel: Job completed, sent {$notificationsSent} notifications");
    }

    /**
     * Determine if fuel/charter notification should be sent
     * (No changes from original - keeping same logic)
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
     * (No changes from original)
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
     * UPDATED: Improved logic for 0 strontium to prevent spam
     * 
     * @param StarbaseFuelHistory $history Latest fuel history record
     * @param int $criticalHours Critical threshold in hours
     * @param int $warningHours Warning threshold in hours
     * @param int $criticalInterval Hours between reminders in critical stage (0 = disabled)
     * @param bool $zeroNotifyOnce Whether to only notify once for prolonged 0 strontium
     * @param int $zeroGracePeriod Hours to wait before considering 0 strontium as "accepted risk"
     * @return bool
     */
    private function shouldSendStrontiumNotification($history, $criticalHours, $warningHours, $criticalInterval, $zeroNotifyOnce = true, $zeroGracePeriod = 2)
    {
        // Calculate current status
        $currentStatus = $this->determineStrontiumStatus($history, $criticalHours, $warningHours);
        
        \Log::channel('stack')->debug("NotifyPosLowFuel: shouldSendStrontiumNotification for POS {$history->starbase_id}", [
            'current_status' => $currentStatus,
            'last_status' => $history->last_strontium_notification_status,
            'strontium_hours' => $history->strontium_hours_available,
            'state' => $history->state,
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
        $hoursRemaining = $history->strontium_hours_available ?? 0;
        
        // SPECIAL CASE: Zero strontium handling for ONLINE POSes
        if ($hoursRemaining <= 0 && $history->state === 4 && $zeroNotifyOnce) {
            // If we've been at 0 strontium for more than grace period, treat it as "owner doesn't care"
            if ($lastNotificationAt) {
                $hoursSinceFirst = Carbon::now()->diffInHours($lastNotificationAt);
                
                if ($hoursSinceFirst >= $zeroGracePeriod) {
                    // After grace period, only notify on status changes
                    // This prevents spam for POSes that owner intentionally runs with 0 strontium
                    if ($lastStatus === $currentStatus) {
                        \Log::channel('stack')->debug("NotifyPosLowFuel: Skipping repeat notification for POS {$history->starbase_id} - 0 strontium past grace period ({$hoursSinceFirst}h)");
                        return false;
                    }
                }
            }
        }
        
        // Check for final alert (30 minutes remaining)
        if ($hoursRemaining <= 0.5 && !$finalAlertSent && $hoursRemaining > 0) {
            \Log::channel('stack')->info("NotifyPosLowFuel: FINAL STRONTIUM ALERT triggered for POS {$history->starbase_id} (< 30 min remaining)");
            return true;
        }
        
        // Trigger #1: Status change
        if ($lastStatus !== $currentStatus) {
            \Log::channel('stack')->info("NotifyPosLowFuel: Strontium status change detected for POS {$history->starbase_id}: {$lastStatus} → {$currentStatus}");
            return true;
        }
        
        // Trigger #2: Critical interval reminders (only in critical stage)
        // Skip interval notifications for 0 strontium if setting enabled
        if ($hoursRemaining <= 0 && $history->state === 4 && $zeroNotifyOnce) {
            return false; // Don't send interval reminders for 0 strontium on online POSes
        }
        
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
     * (No changes from original)
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
     * Send fuel notification
     * (Keeping original logic, just changed signature slightly)
     */
    private function sendFuelNotification($history, $webhookUrl, $criticalDays, $warningDays, $charterCriticalDays, $roleMention = '')
    {
        $status = $this->determineFuelStatus($history, $criticalDays, $warningDays, $charterCriticalDays);
        $actualDays = $history->actual_days_remaining ?? $history->fuel_days_remaining;
        $hoursRemaining = $actualDays * 24;
        $isFinalAlert = ($hoursRemaining <= 1);

        $alerts = [];

        // Fuel blocks alert
        if ($history->fuel_days_remaining < $warningDays) {
            $isLimitingFactor = ($history->limiting_factor === 'fuel');
            $alerts[] = [
                'resource' => 'Fuel Blocks',
                'current' => number_format($history->fuel_blocks_quantity) . ' blocks',
                'remaining' => $this->formatDaysHours($history->fuel_days_remaining),
                'threshold' => $status === 'critical' ? "{$criticalDays} days" : "{$warningDays} days",
                'type' => $status,
                'is_limiting' => $isLimitingFactor,
            ];
        }

        // Charter alert (high-sec only)
        if ($history->requires_charters && $history->charter_days_remaining < $charterCriticalDays) {
            $isLimitingFactor = ($history->limiting_factor === 'charter');
            $alerts[] = [
                'resource' => 'Sovereignty Charters',
                'current' => number_format($history->charter_quantity) . ' charters',
                'remaining' => $this->formatDaysHours($history->charter_days_remaining),
                'threshold' => "{$charterCriticalDays} days",
                'type' => 'critical',
                'is_limiting' => $isLimitingFactor,
            ];
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
                ($status === 'critical' ? 'Critical: Fuel/Charter Low' : 'Warning: Fuel/Charter Low');

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
     * UPDATED: Better messages for 0 strontium based on POS state
     */
    private function sendStrontiumNotification($history, $webhookUrl, $criticalHours, $warningHours, $roleMention = '')
    {
        $status = $this->determineStrontiumStatus($history, $criticalHours, $warningHours);
        $hoursRemaining = $history->strontium_hours_available ?? 0;
        $isFinalAlert = ($hoursRemaining <= 0.5 && $hoursRemaining > 0);

        $alerts = [];

        // Determine appropriate message based on strontium level and POS state
        $resourceMessage = 'Strontium Clathrates';
        $remainingMessage = $this->formatDaysHours($hoursRemaining / 24);
        
        // UPDATED: Better messaging for 0 strontium
        if ($hoursRemaining <= 0) {
            if ($history->state === 4) {
                // Online POS with 0 strontium
                $resourceMessage = 'Strontium Clathrates - Structure in Possible Danger';
                $remainingMessage = '0h (No reinforcement protection)';
            } elseif ($history->state === 3) {
                // Reinforced POS with 0 strontium
                $resourceMessage = 'Strontium Clathrates - Structure in Danger!';
                $remainingMessage = '0h (CRITICAL: No reinforcement timer!)';
                $status = 'critical'; // Force critical status
            }
        }

        $alerts[] = [
            'resource' => $resourceMessage,
            'current' => number_format($history->strontium_quantity) . ' units',
            'remaining' => $remainingMessage,
            'threshold' => $status === 'critical' ? "{$criticalHours} hours" : "{$warningHours} hours",
            'type' => $status,
            'is_limiting' => false,
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

        // UPDATED: Better title for 0 strontium cases
        if ($hoursRemaining <= 0) {
            $title = $history->state === 4 ? 
                'Warning: Structure in Possible Danger (No Strontium)' : 
                'CRITICAL: Structure in Danger! (No Strontium)';
        } else {
            $title = $isFinalAlert ? 'FINAL ALERT: Strontium Depleted!' : 
                    ($status === 'critical' ? 'Critical: Strontium Low' : 'Warning: Strontium Low');
        }

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
     * (No changes from original)
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

        // Handle mentions properly
        $content = '';
        $allowedMentions = [
            'parse' => [],
            'users' => [],
            'roles' => [],
        ];

        if (($severity === 'critical' || $isFinalAlert) && !empty($roleMention)) {
            $mention = trim($roleMention);
            
            if (preg_match('/^<@&(\d+)>$/', $mention, $m)) {
                $content = "<@&{$m[1]}> ";
                $allowedMentions['roles'][] = $m[1];
            } elseif (preg_match('/^<@!?(\d+)>$/', $mention, $m)) {
                $content = "<@{$m[1]}> ";
                $allowedMentions['users'][] = $m[1];
            } elseif (preg_match('/^\d+$/', $mention)) {
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
     * Format days intelligently
     * (No changes from original)
     */
    private function formatDaysHours($days)
    {
        $wholeDays = floor($days);
        $hours = floor(($days - $wholeDays) * 24);
        
        if ($wholeDays == 0) {
            return "{$hours}h";
        }
        
        return "{$wholeDays}d {$hours}h";
    }
}
