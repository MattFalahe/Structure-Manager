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
use StructureManager\Services\TimerEventPublisher;
use StructureManager\Helpers\FuelCalculator;
use StructureManager\Helpers\FuelThresholds;
use StructureManager\Helpers\TypeIdRegistry;
use StructureManager\Helpers\AlertEventEnvelope;
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
     * Fuel block type IDs.
     * @deprecated use TypeIdRegistry::FUEL_BLOCK_IDS
     */
    const FUEL_BLOCK_TYPES = TypeIdRegistry::FUEL_BLOCK_IDS;

    /**
     * Metenox Moon Drill type ID.
     * @deprecated use TypeIdRegistry::METENOX
     */
    const METENOX_TYPE_ID = TypeIdRegistry::METENOX;

    /**
     * Magmatic Gas type ID.
     * @deprecated use TypeIdRegistry::MAGMATIC_GAS
     */
    const MAGMATIC_GAS_TYPE_ID = TypeIdRegistry::MAGMATIC_GAS;

    /**
     * Cyno-module reagent type IDs.
     *   Liquid Ozone — consumed by Standup Cyno Generator per cyno cycle
     *   Strontium Clathrate — consumed by Standup Cyno Jammer per jam cycle
     *   (also POS strontium type ID — same item across both contexts)
     */
    const LIQUID_OZONE_TYPE_ID       = 16273;
    const STRONTIUM_CLATHRATE_TYPE_ID = 16275;

    /**
     * Default thresholds for cyno reagent quantity alerts. Quantity-based
     * (not time-based) because cyno modules consume on demand per cycle —
     * "1 hour remaining" makes no sense for an idle cyno gen, but "you have
     * enough for 1 cyno left" does. Admins tune via settings.
     */
    const DEFAULT_LIQUID_OZONE_WARNING  = 25000;
    const DEFAULT_LIQUID_OZONE_CRITICAL = 5000;
    const DEFAULT_STRONTIUM_WARNING     = 50000;
    const DEFAULT_STRONTIUM_CRITICAL    = 10000;

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
        // Thresholds locked in code via FuelThresholds — see helper docblock.
        $criticalDays = FuelThresholds::UPWELL_FUEL_CRITICAL_DAYS;
        $warningDays  = FuelThresholds::UPWELL_FUEL_WARNING_DAYS;
        // Interval (cadence between repeats during critical) stays configurable.
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

            foreach ($corpStructures as $structure) {
                // Test-structure routing: if the structure is in the safe test
                // range AND a test_webhook_url is configured, redirect notifications
                // to that URL only — production webhooks never see test traffic.
                // Without this, the diagnostic page's "Run Upwell notification check"
                // button cannot exercise SM's enriched dual-fuel embed against test
                // Metenoxes (test corps don't have webhook bindings configured).
                $structureBindings = $this->resolveBindingsForStructure($structure, $bindings);

                if (empty($structureBindings)) {
                    continue;
                }

                try {
                    $sent = $this->processStructure(
                        $structure,
                        $structureBindings,
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

        // Cyno reagent pass — runs alongside the fuel pass but keyed off the
        // upwell.cyno_reagents category, not upwell.fuel. Iterates only structures
        // that have a Standup Cyno Generator or Cyno Jammer service module.
        try {
            $cynoSent = $this->processCynoReagents();
            $notificationsSent += $cynoSent;
        } catch (\Throwable $e) {
            Log::error('NotifyUpwellLowFuel: cyno reagent pass failed: ' . $e->getMessage());
        }

        Log::info("NotifyUpwellLowFuel: Job completed, sent {$notificationsSent} notification(s)");
    }

    /**
     * Cyno reagent pass — alerts when Liquid Ozone or Strontium Clathrate runs
     * low in the fuel bay of a structure with the corresponding cyno service.
     *
     * Detection: query corporation_structure_services for online services with
     * 'Cyno Generator' or 'Cyno Jammer' in the name, joined to corporation_structures.
     * For each match, sum the reagent quantity from corporation_assets (where
     * location_id = structure_id, location_flag = StructureFuel) and compare
     * against configured warning/critical thresholds.
     *
     * Same notification triggers as fuel: status transitions only (good →
     * warning → critical), no spam if quantity stays in the same bracket.
     * Status latch lives on the Timer board via source_reference dedup —
     * source_reference = 'cyno_reagent:{structure_id}:{reagent}'.
     *
     * @return int Number of webhook dispatches fired across all structures
     */
    private function processCynoReagents(): int
    {
        if (!WebhookDispatcher::isCategoryEnabled('upwell', 'cyno_reagents')) {
            Log::debug('NotifyUpwellLowFuel: upwell.cyno_reagents category disabled; skipping cyno pass.');
            return 0;
        }

        // Cyno reagent thresholds also locked — see FuelThresholds helper.
        $loWarn  = FuelThresholds::PHAROLUX_LIQUID_OZONE_WARNING_QTY;
        $loCrit  = FuelThresholds::PHAROLUX_LIQUID_OZONE_CRITICAL_QTY;
        $srWarn  = FuelThresholds::TENEBREX_STRONTIUM_WARNING_QTY;
        $srCrit  = FuelThresholds::TENEBREX_STRONTIUM_CRITICAL_QTY;

        // Find all online cyno-module services across corporation_structures.
        // Use LIKE matching on the service name rather than hardcoded type IDs
        // so future CCP module renames or tier additions just work.
        $rows = DB::table('corporation_structure_services as css')
            ->join('corporation_structures as cs', 'css.structure_id', '=', 'cs.structure_id')
            ->where('css.state', 'online')
            ->where(function ($q) {
                $q->where('css.name', 'LIKE', '%Cyno Generator%')
                  ->orWhere('css.name', 'LIKE', '%Cyno Jammer%');
            })
            ->select(
                'cs.structure_id',
                'cs.corporation_id',
                'cs.type_id',
                'cs.system_id',
                'css.name as service_name'
            )
            ->get();

        if ($rows->isEmpty()) {
            Log::debug('NotifyUpwellLowFuel: no cyno modules detected; cyno pass complete.');
            return 0;
        }

        Log::info('NotifyUpwellLowFuel: cyno pass found ' . $rows->count() . ' active cyno service(s)');

        // Pre-load display metadata once for the whole batch. Without this, both
        // upsertCynoBoardTimer() and buildCynoReagentPayload() each do 3-4 DB
        // lookups per row (universe_structures, invTypes, mapDenormalize,
        // corporation_infos). With it, the loop body only does the per-row asset
        // sum that actually varies — everything else is dictionary access.
        $structureIds = $rows->pluck('structure_id')->unique()->values()->all();
        $typeIds      = $rows->pluck('type_id')->unique()->values()->all();
        $systemIds    = $rows->pluck('system_id')->filter()->unique()->values()->all();
        $corpIds      = $rows->pluck('corporation_id')->unique()->values()->all();

        $structureNames = DB::table('universe_structures')
            ->whereIn('structure_id', $structureIds)
            ->pluck('name', 'structure_id')
            ->all();
        $typeNames = DB::table('invTypes')
            ->whereIn('typeID', $typeIds)
            ->pluck('typeName', 'typeID')
            ->all();
        $systems = !empty($systemIds)
            ? DB::table('mapDenormalize')
                ->whereIn('itemID', $systemIds)
                ->select('itemID', 'itemName', 'security')
                ->get()
                ->keyBy('itemID')
                ->all()
            : [];
        $corpNames = DB::table('corporation_infos')
            ->whereIn('corporation_id', $corpIds)
            ->pluck('name', 'corporation_id')
            ->all();

        $sent = 0;
        foreach ($rows as $row) {
            // Determine which reagent + threshold pair applies based on service name
            $isJammer = stripos($row->service_name, 'Jammer') !== false;
            $reagentTypeId = $isJammer ? self::STRONTIUM_CLATHRATE_TYPE_ID : self::LIQUID_OZONE_TYPE_ID;
            $reagentName   = $isJammer ? 'Strontium Clathrate' : 'Liquid Ozone';
            $warnThreshold = $isJammer ? $srWarn : $loWarn;
            $critThreshold = $isJammer ? $srCrit : $loCrit;

            // Sum reagent quantity in the structure's fuel bay
            $qty = (int) DB::table('corporation_assets')
                ->where('location_id', $row->structure_id)
                ->where('location_flag', 'StructureFuel')
                ->where('type_id', $reagentTypeId)
                ->sum('quantity');

            // Race guard: cyno reagents live in the same StructureFuel bay
            // as fuel blocks. If the reagent reads 0 but cs.fuel_expires
            // shows the structure still has many hours of fuel, the assets
            // table is mid-DELETE-INSERT for this corp and the reagent
            // rows are temporarily missing. Cross-check against
            // cs.fuel_expires (same bay, same race) before alerting.
            if ($qty == 0) {
                $fuelExpires = DB::table('corporation_structures')
                    ->where('structure_id', $row->structure_id)
                    ->value('fuel_expires');
                if ($fuelExpires !== null) {
                    $hoursOfFuelLeft = Carbon::now()->diffInHours(Carbon::parse($fuelExpires), false);
                    if ($hoursOfFuelLeft > 12) {
                        Log::warning(sprintf(
                            'NotifyUpwellLowFuel: suspected corp-assets refresh race on cyno reagent '
                            . 'at structure %d (%s reads 0 but cs.fuel_expires shows %.1fh of fuel), '
                            . 'skipping this row',
                            $row->structure_id,
                            $reagentName,
                            $hoursOfFuelLeft
                        ));
                        continue;
                    }
                }
            }

            $status = $this->classifyReagentStatus($qty, $warnThreshold, $critThreshold);

            if ($status === 'good') {
                // Recovery — soft-dismiss any existing timer row for this reagent
                Timer::where('source_reference', "cyno_reagent:{$row->structure_id}:" . ($isJammer ? 'strontium' : 'liquid_ozone'))
                    ->whereNull('dismissed_at')
                    ->update(['dismissed_at' => Carbon::now()]);
                continue;
            }

            // Resolve metadata for this row from preloaded dictionaries (O(1) each)
            $meta = [
                'structure_name' => $structureNames[$row->structure_id] ?? null,
                'structure_type' => $typeNames[$row->type_id] ?? null,
                'system'         => $systems[$row->system_id] ?? null,
                'owner_name'     => $corpNames[$row->corporation_id] ?? null,
            ];

            // Resolve bindings for this corp; honor test-routing for safe-range structures.
            $corpBindings = WebhookDispatcher::resolveBindings('upwell', 'cyno_reagents', (int) $row->corporation_id);
            $bindings = $this->resolveBindingsForStructure($row, $corpBindings);

            // Upsert Timer board row regardless of webhook bindings (board still
            // shows it even if no webhook is bound)
            $this->upsertCynoBoardTimer($row, $reagentName, $reagentTypeId, $qty, $status, $isJammer, $meta);

            if (empty($bindings)) {
                continue;
            }

            // Build one webhook payload per structure-reagent pair
            $payload = $this->buildCynoReagentPayload($row, $reagentName, $qty, $warnThreshold, $critThreshold, $status, $isJammer, $meta);

            foreach ($bindings as $binding) {
                if (!\StructureManager\Models\WebhookConfiguration::isValidWebhookUrl($binding['webhook_url'])) {
                    continue;
                }
                $finalPayload = $this->injectCynoReagentMention($payload, $binding['role_mention'] ?? '', $status);

                // v2.0.0 — route through WebhookDeliveryService for telemetry
                $ok = \StructureManager\Services\WebhookDeliveryService::sendByUrl(
                    $binding['webhook_url'],
                    $finalPayload,
                    'upwell.cyno_reagents',
                    "Cyno reagent {$status} — structure #{$row->structure_id}"
                );
                if ($ok) {
                    $sent++;
                }
            }

            Log::info("NotifyUpwellLowFuel: dispatched cyno_reagent {$status} for structure {$row->structure_id} ({$reagentName}={$qty})");
        }

        return $sent;
    }

    private function classifyReagentStatus(int $qty, int $warn, int $crit): string
    {
        if ($qty < $crit) return 'critical';
        if ($qty < $warn) return 'warning';
        return 'good';
    }

    private function buildCynoReagentPayload($row, string $reagentName, int $qty, int $warn, int $crit, string $status, bool $isJammer, array $meta = []): array
    {
        $color = $status === 'critical' ? 15158332 : 16776960; // red / yellow

        // Display metadata is preloaded in the caller (processCynoReagents)
        // and passed via $meta. Helpers no longer hit the DB per row.
        $structureName = ($meta['structure_name'] ?? null) ?: ('Structure #' . $row->structure_id);
        $structureType = ($meta['structure_type'] ?? null) ?: 'Unknown';
        $sys = $meta['system'] ?? null;
        $systemDisplay = $sys ? $sys->itemName . ' (' . number_format($sys->security, 2) . ')' : 'Unknown';

        $moduleLabel = $isJammer ? 'Standup Cyno Jammer' : 'Standup Cyno Generator';
        $consumeNote = $isJammer
            ? '*Consumed per jam cycle when actively jamming.*'
            : '*Consumed per cyno cycle (~5,000 per fire).*';

        $fields = [
            ['name' => "\u{1F4CD} Location",     'value' => $systemDisplay,  'inline' => true],
            ['name' => 'Structure Type',         'value' => $structureType,  'inline' => true],
            ['name' => "\u{23F0} Last Update",   'value' => Carbon::now()->diffForHumans(), 'inline' => true],

            ['name' => "\u{1F9EA} Reagent",      'value' => $reagentName,    'inline' => true],
            ['name' => 'Module',                 'value' => $moduleLabel,    'inline' => true],
            ['name' => "\u{1F4E6} Quantity",     'value' => '**' . number_format($qty) . '** units', 'inline' => true],

            ['name' => "\u{1F525} Status",       'value' => '**' . strtoupper($status) . '**' . "\nThreshold: < " . number_format($status === 'critical' ? $crit : $warn), 'inline' => false],
            ['name' => 'Note',                   'value' => $consumeNote,    'inline' => false],
        ];

        $embed = [
            'title'     => $structureName . " \u{2014} {$reagentName} " . strtoupper($status),
            'color'     => $color,
            'fields'    => $fields,
            'footer'    => ['text' => 'SeAT Structure Manager | Structure ID: ' . $row->structure_id],
            'timestamp' => Carbon::now()->toIso8601String(),
        ];

        $contentTitle = $status === 'critical'
            ? "**CRITICAL: {$reagentName} low** — restock to keep cyno service operational"
            : "**Warning: {$reagentName} low**";

        return [
            'content'         => $contentTitle,
            'embeds'          => [$embed],
            'username'        => 'SeAT Structure Manager',
            'allowed_mentions' => ['parse' => [], 'users' => [], 'roles' => []],
        ];
    }

    private function injectCynoReagentMention(array $payload, ?string $roleMention, string $status): array
    {
        if (empty($roleMention)) return $payload;
        // Only ping for critical (matches existing fuel-alert behavior)
        if ($status !== 'critical') return $payload;

        $mention = trim($roleMention);
        if (preg_match('/^<@&(\d+)>$/', $mention, $m)) {
            $payload['content'] = "<@&{$m[1]}> " . ($payload['content'] ?? '');
            $payload['allowed_mentions']['roles'][] = $m[1];
        } elseif (preg_match('/^\d+$/', $mention)) {
            $payload['content'] = "<@&{$mention}> " . ($payload['content'] ?? '');
            $payload['allowed_mentions']['roles'][] = $mention;
        } elseif (preg_match('/^<@!?(\d+)>$/', $mention, $m)) {
            $payload['content'] = "<@{$m[1]}> " . ($payload['content'] ?? '');
            $payload['allowed_mentions']['users'][] = $m[1];
        }
        return $payload;
    }

    private function upsertCynoBoardTimer($row, string $reagentName, int $reagentTypeId, int $qty, string $status, bool $isJammer, array $meta = []): void
    {
        $reagentKey = $isJammer ? 'strontium' : 'liquid_ozone';

        // Display metadata is preloaded in the caller (processCynoReagents)
        // and passed via $meta. Helpers no longer hit the DB per row.
        $structureName = $meta['structure_name'] ?? null;
        $structureType = $meta['structure_type'] ?? null;
        $sys = $meta['system'] ?? null;
        $ownerName = $meta['owner_name'] ?? null;

        Timer::upsertAuto([
            'source'                 => 'auto_fuel',
            'event_type'             => $status === 'critical' ? 'fuel_critical' : 'fuel_warning',
            'severity'               => $status,
            'structure_id'           => $row->structure_id,
            'structure_name'         => ($structureName ?? 'Unknown') . " — {$reagentName}",
            'structure_type'         => $structureType,
            'structure_type_id'      => $row->type_id,
            'system_id'              => $row->system_id,
            'system_name'            => $sys->itemName ?? null,
            'system_security'        => $sys->security ?? null,
            'corporation_id'         => $row->corporation_id,
            'owner_corporation_name' => $ownerName,
            // No deterministic eve_time for cyno reagents (consumption is event-driven, not steady).
            // Use now() so the row sorts to "current" on the board; admin reads quantity from notes.
            'eve_time'               => Carbon::now(),
            'notes'                  => "{$reagentName}: " . number_format($qty) . " units (status: {$status})",
            'source_reference'       => "cyno_reagent:{$row->structure_id}:{$reagentKey}",
            'dismissed_at'           => null,
        ]);
    }

    /**
     * Process a single Upwell structure for fuel-status alerts. Decides
     * whether to fire warning / critical / final-alert webhook(s), updates
     * the local notification-status latch, upserts a Structure Board timer
     * row, and (when MC is installed) publishes cross-plugin events on
     * recovery / critical transitions.
     *
     * @param array<int, array{webhook_id:int, webhook_url:string, role_mention:?string}> $bindings
     * @return int Number of notifications sent (0 or count of bindings)
     */

    /**
     * Pick the correct webhook bindings for a given structure.
     *
     * Test-structure routing: if the structure_id is in TestDataGenerator's
     * safe range AND a test_webhook_url is configured, return a single
     * synthetic binding pointing to the test URL. Otherwise return the
     * production corp bindings unchanged.
     *
     * This is what lets the diagnostic page exercise SM's enriched dual-fuel
     * embed against a test Metenox without a real corp webhook configuration —
     * test traffic flows ONLY to the configured test webhook URL, never to
     * production webhooks (test corp 2.1B range typically has no bindings
     * anyway, so the previous behavior was to silently skip test structures).
     *
     * @param  object  $structure  corporation_structures row (DB::table fetched)
     * @param  array   $corpBindings  bindings already resolved for the structure's corp
     * @return array
     */
    private function resolveBindingsForStructure($structure, array $corpBindings): array
    {
        $structureId = (int) ($structure->structure_id ?? 0);
        if ($structureId === 0) {
            return $corpBindings;
        }

        // Only test-route when the structure is in the safe range
        if (!\StructureManager\Services\TestDataGenerator::isTestStructure($structureId)) {
            return $corpBindings;
        }

        $testUrl = (string) StructureManagerSettings::get('test_webhook_url', '');
        if ($testUrl === '' || !WebhookConfiguration::isValidWebhookUrl($testUrl)) {
            // Test structure but no test webhook configured — explicitly drop
            // (don't fall through to corp bindings; would let test traffic into
            // production webhooks if test corp ever has a real binding).
            return [];
        }

        return [[
            'webhook_id'   => 0, // synthetic, not in webhook_configurations
            'webhook_url'  => $testUrl,
            'role_mention' => '',
        ]];
    }

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

        // Detect a recovery transition: structure was previously alerting at
        // warning/critical and is now back to good. Publish a recovery event
        // so cross-plugin subscribers can clear their dedup latches without
        // waiting for the next critical fire. Snapshot+commit pattern: read
        // the previous status BEFORE updating, write 'good' back if we fired,
        // so we don't double-publish on the next 10-min poll.
        $previousStatus = $status->last_fuel_notification_status ?? null;
        $wasAlerting = in_array($previousStatus, ['warning', 'critical'], true);
        if ($wasAlerting && $currentStatus === 'good') {
            $this->publishFuelRecoveredEvent($structure, $fuelData);

            // Family B: also publish structure_manager.timer.recovered for
            // the active fuel timer (if one exists). The timer is about to
            // be auto-soft-dismissed by upsertBoardTimer below — firing
            // recovered BEFORE that dismiss gives subscribers two distinct
            // signals: "good news, this fuel timer's condition resolved"
            // and (immediately after) "the row was dismissed." Subscribers
            // can react to either or both.
            $recoveryTimer = Timer::where('source_reference', "fuel:{$structure->structure_id}")
                ->whereNull('dismissed_at')
                ->first();
            if ($recoveryTimer) {
                TimerEventPublisher::publish('recovered', $recoveryTimer, [
                    'recovered_to_status' => 'good',
                    'previous_severity'   => $previousStatus,
                ]);
            }

            $status->last_fuel_notification_status = 'good';
            $status->last_fuel_notification_at = Carbon::now();
            $status->save();
        }

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

        // Cross-plugin: publish a `structure.alert.fuel_critical` event through
        // Manager Core's EventBus for ALL critical-fuel structures. Subscribers
        // filter to the structures they care about (MM filters to refineries
        // with active moon extractions; future subscribers can use their own
        // criteria). No-op if MC is missing.
        $this->publishFuelCriticalEvent($structure, $fuelData, $currentStatus);

        return $sent;
    }

    /**
     * Publish a `structure.alert.fuel_critical` event on Manager Core's
     * EventBus when:
     *   - current fuel status is 'critical'
     *   - Manager Core is installed (EventBus class exists)
     *
     * Fires for ALL critical-fuel Upwell structures uniformly with the other 4
     * `structure.alert.*` flavors (shield_reinforced, armor_reinforced,
     * hull_reinforced, destroyed). Subscribers are responsible for filtering
     * to the structures they care about — Mining Manager filters to refineries
     * with active moon extractions inside its own StructureAlertHandler;
     * future subscribers (Pings, etc.) can route by their own criteria.
     *
     * This was previously gated behind a refinery+active-extraction filter to
     * limit MM's notification volume, but that left non-MM subscribers unable
     * to receive fuel-critical events for citadels and engineering complexes.
     * The contract pattern is "publish broadly, subscribe narrowly" — push the
     * filtering to where it belongs (subscribers).
     *
     * @param object $structure
     * @param array  $fuelData
     * @param string $currentStatus
     * @return void
     */
    private function publishFuelCriticalEvent($structure, array $fuelData, string $currentStatus): void
    {
        // Only fire on the critical transition — warning is intentionally not
        // an event flavor (matches contract: only critical + recovered fire)
        if ($currentStatus !== 'critical') {
            return;
        }

        // MC not installed — nothing to publish to
        if (!class_exists('\\ManagerCore\\Services\\EventBus')) {
            return;
        }

        // Route through AlertEventEnvelope so the contract base fields
        // (source_plugin / schema_version / event_id / category_group /
        // eve_time / seconds_until / is_elapsed / url) are uniform with the
        // other 4 structure.alert.* flavors.
        $payload = AlertEventEnvelope::build('fuel_critical', [
            'structure_id'      => (int) $structure->structure_id,
            'corporation_id'    => (int) $structure->corporation_id,
            'type_id'           => (int) $structure->type_id,                // legacy key
            'structure_type_id' => (int) $structure->type_id,                // contract key
            'system_id'         => isset($structure->system_id) ? (int) $structure->system_id : null,
            'structure_name'    => $fuelData['structure_name'] ?? null,
            'system_name'       => $fuelData['system_name'] ?? null,
            'system_security'   => $fuelData['system_security'] ?? null,
            'severity'          => $currentStatus,
            'source_reference'  => 'fuel:' . $structure->structure_id,

            // eve_time = when the structure runs out of fuel
            'eve_time' => $fuelData['fuel_expires'] ?? null,

            // Flavor-specific extras (preserved verbatim — MM reads these)
            'days_remaining'  => (float) ($fuelData['days_remaining'] ?? 0),
            'hours_remaining' => (float) ($fuelData['hours_remaining'] ?? 0),
            'fuel_expires'    => $fuelData['fuel_expires'] ?? null,
            'hourly_rate'     => (float) ($fuelData['hourly_rate'] ?? 0),
        ]);

        try {
            app(\ManagerCore\Services\EventBus::class)->publishSanitized(
                'structure.alert.fuel_critical',
                'structure-manager',
                $payload
            );

            Log::info("NotifyUpwellLowFuel: published structure.alert.fuel_critical for structure {$structure->structure_id} (event_id={$payload['event_id']})");
        } catch (\Throwable $e) {
            Log::warning("NotifyUpwellLowFuel: fuel_critical event publish failed for structure {$structure->structure_id}: " . $e->getMessage());
        }
    }

    /**
     * Publish a `structure.alert.fuel_recovered` event when an Upwell structure's
     * fuel status transitions from warning/critical back to good (i.e. the
     * structure has been refueled). Closes the alert ladder so subscribers can
     * auto-clear their dedup latches without waiting for the next critical fire.
     *
     * Subscribers (Mining Manager, Pings, etc.) can use this to:
     *   - Reset per-structure alert-sent flags
     *   - Send a "structure refueled" follow-up message
     *   - Update calendar / board entries to dismissed state
     *
     * @param object $structure
     * @param array  $fuelData
     * @return void
     */
    private function publishFuelRecoveredEvent($structure, array $fuelData): void
    {
        if (!class_exists('\\ManagerCore\\Services\\EventBus')) {
            return;
        }

        $payload = AlertEventEnvelope::build('fuel_recovered', [
            'structure_id'      => (int) $structure->structure_id,
            'corporation_id'    => (int) $structure->corporation_id,
            'type_id'           => (int) $structure->type_id,                // legacy key
            'structure_type_id' => (int) $structure->type_id,                // contract key
            'system_id'         => isset($structure->system_id) ? (int) $structure->system_id : null,
            'structure_name'    => $fuelData['structure_name'] ?? null,
            'system_name'       => $fuelData['system_name'] ?? null,
            'system_security'   => $fuelData['system_security'] ?? null,
            'severity'          => 'info',                                   // recovery is good news
            'source_reference'  => 'fuel:' . $structure->structure_id,       // matches the critical event so subscribers can correlate

            // eve_time = now (the moment of recovery)
            'eve_time' => Carbon::now(),

            // Flavor-specific extras — the new fuel runway after the refuel
            'days_remaining'  => (float) ($fuelData['days_remaining'] ?? 0),
            'hours_remaining' => (float) ($fuelData['hours_remaining'] ?? 0),
            'fuel_expires'    => $fuelData['fuel_expires'] ?? null,
            'hourly_rate'     => (float) ($fuelData['hourly_rate'] ?? 0),
        ]);

        try {
            app(\ManagerCore\Services\EventBus::class)->publishSanitized(
                'structure.alert.fuel_recovered',
                'structure-manager',
                $payload
            );
            Log::info("NotifyUpwellLowFuel: published structure.alert.fuel_recovered for structure {$structure->structure_id} (event_id={$payload['event_id']})");
        } catch (\Throwable $e) {
            Log::warning("NotifyUpwellLowFuel: fuel_recovered event publish failed for structure {$structure->structure_id}: " . $e->getMessage());
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

        // Race guard: SeAT's corporation_assets refresh is non-atomic
        // (DELETE-then-INSERT, per-corp). A poll landing inside that window
        // can read 0 for the bay's fuel rows even when the structure has
        // plenty of fuel left. For non-Metenox Upwell, the status decision
        // itself comes from $hoursRemaining (cs.fuel_expires based) and is
        // therefore safe, but the embed would render "0 blocks remaining"
        // inside the same race window, which looks like the structure is
        // about to go offline when it isn't. Skip the poll; the next 10-min
        // run picks up the assets table whole. Metenox has its own guard
        // further down (after the gas read) because the asymmetric 0 case
        // is the one that actually drives a false CRITICAL there.
        if (
            $structure->type_id != self::METENOX_TYPE_ID
            && $fuelBlocks == 0
            && $hoursRemaining > 12
        ) {
            Log::warning(sprintf(
                'NotifyUpwellLowFuel: suspected corp-assets refresh race on Upwell %d '
                . '(live fuel=0 but cs.fuel_expires says %.1fh remaining), skipping this poll',
                $structure->structure_id,
                $hoursRemaining
            ));
            return null;
        }

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

            // Race guard: SeAT's corporation_assets refresh is non-atomic
            // (DELETE-then-INSERT, per-corp). A poll inside the window can
            // read 0 for one fuel type while the other reads correctly.
            // A real depletion burns fuel + gas proportionally, so an
            // asymmetric 0 (one resource 0 while the other has many days
            // left) is almost certainly the race rather than the structure
            // being out. This is the case that drove false CRITICAL embeds
            // because the Metenox path below overrides days_remaining from
            // $actualDays = min(fuel, gas) — a race-induced 0 makes
            // actualDays = 0 which then triggers critical. Bail; the next
            // 10-minute poll picks the assets table back up whole.
            $raceSuspicionDays = 7;
            if (
                ($fuelBlocks == 0 && $gasDays > $raceSuspicionDays)
                || ($magmaticGas == 0 && $fuelDays > $raceSuspicionDays)
            ) {
                Log::warning(sprintf(
                    'NotifyUpwellLowFuel: suspected corp-assets refresh race on Metenox %d '
                    . '(fuel=%d, gas=%d, fuelDays=%.1f, gasDays=%.1f), skipping this poll',
                    $structure->structure_id,
                    $fuelBlocks,
                    $magmaticGas,
                    $fuelDays,
                    $gasDays
                ));
                return null;
            }

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

            // Override days/hours/expiry with the LIMITING resource. Without
            // this override, the Structure Board's eve_time and the EventBus
            // payload's fuel_expires would still report the fuel-block
            // expiry (which corporation_structures.fuel_expires reflects).
            // For a Metenox with 5d fuel + 1d gas, the structure actually
            // goes offline in 1 day — not 5 — and every downstream consumer
            // (board, EventBus subscribers, embeds) needs to see that.
            $data['days_remaining'] = round($actualDays, 2);
            $data['hours_remaining'] = round($actualDays * 24, 2);
            $data['fuel_expires'] = Carbon::now()->addHours((int) round($actualDays * 24));
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

        // Stamp [TEST INJECTION] banner when the structure is in the safe test
        // range, so test traffic landing in Discord is unmistakable.
        if (\StructureManager\Services\TestDataGenerator::isTestStructure((int) ($fuelData['structure_id'] ?? 0))) {
            $payload['content'] = "\u{1F9EA} **[TEST INJECTION]** " . ($payload['content'] ?? '');
            if (isset($payload['embeds'][0])) {
                $title = $payload['embeds'][0]['title'] ?? '';
                $payload['embeds'][0]['title'] = '[TEST] ' . $title;
                $existingFooter = $payload['embeds'][0]['footer']['text'] ?? '';
                $payload['embeds'][0]['footer'] = [
                    'text' => 'Structure Manager test injection — ignore for real intel | ' . $existingFooter,
                ];
            }
        }

        // v2.0.0 — route through WebhookDeliveryService for telemetry
        $alertType = $isFinalAlert ? 'FINAL' : $currentStatus;
        $structureLabel = $fuelData['structure_name'] ?? ('structure #' . ($fuelData['structure_id'] ?? '?'));
        \StructureManager\Services\WebhookDeliveryService::sendByUrl(
            $webhookUrl,
            $payload,
            'upwell.fuel',
            "{$alertType} fuel alert — {$structureLabel}"
        );
    }

    /**
     * Build a Discord webhook payload with rich embed fields.
     */
    /**
     * Build the dual-fuel/limiting-factor embed for a test Metenox without
     * touching the DB. Used by the diagnostic page's
     * "Send test Metenox dual-fuel alert" button so admins can preview SM's
     * enriched embed in their test Discord without having to drain the test
     * Metenox's real fuel + waiting for the next cron cycle.
     *
     * Returns true on successful POST to test_webhook_url, false otherwise.
     */
    public static function sendTestMetenoxDualFuelEmbed(?string $limitingFactor = null): bool
    {
        $testStructureId = \StructureManager\Services\TestDataGenerator::STRUCTURE_ID_MIN + 31; // Metenox slot
        $testUrl = (string) StructureManagerSettings::get('test_webhook_url', '');
        if ($testUrl === '' || !WebhookConfiguration::isValidWebhookUrl($testUrl)) {
            Log::warning('NotifyUpwellLowFuel::sendTestMetenoxDualFuelEmbed: no test_webhook_url configured');
            return false;
        }

        // Look up the test Metenox metadata if it exists; fall back to
        // sensible defaults if the test data hasn't been generated yet.
        $structure = DB::table('corporation_structures')
            ->where('structure_id', $testStructureId)
            ->first();

        $structureName = DB::table('universe_structures')
            ->where('structure_id', $testStructureId)
            ->value('name')
            ?? 'TEST - Metenox Moon Drill';
        $systemName = $structure
            ? (DB::table('mapDenormalize')->where('itemID', $structure->system_id)->value('itemName') ?? 'Unknown')
            : 'Test System';
        $systemSecurity = $structure
            ? (float) (DB::table('mapDenormalize')->where('itemID', $structure->system_id)->value('security') ?? 0.0)
            : 0.0;

        // Synthesize a critical dual-fuel scenario:
        //   - 3 days of fuel blocks (5 blocks/hr × 24 × 3 = 360 blocks)
        //   - 1.5 days of magmatic gas (200/hr × 24 × 1.5 = 7200 gas)
        //   → gas is the limiting factor, structure goes offline in 1.5 days
        // limitingFactor argument lets the caller flip this to fuel_blocks if needed.
        $factor = $limitingFactor === 'fuel_blocks' ? 'fuel_blocks' : 'magmatic_gas';
        if ($factor === 'fuel_blocks') {
            $fuelDays = 1.5;
            $gasDays  = 3.0;
            $actualDays = 1.5;
            $fuelBlocks = (int) round(5 * 24 * $fuelDays);
            $magmaticGas = (int) round(200 * 24 * $gasDays);
        } else {
            $fuelDays = 3.0;
            $gasDays  = 1.5;
            $actualDays = 1.5;
            $fuelBlocks = (int) round(5 * 24 * $fuelDays);
            $magmaticGas = (int) round(200 * 24 * $gasDays);
        }

        $fuelData = [
            'structure_id'           => $testStructureId,
            'corporation_id'         => $structure->corporation_id ?? \StructureManager\Services\TestDataGenerator::CORP_ID_MIN,
            'type_id'                => 81826, // Metenox Moon Drill
            'structure_name'         => $structureName,
            'structure_type'         => 'Metenox Moon Drill',
            'system_name'            => $systemName,
            'system_security'        => $systemSecurity,
            'fuel_blocks'            => $fuelBlocks,
            'hours_remaining'        => round($actualDays * 24, 2),
            'days_remaining'         => round($actualDays, 2),
            'hourly_rate'            => 5.0,
            'active_services'        => 1,
            'weekly_requirement'     => 5 * 24 * 7,
            'is_metenox'             => true,
            'fuel_expires'           => Carbon::now()->addDays((int) ceil($actualDays))->toDateTimeString(),
            'magmatic_gas'           => $magmaticGas,
            'fuel_days'              => $fuelDays,
            'gas_days'               => $gasDays,
            'actual_days'            => $actualDays,
            'limiting_factor'        => $factor,
            'weekly_gas_requirement' => 200 * 24 * 7,
        ];

        // Use the same buildDiscordPayload that real notifications use, so
        // the test embed is byte-identical (modulo the [TEST] banner) to
        // what a real critical-fuel Metenox alert would produce.
        $instance = new self();
        $payload = $instance->buildDiscordPayload($fuelData, 'critical', false, '');

        // Stamp the [TEST INJECTION] banner (mirrors sendNotification's hook)
        $payload['content'] = "\u{1F9EA} **[TEST INJECTION]** " . ($payload['content'] ?? '');
        if (isset($payload['embeds'][0])) {
            $payload['embeds'][0]['title'] = '[TEST] ' . ($payload['embeds'][0]['title'] ?? '');
            $existingFooter = $payload['embeds'][0]['footer']['text'] ?? '';
            $payload['embeds'][0]['footer'] = [
                'text' => 'Structure Manager test injection — ignore for real intel | ' . $existingFooter,
            ];
        }

        try {
            Http::connectTimeout(5)->timeout(10)->post($testUrl, $payload);
            Log::info("NotifyUpwellLowFuel::sendTestMetenoxDualFuelEmbed: posted to test webhook (limiting={$factor})");
            return true;
        } catch (\Throwable $e) {
            Log::error('NotifyUpwellLowFuel::sendTestMetenoxDualFuelEmbed: post failed: ' . $e->getMessage());
            return false;
        }
    }

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
