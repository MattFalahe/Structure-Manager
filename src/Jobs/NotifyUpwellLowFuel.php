<?php

namespace StructureManager\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use StructureManager\Models\StructureNotificationStatus;
use StructureManager\Models\StructureManagerSettings;
use StructureManager\Models\Timer;
use StructureManager\Models\WebhookConfiguration;
use StructureManager\Services\WebhookDispatcher;
use StructureManager\Helpers\FuelCalculator;
use Carbon\Carbon;

/**
 * Send Discord/Slack notifications for Upwell structures with low fuel.
 *
 * Proactive polling-based alerts — superior to SeAT core's reactive
 * StructureFuelAlert (which fires once ~48h before empty based on CCP's
 * in-game notification). This job:
 *
 * - Polls every 10 minutes (configurable via ScheduleSeeder)
 * - Fires on status-change transitions (good -> warning -> critical)
 * - Sends a final alert at 1 hour remaining (latched, re-arms on recovery)
 * - Supports optional interval reminders during critical stage
 * - Shows rich data: fuel blocks, consumption rate, service count,
 *   Metenox dual-fuel with limiting factor, predictive offline time,
 *   weekly fuel requirement
 * - Per-webhook corporation filtering + role mentions
 *
 * Uses StructureNotificationStatus (dedicated table, one row per structure)
 * instead of history-row-level tracking, avoiding the latch-propagation
 * fragility seen in the POS notification flow.
 */
class NotifyUpwellLowFuel implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    // $timeout must be LESS than queue.connections.redis.retry_after (960s
    // default in SeAT) to avoid the worker killing the job while Laravel
    // still considers it in-flight — which would cause duplicate webhooks
    // on retry. 600s gives headroom for slow ESI + hundreds of structures.
    public $timeout = 600;
    public $tries = 3;
    public $backoff = [60, 300, 900];

    /**
     * Fuel block type IDs
     */
    const FUEL_BLOCK_TYPES = [4051, 4246, 4247, 4312];

    /**
     * Metenox Moon Drill type ID
     */
    const METENOX_TYPE_ID = 81826;

    /**
     * Magmatic Gas type ID
     */
    const MAGMATIC_GAS_TYPE_ID = 81143;

    /**
     * Execute the job.
     */
    public function handle()
    {
        Log::info('NotifyUpwellLowFuel: Job started');

        // Early bail-out if the upwell.fuel category itself is disabled.
        if (!WebhookDispatcher::isCategoryEnabled('upwell', 'fuel')) {
            Log::debug('NotifyUpwellLowFuel: upwell.fuel category disabled; skipping.');
            return;
        }

        // Load configurable thresholds (independent from POS settings)
        $criticalDays = (int) StructureManagerSettings::get('upwell_fuel_critical_days', 7);
        $warningDays = (int) StructureManagerSettings::get('upwell_fuel_warning_days', 14);
        $criticalInterval = (int) StructureManagerSettings::get('upwell_fuel_notification_interval', 0);

        Log::info('NotifyUpwellLowFuel: Settings loaded', [
            'critical_days' => $criticalDays,
            'warning_days' => $warningDays,
            'interval' => $criticalInterval,
        ]);

        // Get all fueled Upwell structures
        $structures = DB::table('corporation_structures')
            ->whereNotNull('fuel_expires')
            ->get();

        Log::info('NotifyUpwellLowFuel: Found ' . $structures->count() . ' fueled Upwell structures');

        $notificationsSent = 0;

        // Group by corporation for efficient webhook filtering
        $byCorp = $structures->groupBy('corporation_id');

        foreach ($byCorp as $corpId => $corpStructures) {
            // Resolve bindings once per corp. upwell.fuel covers standard structures;
            // upwell.magmatic_gas could also match for Metenox — but for v1 we fan out
            // all upwell fuel alerts (including Metenox dual-fuel) through upwell.fuel
            // to keep behavior identical to pre-refactor. Metenox admins can bind the
            // same webhook to upwell.magmatic_gas as a duplicate channel if desired.
            $bindings = WebhookDispatcher::resolveBindings('upwell', 'fuel', (int) $corpId);

            if (empty($bindings)) {
                continue;
            }

            foreach ($corpStructures as $structure) {
                try {
                    $sent = $this->processStructure(
                        $structure,
                        $bindings,
                        $criticalDays,
                        $warningDays,
                        $criticalInterval
                    );
                    $notificationsSent += $sent;
                } catch (\Throwable $e) {
                    Log::error("NotifyUpwellLowFuel: Error processing structure {$structure->structure_id}: " . $e->getMessage());
                }
            }
        }

        Log::info("NotifyUpwellLowFuel: Job completed, sent {$notificationsSent} notification(s)");
    }

    /**
     * Process a single structure: determine status, check triggers, send if needed.
     *
     * @param array<int, array{webhook_id:int, webhook_url:string, role_mention:?string}> $bindings
     * @return int Number of notifications sent (0 or count of bindings)
     */
    private function processStructure($structure, array $bindings, $criticalDays, $warningDays, $criticalInterval): int
    {
        // Enrich with fuel data
        $fuelData = $this->getStructureFuelData($structure);

        if ($fuelData === null) {
            return 0;
        }

        // Get or create notification tracking row
        $status = StructureNotificationStatus::getOrCreate(
            $structure->structure_id,
            $structure->corporation_id
        );

        // Determine current fuel status
        $currentStatus = $this->determineFuelStatus($fuelData, $criticalDays, $warningDays);

        // Reset latches on recovery (above critical threshold)
        $this->resetLatchesOnRecovery($status, $currentStatus);

        // Upsert a Structure Board timer row so the board reflects this
        // structure's pending fuel expiry. Always called — on recovery to
        // 'good' the row is soft-dismissed (board hides it) but kept for
        // audit; fresh drop re-creates.
        $this->upsertBoardTimer($structure, $fuelData, $currentStatus);

        // Check if notification should fire
        if (!$this->shouldSendNotification($status, $currentStatus, $fuelData, $criticalInterval)) {
            return 0;
        }

        $isFinalAlert = $fuelData['hours_remaining'] > 0 && $fuelData['hours_remaining'] <= 1;

        Log::info("NotifyUpwellLowFuel: SENDING {$currentStatus} notification for structure {$structure->structure_id}" .
            ($isFinalAlert ? ' (FINAL ALERT)' : '') .
            " to " . count($bindings) . " webhook(s)");

        // Send to each applicable webhook
        $sent = 0;
        foreach ($bindings as $binding) {
            $this->sendNotification(
                $structure,
                $fuelData,
                $binding['webhook_url'],
                $currentStatus,
                $isFinalAlert,
                $binding['role_mention'] ?? ''
            );
            $sent++;
        }

        // Update tracking state
        $status->last_fuel_notification_status = $currentStatus;
        $status->last_fuel_notification_at = Carbon::now();
        if ($isFinalAlert) {
            $status->fuel_final_alert_sent = true;
        }
        $status->save();

        // Cross-plugin: publish a `structure.alert.fuel_critical` event
        // through Manager Core's EventBus when this is a refinery with an
        // active moon extraction. Mining Manager subscribes and fires its
        // own extraction_at_risk notification (dedicated channel, mining-
        // domain embed, capital-safety language). No-op if MC is missing
        // or the refinery isn't mining right now.
        $this->publishRefineryAtRiskEvent($structure, $fuelData, $currentStatus);

        return $sent;
    }

    /**
     * Publish a `structure.alert.fuel_critical` event on Manager Core's
     * EventBus when:
     *   - structure is an Athanor (35835) or Tatara (35836)
     *   - current fuel status is 'critical'
     *   - an active moon extraction exists (Mining Manager owns this check)
     *   - Manager Core is installed (EventBus class exists)
     *
     * Mining Manager subscribes to the `structure.alert.*` wildcard and
     * filters this into its extraction_at_risk notification pipeline.
     *
     * This is the FIRST piece of SM's future `structure.alert.*` family —
     * shield_reinforced, armor_reinforced, hull_reinforced, destroyed
     * will follow. See memory doc
     * project_structure_manager_destruction_detection.md for the full design.
     *
     * @pending-sm-work Extend with shield/armor/hull/destroyed event flavors
     *                  in a future SM session. Mining Manager is already
     *                  subscribed to the wildcard pattern, so adding more
     *                  publish calls from SM automatically activates them.
     *
     * @param object $structure
     * @param array  $fuelData
     * @param string $currentStatus
     * @return void
     */
    private function publishRefineryAtRiskEvent($structure, array $fuelData, string $currentStatus): void
    {
        // Refinery check — Athanor 35835, Tatara 35836
        if (!in_array((int) $structure->type_id, [35835, 35836], true)) {
            return;
        }

        // Only fire on the critical transition — warning is too noisy
        if ($currentStatus !== 'critical') {
            return;
        }

        // MC not installed — nothing to publish to
        if (!class_exists('\\ManagerCore\\Services\\EventBus')) {
            return;
        }

        // MM-owned check — is this refinery actually mining right now?
        // If not, SM's existing low-fuel webhook already covers the
        // operator; no need to also spam MM's mining-specific channel.
        if (!FuelCalculator::hasActiveMoonExtraction((int) $structure->structure_id)) {
            return;
        }

        try {
            app(\ManagerCore\Services\EventBus::class)->publish(
                'structure.alert.fuel_critical',
                'structure-manager',
                [
                    'structure_id'    => (int) $structure->structure_id,
                    'corporation_id'  => (int) $structure->corporation_id,
                    'type_id'         => (int) $structure->type_id,
                    'structure_name'  => $fuelData['structure_name'] ?? null,
                    'system_name'     => $fuelData['system_name'] ?? null,
                    'system_security' => $fuelData['system_security'] ?? null,
                    'days_remaining'  => (float) ($fuelData['days_remaining'] ?? 0),
                    'hours_remaining' => (float) ($fuelData['hours_remaining'] ?? 0),
                    'fuel_expires'    => $fuelData['fuel_expires'] ?? null,
                    'hourly_rate'     => (float) ($fuelData['hourly_rate'] ?? 0),
                    'severity'        => $currentStatus,
                ]
            );

            Log::info("NotifyUpwellLowFuel: published structure.alert.fuel_critical for refinery {$structure->structure_id} (active extraction + critical fuel)");
        } catch (\Throwable $e) {
            Log::warning("NotifyUpwellLowFuel: refinery_at_risk event publish failed for structure {$structure->structure_id}: " . $e->getMessage());
        }
    }

    /**
     * Enrich a structure with fuel bay data, consumption rate, services, and
     * Metenox dual-fuel data.
     */
    private function getStructureFuelData($structure): ?array
    {
        // Calculate hours/days remaining from fuel_expires
        $fuelExpires = Carbon::parse($structure->fuel_expires);
        $now = Carbon::now();

        if ($fuelExpires->lte($now)) {
            // Already expired — still report so final alert fires
            $hoursRemaining = 0;
        } else {
            $hoursRemaining = $now->diffInHours($fuelExpires, true) + ($now->diffInMinutes($fuelExpires, true) % 60) / 60;
        }

        $daysRemaining = $hoursRemaining / 24;

        // Get fuel blocks in fuel bay
        $fuelBlocks = DB::table('corporation_assets')
            ->where('location_id', $structure->structure_id)
            ->where('location_flag', 'StructureFuel')
            ->whereIn('type_id', self::FUEL_BLOCK_TYPES)
            ->sum('quantity');

        // Get active services count
        $activeServices = DB::table('corporation_structure_services')
            ->where('structure_id', $structure->structure_id)
            ->where('state', 'online')
            ->count();

        // Get consumption rate from service analysis
        $hourlyRate = 0;
        try {
            $hourlyRate = FuelCalculator::getFuelRequirement(
                $structure->type_id,
                $structure->structure_id,
                'hourly'
            );
        } catch (\Throwable $e) {
            Log::warning("NotifyUpwellLowFuel: Could not calculate consumption for structure {$structure->structure_id}: " . $e->getMessage());
        }

        $weeklyRequirement = round($hourlyRate * 24 * 7);

        // Get structure metadata (name, type, system)
        $meta = $this->getStructureMetadata($structure);

        $data = [
            'structure_id'      => $structure->structure_id,
            'corporation_id'    => $structure->corporation_id,
            'type_id'           => $structure->type_id,
            'structure_name'    => $meta['structure_name'],
            'structure_type'    => $meta['structure_type'],
            'system_name'       => $meta['system_name'],
            'system_security'   => $meta['system_security'],
            'fuel_blocks'       => (int) $fuelBlocks,
            'hours_remaining'   => round($hoursRemaining, 2),
            'days_remaining'    => round($daysRemaining, 2),
            'hourly_rate'       => round($hourlyRate, 2),
            'active_services'   => $activeServices,
            'weekly_requirement' => $weeklyRequirement,
            'is_metenox'        => $structure->type_id == self::METENOX_TYPE_ID,
            'fuel_expires'      => $structure->fuel_expires,
        ];

        // Metenox dual-fuel enrichment
        if ($data['is_metenox']) {
            $magmaticGas = DB::table('corporation_assets')
                ->where('location_id', $structure->structure_id)
                ->where('location_flag', 'StructureFuel')
                ->where('type_id', self::MAGMATIC_GAS_TYPE_ID)
                ->sum('quantity');

            // Metenox: 5 blocks/hour, 200 gas/hour
            $fuelDays = $fuelBlocks > 0 ? $fuelBlocks / (5 * 24) : 0;
            $gasDays = $magmaticGas > 0 ? $magmaticGas / (200 * 24) : 0;
            $actualDays = min($fuelDays, $gasDays);

            $limitingFactor = 'none';
            if ($fuelDays > 0 || $gasDays > 0) {
                if ($fuelDays <= 0) {
                    $limitingFactor = 'fuel_blocks';
                } elseif ($gasDays <= 0) {
                    $limitingFactor = 'magmatic_gas';
                } else {
                    $limitingFactor = $fuelDays < $gasDays ? 'fuel_blocks' : 'magmatic_gas';
                }
            }

            $data['magmatic_gas'] = (int) $magmaticGas;
            $data['fuel_days'] = round($fuelDays, 2);
            $data['gas_days'] = round($gasDays, 2);
            $data['actual_days'] = round($actualDays, 2);
            $data['limiting_factor'] = $limitingFactor;
            $data['weekly_gas_requirement'] = round(200 * 24 * 7);

            // Override days/hours remaining with the limiting resource
            $data['days_remaining'] = round($actualDays, 2);
            $data['hours_remaining'] = round($actualDays * 24, 2);
        }

        return $data;
    }

    /**
     * Get display metadata for a structure (name, type, system, security).
     */
    private function getStructureMetadata($structure): array
    {
        $row = DB::table('corporation_structures as cs')
            ->join('universe_structures as us', 'cs.structure_id', '=', 'us.structure_id')
            ->join('invTypes as it', 'cs.type_id', '=', 'it.typeID')
            ->join('mapDenormalize as md', 'cs.system_id', '=', 'md.itemID')
            ->where('cs.structure_id', $structure->structure_id)
            ->select('us.name as structure_name', 'it.typeName as structure_type', 'md.itemName as system_name', 'md.security')
            ->first();

        return [
            'structure_name' => $row->structure_name ?? 'Unknown Structure',
            'structure_type' => $row->structure_type ?? 'Unknown',
            'system_name'    => $row->system_name ?? 'Unknown System',
            'system_security' => $row->security ?? null,
        ];
    }

    /**
     * Determine fuel status: good, warning, or critical.
     * For Metenox: uses the effective remaining days (min of fuel and gas).
     */
    private function determineFuelStatus(array $fuelData, int $criticalDays, int $warningDays): string
    {
        $days = $fuelData['days_remaining'];

        if ($days < $criticalDays) {
            return 'critical';
        }
        if ($days < $warningDays) {
            return 'warning';
        }
        return 'good';
    }

    /**
     * Should we send a notification for this structure right now?
     *
     * Three triggers (same pattern as POS):
     *   1. Status change — always fires
     *   2. Final alert at <= 1 hour — fires once (latched), requires hoursRemaining > 0
     *   3. Critical interval reminders — optional (0 = disabled)
     */
    private function shouldSendNotification(
        StructureNotificationStatus $status,
        string $currentStatus,
        array $fuelData,
        int $criticalInterval
    ): bool {
        // No alerts for good status
        if ($currentStatus === 'good') {
            return false;
        }

        $lastStatus = $status->last_fuel_notification_status;
        $lastNotifiedAt = $status->last_fuel_notification_at;
        $finalAlertSent = $status->fuel_final_alert_sent ?? false;
        $hoursRemaining = $fuelData['hours_remaining'];

        // Trigger 1: Final alert at <= 1 hour remaining.
        // Guard: hoursRemaining > 0 to avoid spurious alerts on ESI lag / fresh structures.
        if ($hoursRemaining > 0 && $hoursRemaining <= 1 && !$finalAlertSent) {
            Log::info("NotifyUpwellLowFuel: FINAL ALERT for structure {$fuelData['structure_id']} ({$hoursRemaining}h remaining)");
            return true;
        }

        // Trigger 2: Status change (good -> warning, warning -> critical, etc.)
        if ($lastStatus !== $currentStatus) {
            Log::info("NotifyUpwellLowFuel: Status change for structure {$fuelData['structure_id']}: {$lastStatus} -> {$currentStatus}");
            return true;
        }

        // Trigger 3: Critical interval reminders (only in critical stage, optional)
        if ($currentStatus === 'critical' && $criticalInterval > 0 && $lastNotifiedAt) {
            $hoursSinceLastNotification = Carbon::now()->diffInHours($lastNotifiedAt, true);

            if ($hoursSinceLastNotification >= $criticalInterval) {
                Log::info("NotifyUpwellLowFuel: Critical interval reached for structure {$fuelData['structure_id']} ({$hoursSinceLastNotification}h since last, interval: {$criticalInterval}h)");
                return true;
            }
        }

        return false;
    }

    /**
     * Upsert a Structure Board timer row for this structure's fuel expiry.
     *
     * Dedup key is "fuel:{structure_id}" — the same row evolves as status
     * transitions (warning → critical → final). Row is soft-dismissed when
     * status recovers to 'good', kept for audit.
     */
    private function upsertBoardTimer($structure, array $fuelData, string $currentStatus): void
    {
        $isFinalAlert = $fuelData['hours_remaining'] > 0 && $fuelData['hours_remaining'] <= 1;
        $eventType = $isFinalAlert ? 'fuel_final'
            : ($currentStatus === 'critical' ? 'fuel_critical'
                : ($currentStatus === 'warning' ? 'fuel_warning' : null));

        // Resolve owner corp name (cached lookup OK — small set)
        $ownerName = \DB::table('corporation_infos')
            ->where('corporation_id', $structure->corporation_id)
            ->value('name');

        if ($currentStatus === 'good' && $eventType === null) {
            // Recovered — soft-dismiss any existing row for this structure
            Timer::where('source_reference', "fuel:{$structure->structure_id}")
                ->whereNull('dismissed_at')
                ->update(['dismissed_at' => Carbon::now()]);
            return;
        }

        if ($eventType === null) {
            return;
        }

        Timer::upsertAuto([
            'source'                 => 'auto_fuel',
            'event_type'             => $eventType,
            'severity'               => $isFinalAlert || $currentStatus === 'critical' ? 'critical' : 'warning',
            'structure_id'           => $structure->structure_id,
            'structure_name'         => $fuelData['structure_name'] ?? null,
            'structure_type'         => $fuelData['structure_type'] ?? null,
            'structure_type_id'      => $structure->type_id ?? null,
            'system_id'              => $structure->system_id ?? null,
            'system_name'            => $fuelData['system_name'] ?? null,
            'system_security'        => $fuelData['system_security'] ?? null,
            'corporation_id'         => $structure->corporation_id,
            'owner_corporation_name' => $ownerName,
            'eve_time'               => Carbon::parse($fuelData['fuel_expires']),
            'source_reference'       => "fuel:{$structure->structure_id}",
            // On re-fuel+redrop, clear dismissed_at so the row reappears
            'dismissed_at'           => null,
        ]);
    }

    /**
     * Clear the final-alert latch when status recovers above critical.
     * This re-arms the latch so a future drop to <= 1 hour fires again.
     */
    private function resetLatchesOnRecovery(StructureNotificationStatus $status, string $currentStatus): void
    {
        if ($currentStatus !== 'critical' && ($status->fuel_final_alert_sent ?? false)) {
            $status->fuel_final_alert_sent = false;
            $status->save();
            Log::debug("NotifyUpwellLowFuel: Reset fuel_final_alert_sent for structure {$status->structure_id} (recovered to {$currentStatus})");
        }
    }

    /**
     * Build and send a Discord/Slack webhook notification.
     */
    private function sendNotification($structure, array $fuelData, string $webhookUrl, string $currentStatus, bool $isFinalAlert, string $roleMention = ''): void
    {
        // SECURITY: revalidate URL before every POST
        if (!WebhookConfiguration::isValidWebhookUrl($webhookUrl)) {
            Log::error("NotifyUpwellLowFuel: Refusing to POST to invalid webhook URL. Edit and re-save in settings.");
            return;
        }

        $payload = $this->buildDiscordPayload($fuelData, $currentStatus, $isFinalAlert, $roleMention);

        try {
            $response = Http::connectTimeout(5)->timeout(10)->post($webhookUrl, $payload);

            if ($response->successful()) {
                $alertType = $isFinalAlert ? 'FINAL' : $currentStatus;
                Log::info("NotifyUpwellLowFuel: Sent {$alertType} notification for structure {$fuelData['structure_id']}");
            } else {
                Log::error("NotifyUpwellLowFuel: Webhook failed - HTTP {$response->status()}: " . $response->body());
            }
        } catch (\Throwable $e) {
            Log::error("NotifyUpwellLowFuel: Webhook exception - " . $e->getMessage());
        }
    }

    /**
     * Build a Discord webhook payload with rich embed fields.
     */
    private function buildDiscordPayload(array $fuelData, string $status, bool $isFinalAlert, string $roleMention): array
    {
        // Colors: warning=yellow, critical=red, final=dark-red
        if ($isFinalAlert) {
            $color = 10038562;  // 0x992D22 - dark red
        } elseif ($status === 'critical') {
            $color = 15158332;  // 0xE74C3C - red
        } else {
            $color = 16776960;  // 0xFFFF00 - yellow
        }

        // Title
        if ($isFinalAlert) {
            $title = 'FINAL ALERT: ' . $fuelData['structure_name'];
        } else {
            $title = $fuelData['structure_name'];
        }

        // Build embed fields
        $fields = [];

        // Location + Type + Last Update (top row, inline)
        $securityDisplay = $fuelData['system_security'] !== null
            ? ' (' . number_format($fuelData['system_security'], 2) . ')'
            : '';

        $fields[] = [
            'name' => "\u{1F4CD} Location",  // pin emoji
            'value' => $fuelData['system_name'] . $securityDisplay,
            'inline' => true,
        ];

        $fields[] = [
            'name' => 'Structure Type',
            'value' => $fuelData['structure_type'],
            'inline' => true,
        ];

        $fields[] = [
            'name' => "\u{23F0} Last Update",  // clock emoji
            'value' => Carbon::now()->diffForHumans(),
            'inline' => true,
        ];

        // Final alert: prominent GOING OFFLINE field
        if ($isFinalAlert) {
            $minutesLeft = max(1, round($fuelData['hours_remaining'] * 60));
            $fields[] = [
                'name' => 'GOING OFFLINE IN',
                'value' => "**{$minutesLeft} minutes**",
                'inline' => false,
            ];
        }

        // Metenox dual-fuel display
        if ($fuelData['is_metenox']) {
            $fuelLabel = 'Fuel Blocks';
            $gasLabel = 'Magmatic Gas';
            if ($fuelData['limiting_factor'] === 'fuel_blocks') {
                $fuelLabel .= ' **[LIMITING]**';
            } elseif ($fuelData['limiting_factor'] === 'magmatic_gas') {
                $gasLabel .= ' **[LIMITING]**';
            }

            $fields[] = [
                'name' => $fuelLabel,
                'value' => number_format($fuelData['fuel_blocks']) . ' blocks (' . $this->formatDaysHours($fuelData['fuel_days']) . ')',
                'inline' => false,
            ];

            $fields[] = [
                'name' => $gasLabel,
                'value' => number_format($fuelData['magmatic_gas']) . ' gas (' . $this->formatDaysHours($fuelData['gas_days']) . ')',
                'inline' => false,
            ];

            // Predictive offline time
            $offlineResource = $fuelData['limiting_factor'] === 'magmatic_gas' ? 'gas runs out first' : 'fuel runs out first';
            $fields[] = [
                'name' => 'Offline In',
                'value' => $this->formatDaysHours($fuelData['actual_days']) . ' (' . $offlineResource . ')',
                'inline' => false,
            ];

            // Weekly requirement
            $fields[] = [
                'name' => 'Weekly Requirement',
                'value' => number_format($fuelData['weekly_requirement']) . ' blocks + ' . number_format($fuelData['weekly_gas_requirement']) . ' gas',
                'inline' => true,
            ];
        } else {
            // Standard Upwell structure
            $fields[] = [
                'name' => 'Fuel Blocks',
                'value' => number_format($fuelData['fuel_blocks']) . ' blocks remaining',
                'inline' => false,
            ];

            if (!$isFinalAlert) {
                $fields[] = [
                    'name' => 'Time Remaining',
                    'value' => $this->formatDaysHours($fuelData['days_remaining']) . ' at current rate',
                    'inline' => false,
                ];
            }

            $fields[] = [
                'name' => 'Consumption Rate',
                'value' => number_format($fuelData['hourly_rate'], 1) . ' blocks/hour',
                'inline' => true,
            ];

            $fields[] = [
                'name' => 'Active Services',
                'value' => $fuelData['active_services'] . ' service(s) online',
                'inline' => true,
            ];

            $fields[] = [
                'name' => 'Weekly Requirement',
                'value' => number_format($fuelData['weekly_requirement']) . ' blocks',
                'inline' => true,
            ];
        }

        $embed = [
            'title' => $title,
            'color' => $color,
            'fields' => $fields,
            'footer' => [
                'text' => 'SeAT Structure Manager | Structure ID: ' . $fuelData['structure_id'],
            ],
            'timestamp' => Carbon::now()->toIso8601String(),
        ];

        // Build content line with optional role mention
        $content = '';
        $allowedMentions = ['parse' => [], 'users' => [], 'roles' => []];

        if (($status === 'critical' || $isFinalAlert) && !empty($roleMention)) {
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

        if ($isFinalAlert) {
            $content .= '**FINAL ALERT: Upwell Structure Going Offline!**';
        } elseif ($status === 'critical') {
            $content .= '**Critical: Upwell Structure Low Fuel**';
        } else {
            $content .= '**Warning: Upwell Structure Low Fuel**';
        }

        $content .= ' - 1 structure needs attention';

        return [
            'content' => $content,
            'embeds' => [$embed],
            'username' => 'SeAT Structure Manager',
            'allowed_mentions' => $allowedMentions,
        ];
    }

    /**
     * Format days as "Xd Yh" string.
     */
    private function formatDaysHours(float $days): string
    {
        $wholeDays = floor($days);
        $hours = floor(($days - $wholeDays) * 24);

        if ($wholeDays == 0) {
            return "{$hours}h";
        }

        return "{$wholeDays}d {$hours}h";
    }
}
