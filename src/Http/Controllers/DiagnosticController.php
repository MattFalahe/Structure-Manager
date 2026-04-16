<?php

namespace StructureManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use StructureManager\Helpers\FuelCalculator;
use StructureManager\Helpers\PosFuelCalculator;
use StructureManager\Models\WebhookConfiguration;
use StructureManager\Models\StarbaseFuelHistory;
use StructureManager\Models\StructureNotificationStatus;

/**
 * Admin-only diagnostic page for Structure Manager.
 *
 * Verifies that the plugin's hardcoded type IDs, SeAT SDE tables, scheduled
 * commands, plugin tables, webhooks, ESI coverage, notification state, and the
 * current user's resolved corp scope are all healthy. Every check is read-only.
 */
class DiagnosticController extends Controller
{
    /**
     * The six artisan commands the plugin registers into SeAT's scheduler.
     * Each paired with its expected cron expression (source of truth = our seeder).
     */
    private const EXPECTED_SCHEDULES = [
        'structure-manager:track-fuel'             => '15 * * * *',
        'structure-manager:analyze-consumption'    => '30 * * * *',
        'structure-manager:track-poses-fuel'       => '*/10 * * * *',
        'structure-manager:analyze-pos-consumption' => '0 1 * * *',
        'structure-manager:notify-pos-fuel'        => '*/10 * * * *',
        'structure-manager:notify-upwell-fuel'      => '*/10 * * * *',
        'structure-manager:cleanup-history'        => '0 3 * * *',
    ];

    /**
     * The eight database tables the plugin owns.
     */
    private const PLUGIN_TABLES = [
        'structure_fuel_history',
        'structure_fuel_reserves',
        'structure_notification_status',
        'starbase_fuel_history',
        'starbase_fuel_reserves',
        'starbase_fuel_consumption',
        'structure_manager_settings',
        'structure_manager_webhooks',
    ];

    /**
     * The SeAT tables (plus SDE imports) the plugin reads from.
     */
    private const REQUIRED_SEAT_TABLES = [
        'corporation_structures',
        'corporation_starbases',
        'corporation_starbase_fuels',
        'corporation_assets',
        'corporation_infos',
        'corporation_divisions',
        'universe_structures',
        'refresh_tokens',
        'character_affiliations',
        // SDE
        'invTypes',
        'invGroups',
        'mapDenormalize',
        'invControlTowerResources',
        'dgmTypeAttributes',
    ];

    /**
     * Render the diagnostic page with all checks executed server-side.
     */
    public function index()
    {
        $checks = [
            'environment'        => $this->checkEnvironment(),
            'required_tables'    => $this->checkRequiredTables(),
            'plugin_tables'      => $this->checkPluginTables(),
            'type_ids'           => $this->checkTypeIds(),
            'schedules'          => $this->checkSchedules(),
            'webhooks'           => $this->checkWebhooks(),
            'esi_coverage'       => $this->checkEsiCoverage(),
            'notification_state'        => $this->checkNotificationState(),
            'upwell_notification_state' => $this->checkUpwellNotificationState(),
            'user_context'              => $this->checkUserContext(),
        ];

        // Summary counts for the header banner
        $summary = $this->summarise($checks);

        // Counts of existing test data so admin can see what's currently in the DB
        $testData = $this->countTestData();

        return view('structure-manager::diagnostic.index', compact('checks', 'summary', 'testData'));
    }

    // ------------------------------------------------------------------
    // Individual checks. Each returns:
    //   ['status' => 'ok'|'warn'|'error'|'info', 'message' => string, 'details' => array]
    // ------------------------------------------------------------------

    /**
     * Plugin + PHP + SeAT environment basics.
     */
    private function checkEnvironment(): array
    {
        $pluginConfig = config('structure-manager');
        $details = [
            'Plugin version'   => $pluginConfig['version'] ?? 'unknown',
            'PHP version'      => PHP_VERSION,
            'Laravel version'  => app()->version(),
            'App timezone'     => config('app.timezone'),
            'DB timezone (now)' => $this->dbTimezone(),
            'Debug mode'       => config('app.debug') ? 'ON' : 'off',
            'Queue driver'     => config('queue.default'),
        ];

        return [
            'status'  => 'info',
            'message' => 'Structure Manager ' . ($pluginConfig['version'] ?? '?') . ' on PHP ' . PHP_VERSION,
            'details' => $details,
        ];
    }

    /**
     * Probe the SeAT core tables and SDE tables the plugin depends on.
     * A missing SDE table typically means the SDE import never completed.
     */
    private function checkRequiredTables(): array
    {
        $missing = [];
        foreach (self::REQUIRED_SEAT_TABLES as $table) {
            if (!Schema::hasTable($table)) {
                $missing[] = $table;
            }
        }

        if (empty($missing)) {
            return [
                'status'  => 'ok',
                'message' => 'All ' . count(self::REQUIRED_SEAT_TABLES) . ' required SeAT/SDE tables are present.',
                'details' => self::REQUIRED_SEAT_TABLES,
            ];
        }

        return [
            'status'  => 'error',
            'message' => count($missing) . ' required SeAT/SDE table(s) missing. Plugin cannot function.',
            'details' => ['Missing' => $missing],
        ];
    }

    /**
     * Probe the plugin's own tables and report row counts + freshness.
     */
    private function checkPluginTables(): array
    {
        $rows = [];
        $missing = [];

        foreach (self::PLUGIN_TABLES as $table) {
            if (!Schema::hasTable($table)) {
                $missing[] = $table;
                $rows[$table] = ['exists' => false];
                continue;
            }

            $count = DB::table($table)->count();
            $info = ['exists' => true, 'rows' => $count];

            if (Schema::hasColumn($table, 'created_at') && $count > 0) {
                $info['newest'] = DB::table($table)->max('created_at');
                $info['oldest'] = DB::table($table)->min('created_at');
            }

            $rows[$table] = $info;
        }

        if (!empty($missing)) {
            return [
                'status'  => 'error',
                'message' => count($missing) . ' plugin table(s) missing. Migrations have not been run.',
                'details' => $rows,
            ];
        }

        return [
            'status'  => 'ok',
            'message' => 'All ' . count(self::PLUGIN_TABLES) . ' plugin tables present.',
            'details' => $rows,
        ];
    }

    /**
     * Verify every hardcoded type ID in the plugin exists in the SDE and carries
     * the name the plugin expects. Groups results by category so issues are easy
     * to spot. This is the "we just caught Tenebrex" style check.
     */
    private function checkTypeIds(): array
    {
        if (!Schema::hasTable('invTypes')) {
            return [
                'status'  => 'error',
                'message' => 'invTypes table missing - cannot validate type IDs.',
                'details' => [],
            ];
        }

        // Pull all hardcoded IDs grouped by category so we can display a clean table.
        $groups = [
            'Fuel Blocks' => array_map(
                fn($id, $expected) => ['id' => $id, 'expected' => $expected],
                array_keys(PosFuelCalculator::FUEL_BLOCKS),
                array_values(PosFuelCalculator::FUEL_BLOCKS)
            ),
            'Strontium & Magmatic Gas' => [
                ['id' => PosFuelCalculator::STRONTIUM,           'expected' => 'Strontium Clathrates'],
                ['id' => FuelCalculator::MAGMATIC_GAS_TYPE_ID,   'expected' => 'Magmatic Gas'],
            ],
            'Starbase Charters' => array_map(
                fn($id, $expected) => ['id' => $id, 'expected' => $expected],
                array_keys(PosFuelCalculator::CHARTER_TYPES),
                array_values(PosFuelCalculator::CHARTER_TYPES)
            ),
            'Upwell Structures' => $this->upwellStructureExpectations(),
            'Control Towers' => array_map(
                fn($id, $mod) => ['id' => $id, 'expected' => null, 'modifier' => $mod],
                array_keys(PosFuelCalculator::FACTION_FUEL_MODIFIERS),
                array_values(PosFuelCalculator::FACTION_FUEL_MODIFIERS)
            ),
        ];

        // Collect all IDs into one query for efficiency
        $allIds = [];
        foreach ($groups as $items) {
            foreach ($items as $item) {
                $allIds[] = $item['id'];
            }
        }

        $sdeRows = DB::table('invTypes')
            ->whereIn('typeID', $allIds)
            ->get(['typeID', 'typeName', 'groupID', 'published'])
            ->keyBy('typeID');

        $results = [];
        $errors = 0;
        $warnings = 0;

        foreach ($groups as $groupName => $items) {
            $groupResults = [];
            foreach ($items as $item) {
                $id = $item['id'];
                $sde = $sdeRows->get($id);

                if (!$sde) {
                    $groupResults[] = [
                        'id'       => $id,
                        'expected' => $item['expected'] ?? '(any)',
                        'actual'   => null,
                        'status'   => 'error',
                        'note'     => 'Not found in SDE',
                    ];
                    $errors++;
                    continue;
                }

                // Flag unpublished types — usually means the plugin is pointing at
                // a retired/debug/test type.
                $note = null;
                $status = 'ok';

                if (!$sde->published) {
                    $status = 'warn';
                    $note = 'UNPUBLISHED in SDE (probably retired or test type)';
                    $warnings++;
                }

                // For items with an expected name, compare. Label mismatches are
                // usually cosmetic (CCP renames happen), so "warn" not "error".
                if (!empty($item['expected']) && strcasecmp($item['expected'], $sde->typeName) !== 0) {
                    if ($status === 'ok') {
                        $status = 'warn';
                        $note = 'Name mismatch';
                        $warnings++;
                    }
                }

                $groupResults[] = [
                    'id'       => $id,
                    'expected' => $item['expected'] ?? '(any)',
                    'actual'   => $sde->typeName,
                    'group_id' => $sde->groupID,
                    'status'   => $status,
                    'note'     => $note,
                    'modifier' => $item['modifier'] ?? null,
                ];
            }
            $results[$groupName] = $groupResults;
        }

        // Also check: any PUBLISHED control tower in SDE the plugin does not know about.
        $unknownTowers = DB::table('invTypes')
            ->where('groupID', 365)
            ->where('published', 1)
            ->whereNotIn('typeID', array_keys(PosFuelCalculator::FACTION_FUEL_MODIFIERS))
            ->get(['typeID', 'typeName']);

        if ($unknownTowers->count() > 0) {
            $warnings++;
            $results['Unknown Control Towers'] = $unknownTowers
                ->map(fn($row) => [
                    'id'       => $row->typeID,
                    'expected' => '(missing from plugin)',
                    'actual'   => $row->typeName,
                    'status'   => 'warn',
                    'note'     => 'Group 365 tower not in FACTION_FUEL_MODIFIERS; would silently use x1.0 modifier',
                ])
                ->toArray();
        }

        $totalChecked = count($allIds);

        if ($errors > 0) {
            return [
                'status'  => 'error',
                'message' => "Checked {$totalChecked} type IDs: {$errors} error(s), {$warnings} warning(s).",
                'details' => $results,
            ];
        }
        if ($warnings > 0) {
            return [
                'status'  => 'warn',
                'message' => "Checked {$totalChecked} type IDs: all found, {$warnings} minor warning(s).",
                'details' => $results,
            ];
        }
        return [
            'status'  => 'ok',
            'message' => "Checked {$totalChecked} type IDs: all match SDE.",
            'details' => $results,
        ];
    }

    /**
     * Confirm every plugin-registered schedule is present with the expected cron
     * expression, and report how long since each last fired.
     */
    private function checkSchedules(): array
    {
        if (!Schema::hasTable('schedules')) {
            return [
                'status'  => 'error',
                'message' => 'SeAT schedules table missing.',
                'details' => [],
            ];
        }

        $rows = DB::table('schedules')
            ->whereIn('command', array_keys(self::EXPECTED_SCHEDULES))
            ->get()
            ->keyBy('command');

        $results = [];
        $errors = 0;
        $warnings = 0;

        foreach (self::EXPECTED_SCHEDULES as $command => $expectedExpr) {
            $row = $rows->get($command);
            if (!$row) {
                $results[] = [
                    'command'    => $command,
                    'expected'   => $expectedExpr,
                    'actual'     => null,
                    'status'     => 'error',
                    'note'       => 'Not registered in scheduler',
                ];
                $errors++;
                continue;
            }

            $status = 'ok';
            $note = null;

            if ($row->expression !== $expectedExpr) {
                $status = 'warn';
                $note = "Expression differs from seeder default ({$expectedExpr})";
                $warnings++;
            }

            $results[] = [
                'command'    => $command,
                'expected'   => $expectedExpr,
                'actual'     => $row->expression,
                'last_run'   => $row->last_run_at ?? null,
                'next_run'   => $row->next_run_at ?? null,
                'overlap'    => (bool) ($row->allow_overlap ?? false),
                'status'     => $status,
                'note'       => $note,
            ];
        }

        if ($errors > 0) {
            return [
                'status'  => 'error',
                'message' => "{$errors} schedule(s) missing. Run the plugin's migrations / seeders.",
                'details' => $results,
            ];
        }
        if ($warnings > 0) {
            return [
                'status'  => 'warn',
                'message' => "{$warnings} schedule(s) have modified cron expressions.",
                'details' => $results,
            ];
        }
        return [
            'status'  => 'ok',
            'message' => 'All ' . count(self::EXPECTED_SCHEDULES) . ' schedules present with expected cron expressions.',
            'details' => $results,
        ];
    }

    /**
     * Validate stored webhook URLs against the current allowlist.
     * This is the check that catches DB rows that passed an older, weaker
     * validator but would be rejected by the tightened allowlist.
     */
    private function checkWebhooks(): array
    {
        $all = WebhookConfiguration::all();

        if ($all->isEmpty()) {
            return [
                'status'  => 'info',
                'message' => 'No webhooks configured. POS notifications are disabled.',
                'details' => [],
            ];
        }

        $rows = [];
        $invalid = 0;
        $disabled = 0;

        foreach ($all as $webhook) {
            $urlValid = WebhookConfiguration::isValidWebhookUrl($webhook->webhook_url);
            $urlForDisplay = $this->maskWebhookUrl($webhook->webhook_url);

            if (!$urlValid) {
                $invalid++;
            }
            if (!$webhook->enabled) {
                $disabled++;
            }

            $rows[] = [
                'id'             => $webhook->id,
                'url_masked'     => $urlForDisplay,
                'corporation_id' => $webhook->corporation_id,
                'corporation'    => $webhook->getCorporationLabel(),
                'enabled'        => (bool) $webhook->enabled,
                'description'    => $webhook->description,
                'role_mention'   => $webhook->role_mention,
                'url_valid'      => $urlValid,
                'status'         => !$urlValid ? 'error' : (!$webhook->enabled ? 'warn' : 'ok'),
                'note'           => !$urlValid
                    ? 'URL fails current allowlist (non-https, non-Discord/Slack, or malformed)'
                    : (!$webhook->enabled ? 'Disabled - will not send notifications' : null),
            ];
        }

        if ($invalid > 0) {
            return [
                'status'  => 'error',
                'message' => "{$invalid} webhook URL(s) fail current validation. Edit and re-save them.",
                'details' => $rows,
            ];
        }
        if ($disabled === $all->count()) {
            return [
                'status'  => 'warn',
                'message' => 'All webhooks are disabled. Notifications suppressed.',
                'details' => $rows,
            ];
        }

        return [
            'status'  => 'ok',
            'message' => $all->count() . ' webhook(s) configured, ' . ($all->count() - $disabled) . ' enabled.',
            'details' => $rows,
        ];
    }

    /**
     * Per-corporation stats on how many structures/POSes SeAT knows about vs how
     * many have at least one tracking history row, plus per-corp "newest tracked
     * run" so you can tell when one corp's ESI token has silently stopped returning
     * data.
     */
    private function checkEsiCoverage(): array
    {
        $rows = [];

        // Upwell structures per corp
        $structuresPerCorp = DB::table('corporation_structures as cs')
            ->join('corporation_infos as ci', 'cs.corporation_id', '=', 'ci.corporation_id')
            ->select(
                'cs.corporation_id',
                'ci.name as corporation_name',
                DB::raw('COUNT(*) as structure_count'),
                DB::raw('SUM(CASE WHEN cs.fuel_expires IS NULL THEN 1 ELSE 0 END) as null_fuel_expires')
            )
            ->groupBy('cs.corporation_id', 'ci.name')
            ->get()
            ->keyBy('corporation_id');

        $trackedStructures = DB::table('structure_fuel_history')
            ->select(
                'corporation_id',
                DB::raw('COUNT(DISTINCT structure_id) as tracked'),
                DB::raw('MAX(created_at) as last_tracked_at')
            )
            ->groupBy('corporation_id')
            ->get()
            ->keyBy('corporation_id');

        // POSes per corp
        $posesPerCorp = DB::table('corporation_starbases')
            ->select('corporation_id', DB::raw('COUNT(*) as pos_count'))
            ->groupBy('corporation_id')
            ->get()
            ->keyBy('corporation_id');

        $trackedPoses = DB::table('starbase_fuel_history')
            ->select(
                'corporation_id',
                DB::raw('COUNT(DISTINCT starbase_id) as tracked'),
                DB::raw('MAX(created_at) as last_tracked_at')
            )
            ->groupBy('corporation_id')
            ->get()
            ->keyBy('corporation_id');

        // Union of all corp IDs in any of the above
        $allCorpIds = $structuresPerCorp->keys()
            ->merge($trackedStructures->keys())
            ->merge($posesPerCorp->keys())
            ->merge($trackedPoses->keys())
            ->unique()
            ->values();

        $corpNames = DB::table('corporation_infos')
            ->whereIn('corporation_id', $allCorpIds)
            ->pluck('name', 'corporation_id');

        $warnings = 0;
        foreach ($allCorpIds as $corpId) {
            $structures = $structuresPerCorp->get($corpId);
            $tracked = $trackedStructures->get($corpId);
            $poses = $posesPerCorp->get($corpId);
            $trackedPos = $trackedPoses->get($corpId);

            $structureTotal = $structures->structure_count ?? 0;
            $structureTracked = $tracked->tracked ?? 0;
            $posTotal = $poses->pos_count ?? 0;
            $posTracked = $trackedPos->tracked ?? 0;

            $status = 'ok';
            $notes = [];

            if ($structureTotal > 0 && $structureTracked < $structureTotal) {
                $status = 'warn';
                $notes[] = 'Only ' . $structureTracked . '/' . $structureTotal . ' structures tracked';
                $warnings++;
            }
            if ($posTotal > 0 && $posTracked < $posTotal) {
                $status = 'warn';
                $notes[] = 'Only ' . $posTracked . '/' . $posTotal . ' POSes tracked';
                $warnings++;
            }

            $rows[] = [
                'corporation_id'           => $corpId,
                'corporation_name'         => $corpNames[$corpId] ?? "Corp #{$corpId}",
                'structures_total'         => $structureTotal,
                'structures_tracked'       => $structureTracked,
                'structures_null_fuel'     => $structures->null_fuel_expires ?? 0,
                'structures_last_tracked'  => $tracked->last_tracked_at ?? null,
                'poses_total'              => $posTotal,
                'poses_tracked'            => $posTracked,
                'poses_last_tracked'       => $trackedPos->last_tracked_at ?? null,
                'status'                   => $status,
                'note'                     => empty($notes) ? null : implode('; ', $notes),
            ];
        }

        if ($warnings > 0) {
            return [
                'status'  => 'warn',
                'message' => $warnings . ' coverage gap(s) across ' . count($rows) . ' corporation(s). See per-corp detail.',
                'details' => $rows,
            ];
        }

        return [
            'status'  => 'ok',
            'message' => count($rows) . ' corporation(s); tracking coverage is complete.',
            'details' => $rows,
        ];
    }

    /**
     * Notification-state sanity: POSes currently in critical, POSes sitting with
     * final_alert_sent still latched despite being in good status (shouldn't
     * happen after the recovery-reset fix, but this catches legacy rows), and
     * POSes with no fuel history at all.
     */
    private function checkNotificationState(): array
    {
        // Latest row per POS using the same MAX(id) pattern the jobs use
        $latest = StarbaseFuelHistory::whereIn('id', function ($q) {
            $q->select(DB::raw('MAX(id)'))
                ->from('starbase_fuel_history')
                ->groupBy('starbase_id');
        })->get();

        $critical = 0;
        $warning = 0;
        $stuckLatch = 0;
        $onlineReinforced = 0;

        $stuckDetails = [];

        foreach ($latest as $row) {
            // Only count currently-online or reinforced POSes
            if (!in_array($row->state, [3, 4], true)) {
                continue;
            }
            $onlineReinforced++;

            $daysRemaining = $row->actual_days_remaining ?? $row->fuel_days_remaining;

            if ($daysRemaining !== null && $daysRemaining < 7) {
                $critical++;
            } elseif ($daysRemaining !== null && $daysRemaining < 14) {
                $warning++;
            }

            // Stuck latch detection: latch set true but fuel is currently good (>= 14d).
            $fuelGood = $daysRemaining !== null && $daysRemaining >= 14;
            if ($fuelGood && ($row->fuel_final_alert_sent ?? false)) {
                $stuckLatch++;
                $stuckDetails[] = [
                    'starbase_id'   => $row->starbase_id,
                    'starbase_name' => $row->starbase_name ?? ('POS-' . $row->starbase_id),
                    'fuel_days'     => round($daysRemaining ?? 0, 2),
                    'latch_type'    => 'fuel_final_alert_sent',
                    'note'          => 'Latch still set despite recovery - will clear on next notify run',
                ];
            }

            if (($row->strontium_hours_available ?? 0) >= 24 && ($row->strontium_final_alert_sent ?? false)) {
                $stuckLatch++;
                $stuckDetails[] = [
                    'starbase_id'   => $row->starbase_id,
                    'starbase_name' => $row->starbase_name ?? ('POS-' . $row->starbase_id),
                    'stront_hours'  => round($row->strontium_hours_available ?? 0, 2),
                    'latch_type'    => 'strontium_final_alert_sent',
                    'note'          => 'Latch still set despite recovery - will clear on next notify run',
                ];
            }
        }

        // POSes in corporation_starbases with no history at all
        $noHistory = DB::table('corporation_starbases as cs')
            ->leftJoin('starbase_fuel_history as sfh', 'cs.starbase_id', '=', 'sfh.starbase_id')
            ->whereNull('sfh.id')
            ->count();

        $details = [
            'Online/reinforced POSes'        => $onlineReinforced,
            'Currently in CRITICAL'          => $critical,
            'Currently in WARNING'           => $warning,
            'Stuck final-alert latches'      => $stuckLatch,
            'POSes with no history'          => $noHistory,
        ];
        if (!empty($stuckDetails)) {
            $details['Stuck latches detail'] = $stuckDetails;
        }

        $status = 'ok';
        if ($critical > 0) {
            $status = 'warn'; // informational - not an error, but admin should know
        }
        if ($noHistory > 0 || $stuckLatch > 0) {
            $status = 'warn';
        }

        return [
            'status'  => $status,
            'message' => "{$onlineReinforced} online/reinforced POS(es) - {$critical} critical, {$warning} warning.",
            'details' => $details,
        ];
    }

    /**
     * Upwell structure notification state: how many are fueled, critical/warning
     * counts, stuck latches, structures with no notification tracking row.
     */
    private function checkUpwellNotificationState(): array
    {
        if (!Schema::hasTable('structure_notification_status') || !Schema::hasTable('corporation_structures')) {
            return [
                'status'  => 'info',
                'message' => 'Upwell notification table not yet created (run migrations).',
                'details' => [],
            ];
        }

        $fueledStructures = DB::table('corporation_structures')
            ->whereNotNull('fuel_expires')
            ->count();

        $trackedStructures = StructureNotificationStatus::count();

        $criticalDays = (int) \StructureManager\Models\StructureManagerSettings::get('upwell_fuel_critical_days', 7);
        $warningDays = (int) \StructureManager\Models\StructureManagerSettings::get('upwell_fuel_warning_days', 14);

        // Count structures currently below thresholds
        $critical = DB::table('corporation_structures')
            ->whereNotNull('fuel_expires')
            ->whereRaw('TIMESTAMPDIFF(HOUR, NOW(), fuel_expires) < ?', [$criticalDays * 24])
            ->count();

        $warning = DB::table('corporation_structures')
            ->whereNotNull('fuel_expires')
            ->whereRaw('TIMESTAMPDIFF(HOUR, NOW(), fuel_expires) BETWEEN ? AND ?', [$criticalDays * 24, $warningDays * 24])
            ->count();

        // Stuck latches: latch set true but structure is above warning threshold
        $stuckLatches = StructureNotificationStatus::where('fuel_final_alert_sent', true)
            ->whereHas(null) // can't use relationship here, just count via join
            ->count();

        // More accurate: join to find stuck latches
        $stuckLatches = DB::table('structure_notification_status as sns')
            ->join('corporation_structures as cs', 'sns.structure_id', '=', 'cs.structure_id')
            ->where('sns.fuel_final_alert_sent', true)
            ->whereNotNull('cs.fuel_expires')
            ->whereRaw('TIMESTAMPDIFF(HOUR, NOW(), cs.fuel_expires) >= ?', [$warningDays * 24])
            ->count();

        $details = [
            'Fueled Upwell structures'    => $fueledStructures,
            'With notification tracking'  => $trackedStructures,
            'Currently in CRITICAL'       => $critical,
            'Currently in WARNING'        => $warning,
            'Stuck final-alert latches'   => $stuckLatches,
        ];

        $status = 'ok';
        if ($critical > 0) {
            $status = 'warn';
        }
        if ($stuckLatches > 0) {
            $status = 'warn';
        }

        return [
            'status'  => $status,
            'message' => "{$fueledStructures} fueled Upwell structure(s) - {$critical} critical, {$warning} warning.",
            'details' => $details,
        ];
    }

    /**
     * What the plugin sees for the currently-logged-in admin. Helps diagnose
     * "why can I only see N corps" by showing exactly what the scope resolver
     * would return for this user.
     */
    private function checkUserContext(): array
    {
        $user = auth()->user();
        if (!$user) {
            return [
                'status'  => 'error',
                'message' => 'No authenticated user (unexpected on an admin route).',
                'details' => [],
            ];
        }

        $hasView = $user->can('structure-manager.view');
        $hasAdmin = $user->can('structure-manager.admin');

        $corpRows = DB::table('refresh_tokens')
            ->join('character_affiliations', 'refresh_tokens.character_id', '=', 'character_affiliations.character_id')
            ->leftJoin('corporation_infos', 'character_affiliations.corporation_id', '=', 'corporation_infos.corporation_id')
            ->where('refresh_tokens.user_id', $user->id)
            ->whereNull('refresh_tokens.deleted_at')
            ->select(
                'refresh_tokens.character_id',
                'character_affiliations.corporation_id',
                'corporation_infos.name as corporation_name'
            )
            ->distinct()
            ->get();

        $resolvedCorpIds = $corpRows->pluck('corporation_id')->unique()->values()->all();

        $details = [
            'User ID'              => $user->id,
            'has structure-manager.view'  => $hasView,
            'has structure-manager.admin' => $hasAdmin,
            'Linked characters'    => $corpRows->count(),
            'Resolved corporations' => $corpRows
                ->unique('corporation_id')
                ->map(fn($r) => ($r->corporation_name ?? 'Corp #' . $r->corporation_id) . ' (' . $r->corporation_id . ')')
                ->values()
                ->all(),
            'getUserCorporations() would return' => $hasAdmin
                ? 'null (admin = see all corporations)'
                : (empty($resolvedCorpIds) ? '[] (no access)' : '[' . count($resolvedCorpIds) . ' corp(s)]'),
        ];

        $status = 'info';
        $message = $hasAdmin
            ? 'Admin: unrestricted cross-corporation access.'
            : 'Scoped to ' . count($resolvedCorpIds) . ' corporation(s) via ' . $corpRows->count() . ' linked character(s).';

        if (!$hasView && !$hasAdmin) {
            $status = 'warn';
            $message = 'User has neither view nor admin permission.';
        }

        return [
            'status'  => $status,
            'message' => $message,
            'details' => $details,
        ];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Build the Upwell structure expectations array from FuelCalculator's
     * STRUCTURE_TYPES const. Names here are the plugin's internal labels; the SDE
     * may use slightly different typeNames (see label mismatch notes above).
     */
    private function upwellStructureExpectations(): array
    {
        $out = [];
        foreach (FuelCalculator::STRUCTURE_TYPES as $id => $info) {
            $out[] = ['id' => $id, 'expected' => $info['name']];
        }
        return $out;
    }

    /**
     * Strip the webhook token from a Discord/Slack URL so we can display a useful
     * chunk without exposing the secret. Discord URLs look like:
     *   https://discord.com/api/webhooks/{id}/{token}
     * We show the ID but mask the token.
     */
    private function maskWebhookUrl(?string $url): string
    {
        if ($url === null || $url === '') {
            return '(empty)';
        }
        // Match Discord webhook token
        if (preg_match('~^(https://[^/]+/api/webhooks/\d+/)([A-Za-z0-9_-]+)(.*)$~', $url, $m)) {
            return $m[1] . str_repeat('*', max(8, strlen($m[2]) - 4)) . substr($m[2], -4) . $m[3];
        }
        // Slack webhooks: show the host + masked path
        if (preg_match('~^(https://[^/]+)(/.*)$~', $url, $m)) {
            return $m[1] . '/' . str_repeat('*', 8) . substr($m[2], -6);
        }
        return '(unrecognised format)';
    }

    /**
     * Return the DB server's current time so admins can see if it drifted.
     */
    private function dbTimezone(): string
    {
        try {
            $row = DB::selectOne('SELECT @@global.time_zone as gtz, @@session.time_zone as stz, NOW() as now_ts');
            return "now={$row->now_ts} (session tz: {$row->stz}, global tz: {$row->gtz})";
        } catch (\Throwable $e) {
            return 'unavailable: ' . $e->getMessage();
        }
    }

    /**
     * Aggregate statuses across all checks for the top-of-page banner.
     */
    private function summarise(array $checks): array
    {
        $counts = ['ok' => 0, 'warn' => 0, 'error' => 0, 'info' => 0];
        foreach ($checks as $c) {
            $status = $c['status'] ?? 'info';
            if (!isset($counts[$status])) {
                $status = 'info';
            }
            $counts[$status]++;
        }

        $overall = 'ok';
        if ($counts['error'] > 0) {
            $overall = 'error';
        } elseif ($counts['warn'] > 0) {
            $overall = 'warn';
        }

        return [
            'overall' => $overall,
            'counts'  => $counts,
            'total'   => count($checks),
        ];
    }

    // ==================================================================
    // Test data generation endpoints (DEV-ONLY).
    //
    // These wrap the existing artisan commands so admins can trigger them
    // from the UI without shell access. They INSERT fake rows directly
    // into corporation_infos, corporation_starbases, corporation_structures
    // and related tables. Every endpoint requires structure-manager.admin
    // at the route level AND requires a confirm=yes form field at the body
    // level as a deliberate two-step guard.
    // ==================================================================

    /**
     * Count existing test rows so the view can show current state before the
     * admin decides to generate more.
     *
     * Detection rules match the IDs the test commands actually insert:
     *   - Test corporations: names starting with "Test Corporation"
     *     (see CreateTestPoses::$corpNames)
     *   - Test POSes: starbase_id >= 2_100_000_000 (CreateTestPoses range)
     *   - Test Metenox/Astrahus: structure IDs 9_999_999_998 / 9_999_999_999
     *     (see CreateTestMetenoxCommand)
     */
    private function countTestData(): array
    {
        $testCorps = 0;
        if (Schema::hasTable('corporation_infos')) {
            $testCorps = DB::table('corporation_infos')
                ->where('name', 'LIKE', 'Test Corporation%')
                ->count();
        }

        $testPoses = 0;
        if (Schema::hasTable('corporation_starbases')) {
            $testPoses = DB::table('corporation_starbases')
                ->where('starbase_id', '>=', 2100000000)
                ->count();
        }

        $testPosHistory = 0;
        if (Schema::hasTable('starbase_fuel_history')) {
            $testPosHistory = DB::table('starbase_fuel_history')
                ->where('starbase_id', '>=', 2100000000)
                ->count();
        }

        $testMetenoxStructures = 0;
        if (Schema::hasTable('corporation_structures')) {
            $testMetenoxStructures = DB::table('corporation_structures')
                ->whereIn('structure_id', [9999999998, 9999999999])
                ->count();
        }

        $testMetenoxHistory = 0;
        if (Schema::hasTable('structure_fuel_history')) {
            $testMetenoxHistory = DB::table('structure_fuel_history')
                ->whereIn('structure_id', [9999999998, 9999999999])
                ->count();
        }

        return [
            'test_corporations'       => $testCorps,
            'test_poses'              => $testPoses,
            'test_pos_history_rows'   => $testPosHistory,
            'test_metenox_structures' => $testMetenoxStructures,
            'test_metenox_history'    => $testMetenoxHistory,
            'any_present'             => $testCorps > 0
                                         || $testPoses > 0
                                         || $testMetenoxStructures > 0,
        ];
    }

    /**
     * Create test POSes. Wraps `structure-manager:create-test-poses`.
     * Admin must check the confirmation box on the form before this fires.
     */
    public function generateTestPoses(Request $request)
    {
        if (!$this->confirmed($request)) {
            return back()->with('error', 'Test-data generation requires the confirmation checkbox. No changes made.');
        }

        $request->validate([
            'corporations'   => 'nullable|integer|min:1|max:10',
            'poses_per_corp' => 'nullable|integer|min:1|max:10',
        ]);

        try {
            Artisan::call('structure-manager:create-test-poses', [
                '--corporations'   => $request->input('corporations', 3),
                '--poses-per-corp' => $request->input('poses_per_corp', 2),
            ]);
            $output = Artisan::output();
            Log::info('Structure Manager diagnostic: generated test POSes', ['output_lines' => substr_count($output, "\n")]);

            return back()->with('success', 'Test POSes generated. See the Test Data panel for current counts.');
        } catch (\Throwable $e) {
            Log::error('Structure Manager diagnostic: test POS generation failed - ' . $e->getMessage());
            return back()->with('error', 'Test POS generation failed: ' . $e->getMessage());
        }
    }

    /**
     * Create test Metenox + Astrahus for dual-fuel tracking tests.
     * Wraps `structure-manager:create-test-metenox`.
     */
    public function generateTestMetenox(Request $request)
    {
        if (!$this->confirmed($request)) {
            return back()->with('error', 'Test-data generation requires the confirmation checkbox. No changes made.');
        }

        try {
            Artisan::call('structure-manager:create-test-metenox');
            Log::info('Structure Manager diagnostic: generated test Metenox structures');
            return back()->with('success', 'Test Metenox + Astrahus structures generated.');
        } catch (\Throwable $e) {
            Log::error('Structure Manager diagnostic: test Metenox generation failed - ' . $e->getMessage());
            return back()->with('error', 'Test Metenox generation failed: ' . $e->getMessage());
        }
    }

    /**
     * Advance a fake consumption cycle to exercise the refuel-detection and
     * notification code paths without waiting for real time to pass.
     * Wraps `structure-manager:simulate-consumption --test-only`.
     */
    public function simulateConsumption(Request $request)
    {
        if (!$this->confirmed($request)) {
            return back()->with('error', 'Simulation requires the confirmation checkbox. No changes made.');
        }

        $request->validate([
            'cycles' => 'nullable|integer|min:1|max:20',
        ]);

        try {
            Artisan::call('structure-manager:simulate-consumption', [
                '--cycles'    => $request->input('cycles', 1),
                '--test-only' => true,
            ]);
            Log::info('Structure Manager diagnostic: simulated consumption cycles');
            return back()->with('success', 'Simulated ' . $request->input('cycles', 1) . ' consumption cycle(s) on test POSes.');
        } catch (\Throwable $e) {
            Log::error('Structure Manager diagnostic: simulate-consumption failed - ' . $e->getMessage());
            return back()->with('error', 'Simulation failed: ' . $e->getMessage());
        }
    }

    /**
     * Remove all test data the plugin knows how to create.
     * Wraps the two cleanup paths (`--cleanup` on both create- commands).
     */
    public function cleanupTestData(Request $request)
    {
        if (!$this->confirmed($request)) {
            return back()->with('error', 'Cleanup requires the confirmation checkbox. No changes made.');
        }

        $errors = [];
        try {
            Artisan::call('structure-manager:create-test-poses', ['--cleanup' => true]);
        } catch (\Throwable $e) {
            $errors[] = 'POS cleanup: ' . $e->getMessage();
        }
        try {
            Artisan::call('structure-manager:create-test-metenox', ['--cleanup' => true]);
        } catch (\Throwable $e) {
            $errors[] = 'Metenox cleanup: ' . $e->getMessage();
        }

        if (!empty($errors)) {
            Log::warning('Structure Manager diagnostic: test cleanup had errors', ['errors' => $errors]);
            return back()->with('error', 'Cleanup finished with errors: ' . implode(' | ', $errors));
        }

        Log::info('Structure Manager diagnostic: cleaned up test data');
        return back()->with('success', 'All test data cleaned up.');
    }

    // ==================================================================
    // Notification testing endpoints
    // ==================================================================

    /**
     * Dispatch the real NotifyUpwellLowFuel job so it processes actual
     * structures and sends real notifications for any below thresholds.
     */
    public function runUpwellNotificationCheck(Request $request)
    {
        if (!$this->confirmed($request)) {
            return back()->with('error', 'Notification check requires the confirmation checkbox.');
        }

        try {
            Artisan::call('structure-manager:notify-upwell-fuel');
            Log::info('Structure Manager diagnostic: dispatched Upwell notification check');
            return back()->with('success', 'Upwell notification job dispatched. Any structures below your thresholds will receive alerts on configured webhooks.');
        } catch (\Throwable $e) {
            Log::error('Structure Manager diagnostic: Upwell notification check failed - ' . $e->getMessage());
            return back()->with('error', 'Upwell notification check failed: ' . $e->getMessage());
        }
    }

    /**
     * Dispatch the real NotifyPosLowFuel job so it processes actual POSes
     * and sends real notifications for any below thresholds.
     */
    public function runPosNotificationCheck(Request $request)
    {
        if (!$this->confirmed($request)) {
            return back()->with('error', 'Notification check requires the confirmation checkbox.');
        }

        try {
            Artisan::call('structure-manager:notify-pos-fuel');
            Log::info('Structure Manager diagnostic: dispatched POS notification check');
            return back()->with('success', 'POS notification job dispatched. Any POSes below your thresholds will receive alerts on configured webhooks.');
        } catch (\Throwable $e) {
            Log::error('Structure Manager diagnostic: POS notification check failed - ' . $e->getMessage());
            return back()->with('error', 'POS notification check failed: ' . $e->getMessage());
        }
    }

    /**
     * Send a sample Upwell fuel alert to a specific webhook so the admin
     * can preview embed formatting without needing a real low-fuel structure.
     */
    public function sendTestUpwellAlert(Request $request)
    {
        $request->validate([
            'webhook_id' => 'required|integer',
        ]);

        $webhook = WebhookConfiguration::find($request->webhook_id);
        if (!$webhook) {
            return back()->with('error', 'Webhook not found.');
        }

        if (!WebhookConfiguration::isValidWebhookUrl($webhook->webhook_url)) {
            return back()->with('error', 'Webhook URL fails validation. Edit and re-save it in Settings.');
        }

        // Build a realistic sample embed
        $payload = $this->buildSampleUpwellPayload($webhook->role_mention ?? '');

        try {
            $response = Http::connectTimeout(5)->timeout(10)->post($webhook->webhook_url, $payload);

            if ($response->successful()) {
                return back()->with('success', 'Sample Upwell fuel alert sent to webhook #' . $webhook->id . '. Check your Discord/Slack channel.');
            } else {
                return back()->with('error', 'Webhook returned HTTP ' . $response->status() . '. Check the URL.');
            }
        } catch (\Throwable $e) {
            return back()->with('error', 'Failed to send test alert: ' . $e->getMessage());
        }
    }

    /**
     * Build a realistic sample payload that looks like a real Upwell fuel alert
     * so admins can preview the embed format.
     */
    private function buildSampleUpwellPayload(string $roleMention): array
    {
        $content = '';
        $allowedMentions = ['parse' => [], 'users' => [], 'roles' => []];

        if (!empty($roleMention)) {
            $mention = trim($roleMention);
            if (preg_match('/^<@&(\d+)>$/', $mention, $m)) {
                $content = "<@&{$m[1]}> ";
                $allowedMentions['roles'][] = $m[1];
            } elseif (preg_match('/^\d+$/', $mention)) {
                $content = "<@&{$mention}> ";
                $allowedMentions['roles'][] = $mention;
            }
        }

        $content .= '**Critical: Upwell Structure Low Fuel** - 1 structure needs attention';

        // Standard structure sample
        $standardEmbed = [
            'title' => 'Test Fortizar - Fuel Alert Preview',
            'color' => 15158332, // red
            'fields' => [
                ['name' => "\u{1F4CD} Location", 'value' => 'Jita (0.95)', 'inline' => true],
                ['name' => 'Structure Type', 'value' => 'Fortizar', 'inline' => true],
                ['name' => "\u{23F0} Last Update", 'value' => 'just now', 'inline' => true],
                ['name' => 'Fuel Blocks', 'value' => '3,240 blocks remaining', 'inline' => false],
                ['name' => 'Time Remaining', 'value' => '5d 18h at current rate', 'inline' => false],
                ['name' => 'Consumption Rate', 'value' => '23.5 blocks/hour', 'inline' => true],
                ['name' => 'Active Services', 'value' => '4 service(s) online', 'inline' => true],
                ['name' => 'Weekly Requirement', 'value' => '3,948 blocks', 'inline' => true],
            ],
            'footer' => ['text' => 'SeAT Structure Manager | TEST PREVIEW - Not a real alert'],
            'timestamp' => now()->toIso8601String(),
        ];

        // Metenox sample
        $metenoxEmbed = [
            'title' => 'Test Metenox - Dual Fuel Preview',
            'color' => 16776960, // yellow (warning)
            'fields' => [
                ['name' => "\u{1F4CD} Location", 'value' => 'Amamake (0.40)', 'inline' => true],
                ['name' => 'Structure Type', 'value' => 'Metenox Moon Drill', 'inline' => true],
                ['name' => "\u{23F0} Last Update", 'value' => 'just now', 'inline' => true],
                ['name' => 'Fuel Blocks', 'value' => '1,680 blocks (14.0d)', 'inline' => false],
                ['name' => 'Magmatic Gas **[LIMITING]**', 'value' => '52,800 gas (11.0d)', 'inline' => false],
                ['name' => 'Offline In', 'value' => '11d 0h (gas runs out first)', 'inline' => false],
                ['name' => 'Weekly Requirement', 'value' => '840 blocks + 33,600 gas', 'inline' => true],
            ],
            'footer' => ['text' => 'SeAT Structure Manager | TEST PREVIEW - Not a real alert'],
            'timestamp' => now()->toIso8601String(),
        ];

        return [
            'content' => $content,
            'embeds' => [$standardEmbed, $metenoxEmbed],
            'username' => 'SeAT Structure Manager',
            'allowed_mentions' => $allowedMentions,
        ];
    }

    /**
     * Shared guard: require the explicit confirm=yes body field. This is the UI
     * equivalent of a `--force` flag on the artisan commands and is the last
     * line of defence against a misclick in production.
     */
    private function confirmed(Request $request): bool
    {
        return $request->input('confirm') === 'yes';
    }
}
