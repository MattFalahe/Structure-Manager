<?php

namespace StructureManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use StructureManager\Console\Commands\CreateTestUpwellStructuresCommand;
use StructureManager\Helpers\FuelCalculator;
use StructureManager\Helpers\PosFuelCalculator;
use StructureManager\Helpers\TypeIdRegistry;
use StructureManager\Models\StructureManagerSettings;
use StructureManager\Models\WebhookConfiguration;
use StructureManager\Models\StarbaseFuelHistory;
use StructureManager\Models\StructureNotificationStatus;
use StructureManager\Models\EsiNotification;
use StructureManager\Integrations\ManagerCoreIntegration;
use StructureManager\Services\FakeNotificationBuilder;
use StructureManager\Services\TestDataGenerator;

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
     * The artisan commands the plugin registers into SeAT's scheduler.
     * Each paired with its expected cron expression (source of truth = our seeder).
     */
    private const EXPECTED_SCHEDULES = [
        'structure-manager:track-fuel'              => '15 * * * *',
        'structure-manager:analyze-consumption'     => '30 * * * *',
        'structure-manager:track-poses-fuel'        => '*/10 * * * *',
        'structure-manager:analyze-pos-consumption' => '0 1 * * *',
        'structure-manager:notify-pos-fuel'         => '*/10 * * * *',
        'structure-manager:notify-upwell-fuel'      => '*/10 * * * *',
        'structure-manager:process-notifications'   => '* * * * *',
        'structure-manager:cleanup-history'         => '0 3 * * *',
    ];

    /**
     * The ten database tables the plugin owns.
     */
    private const PLUGIN_TABLES = [
        'structure_fuel_history',
        'structure_fuel_reserves',
        'structure_notification_status',
        'structure_manager_esi_notifications',
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
     *
     * Performance strategy: the heavier read-only computations (cross-table
     * orphan checks, hardcode-constant validation, catalog joins) get a
     * short Redis cache so a refresh-twice scenario doesn't re-pay the cost.
     * Pass ?refresh=1 to bust the per-section caches and recompute live.
     *
     * Lazy-loaded sections only run when the relevant tab is the active
     * one — this is the single biggest page-load win because the Fuel
     * Trace catalog (~28 Upwells + 4 POSes joined across 4 tables) is only
     * needed when an admin clicks that tab.
     */
    public function index()
    {
        $forceRefresh = (bool) request('refresh', false);
        $activeTab    = (string) request('diag_tab', '');
        $traceId      = (int) request('trace_id', 0);
        $traceType    = (string) request('trace_type', '');

        // Health checks split by cost:
        //
        //   FAST (uncached) — single small query each, <10ms apiece.
        //   The 6 cheap ones run on every page load to keep the dashboard
        //   feeling live (settings change, schedules, webhooks).
        //
        //   HEAVY (60s-cached) — full-table COUNTs / groupBys on history
        //   tables that grow unbounded. Without caching these dominated
        //   page-load time on active installs (50+ structures, 6 months
        //   of hourly history = 200k+ rows scanned per check). 60s TTL
        //   feels live while absorbing rapid-clicks.
        //
        //   STATIC (1800s-cached) — type IDs that depend on SDE state
        //   which only changes on SeAT updates.
        $checks = [
            // FAST
            'environment'        => $this->checkEnvironment(),
            'required_tables'    => $this->checkRequiredTables(),
            'plugin_tables'      => $this->checkPluginTables(),
            'schedules'          => $this->checkSchedules(),
            'webhooks'           => $this->checkWebhooks(),
            'user_context'       => $this->checkUserContext(),

            // STATIC
            'type_ids'           => $this->cached('checks.type_ids', 1800, $forceRefresh, fn() => $this->checkTypeIds()),

            // HEAVY — wrapped in 60s cache to avoid the per-page scan
            'esi_coverage'              => $this->cached('checks.esi_coverage', 60, $forceRefresh, fn() => $this->checkEsiCoverage()),
            'notification_state'        => $this->cached('checks.notification_state', 60, $forceRefresh, fn() => $this->checkNotificationState()),
            'upwell_notification_state' => $this->cached('checks.upwell_notification_state', 60, $forceRefresh, fn() => $this->checkUpwellNotificationState()),
            'esi_polling_state'         => $this->cached('checks.esi_polling_state', 60, $forceRefresh, fn() => $this->checkEsiPollingState()),
            'pricing_integration'       => $this->cached('checks.pricing_integration', 60, $forceRefresh, fn() => $this->checkPricingIntegration()),

            // v2.0.0 — webhook delivery health from telemetry table
            'webhook_delivery'          => $this->cached('checks.webhook_delivery', 60, $forceRefresh, fn() => $this->checkWebhookDelivery()),
        ];

        $summary  = $this->summarise($checks);
        $testData = $this->countTestData();
        $testLab  = $this->buildTestLabStateData();

        // Heavy read-only sections — lazy-loaded per active tab.
        //
        // The default landing tab is Health Checks, which doesn't need
        // any of these. Computing System Validation / Settings Health /
        // Data Integrity eagerly on every page load was the dominant
        // cost on cold start (heaviest single section ~1-3s on large
        // installs).
        //
        // New behavior: each heavy section only runs when the URL's
        // ?diag_tab=X matches that section. JS auto-redirects on tab
        // click (the same data-lazy pattern Fuel Trace has used since
        // launch). Cached results persist across redirects so subsequent
        // visits hit the cache regardless of which tab is active.
        //
        // Cache TTLs tuned per section:
        //   system_validation  — 1800s (constants + dependencies rarely change)
        //   data_integrity     —  300s (DB consistency)
        //   settings_health    —   30s (operator edits should feel live)
        $systemValidation = null;
        $dataIntegrity    = null;
        $settingsHealth   = null;

        if ($activeTab === 'system-validation') {
            $systemValidation = $this->cached(
                'system_validation', 1800, $forceRefresh,
                fn() => $this->buildSystemValidation()
            );
        }
        if ($activeTab === 'data-integrity') {
            $dataIntegrity = $this->cached(
                'data_integrity', 300, $forceRefresh,
                fn() => $this->buildDataIntegrity()
            );
        }
        if ($activeTab === 'settings-health') {
            $settingsHealth = $this->cached(
                'settings_health', 30, $forceRefresh,
                fn() => $this->buildSettingsHealth()
            );
        }

        // ---- Lazy-loaded sections (existing pattern) ----
        // Fuel Trace catalog is the slowest single thing on the page
        // (~4-table join). Only build it when the user is actually on
        // the fuel-trace tab OR has explicitly requested a trace.
        $traceCatalog = null;
        $fuelTrace    = null;
        $isFuelTraceActive = $activeTab === 'fuel-trace' || $traceId > 0;
        if ($isFuelTraceActive) {
            $traceCatalog = $this->cached(
                'fuel_trace_catalog', 300, $forceRefresh,
                fn() => $this->buildFuelTraceCatalog()
            );
            if ($traceId > 0 && $traceType !== '') {
                // Per-entity trace is fast and admin-driven — never cache.
                $fuelTrace = $this->buildFuelTrace($traceId, $traceType);
            }
        }

        // Flags for the blade — pane gets data-lazy="true" when its data
        // hasn't been loaded yet. The JS tab switcher reads this and
        // triggers the redirect.
        $isSystemValidationActive = $activeTab === 'system-validation';
        $isDataIntegrityActive    = $activeTab === 'data-integrity';
        $isSettingsHealthActive   = $activeTab === 'settings-health';

        return view('structure-manager::diagnostic.index', compact(
            'checks', 'summary', 'testData', 'testLab',
            'systemValidation', 'settingsHealth', 'dataIntegrity',
            'traceCatalog', 'fuelTrace', 'isFuelTraceActive',
            'isSystemValidationActive', 'isDataIntegrityActive', 'isSettingsHealthActive'
        ));
    }

    /**
     * Per-section Redis cache helper. Wraps Cache::remember with a
     * forceRefresh override (admin can pass ?refresh=1 to recompute live)
     * and a defensive try/catch so a Redis hiccup never 500's the
     * diagnostic page — falls back to running the closure directly.
     *
     * Cache keys are namespaced under sm:diag: so they never collide with
     * other plugins or with other diagnostic implementations.
     */
    private function cached(string $key, int $ttlSeconds, bool $forceRefresh, callable $compute)
    {
        $fullKey = "sm:diag:{$key}";
        try {
            if ($forceRefresh) {
                Cache::forget($fullKey);
            }
            return Cache::remember($fullKey, $ttlSeconds, $compute);
        } catch (\Throwable $e) {
            // Cache backend hiccup — log once and fall through. We don't
            // want a Redis blip to take down the diagnostic page.
            Log::warning("[SM] Diagnostic cache failed for {$fullKey}: " . $e->getMessage());
            return $compute();
        }
    }

    /**
     * Build the data shape used by both the SSR view (index page) and the
     * AJAX state endpoint (`testLabState`). One source of truth.
     */
    private function buildTestLabStateData(): array
    {
        $inventory = TestDataGenerator::inventory();
        $catalog   = CreateTestUpwellStructuresCommand::catalog();

        $existing = DB::table('corporation_structures')
            ->whereBetween('structure_id', [
                TestDataGenerator::STRUCTURE_ID_MIN,
                TestDataGenerator::STRUCTURE_ID_MAX,
            ])
            ->select('structure_id', 'corporation_id', 'system_id', 'type_id', 'fuel_expires', 'state')
            ->get()
            ->keyBy('structure_id');

        // v2.0.0 — batch the universe_structures lookups. Previously we
        // queried per-iteration inside the foreach, producing N+1 SDE
        // hits on every diagnostic page load. For installs with the full
        // catalog of 25+ test structures that's 25 extra round-trips
        // every refresh.
        $testStructureIds = array_column($catalog, 'structure_id');
        $structureNames = DB::table('universe_structures')
            ->whereIn('structure_id', $testStructureIds)
            ->pluck('name', 'structure_id');

        $structures = [];
        foreach ($catalog as $entry) {
            $row = $existing->get($entry['structure_id']);
            $structures[] = [
                'slug'           => $entry['slug'],
                'name'           => $entry['name'],
                'type_id'        => $entry['type_id'],
                'structure_id'   => $entry['structure_id'],
                'exists'         => $row !== null,
                'corporation_id' => $row->corporation_id ?? null,
                'system_id'      => $row->system_id ?? null,
                'fuel_expires'   => $row->fuel_expires ?? null,
                'state'          => $row->state ?? null,
                'display_name'   => $row !== null
                    ? ($structureNames[$entry['structure_id']] ?? ('TEST - ' . $entry['name']))
                    : null,
            ];
        }

        $byFamily = [];
        foreach (FakeNotificationBuilder::SUPPORTED_TYPES as $type => $cfg) {
            $byFamily[$cfg['family']][] = ['type' => $type, 'label' => $cfg['label']];
        }

        // Recent test notifications. Check BOTH dedup tables (SM's owned, MC's
        // shared) so the displayed status is accurate regardless of which path
        // dispatched the notification:
        //   - SM standalone: only SM's table exists, processed=true means done
        //   - MC + SM:       MC's table is the source of truth, dispatched=true
        //                    OR the synchronous-dispatch path on injection
        //                    writes both tables eagerly
        $hasMcTable = \Illuminate\Support\Facades\Schema::hasTable('manager_core_esi_notifications');

        $query = DB::table('character_notifications as cn')
            ->leftJoin('structure_manager_esi_notifications as sm', 'cn.notification_id', '=', 'sm.notification_id');

        if ($hasMcTable) {
            $query->leftJoin('manager_core_esi_notifications as mc', 'cn.notification_id', '=', 'mc.notification_id');
        }

        $select = [
            'cn.notification_id',
            'cn.type',
            'cn.timestamp',
            'sm.processed as sm_processed',
            'sm.processed_at as sm_processed_at',
        ];
        if ($hasMcTable) {
            $select[] = 'mc.dispatched as mc_dispatched';
            $select[] = DB::raw('mc.dispatched_at as mc_dispatched_at');
        }

        $recent = $query
            ->whereBetween('cn.notification_id', [
                TestDataGenerator::NOTIFICATION_ID_MIN,
                TestDataGenerator::NOTIFICATION_ID_MAX,
            ])
            ->orderByDesc('cn.timestamp')
            ->limit(10)
            ->get($select)
            ->map(function ($r) use ($hasMcTable) {
                // Status priority:
                //   processed: either SM's table marks processed=1 OR MC's table marks dispatched=1
                //   pending:   row exists in either table but flag is 0
                //   queued:    no row in either table yet
                $smDone = ($r->sm_processed ?? null) ? true : false;
                $smRow  = property_exists($r, 'sm_processed') && $r->sm_processed !== null;
                $mcDone = $hasMcTable ? (($r->mc_dispatched ?? null) ? true : false) : false;
                $mcRow  = $hasMcTable && property_exists($r, 'mc_dispatched') && $r->mc_dispatched !== null;

                if ($smDone || $mcDone) {
                    $status = 'processed';
                } elseif ($smRow || $mcRow) {
                    $status = 'pending';
                } else {
                    $status = 'queued';
                }

                $processedAt = $r->sm_processed_at ?? ($hasMcTable ? ($r->mc_dispatched_at ?? null) : null);

                return [
                    'notification_id' => (string) $r->notification_id,
                    'type'            => $r->type,
                    'timestamp'       => $r->timestamp,
                    'processed'       => $status,
                    'processed_at'    => $processedAt,
                ];
            })
            ->toArray();

        return [
            'inventory'          => $inventory,
            'structures'         => $structures,
            'notification_types' => $byFamily,
            'recent'             => $recent,
            'test_webhook_url'   => StructureManagerSettings::get('test_webhook_url', ''),
        ];
    }

    // ------------------------------------------------------------------
    // Individual checks. Each returns:
    //   ['status' => 'ok'|'warn'|'error'|'info', 'message' => string, 'details' => array]
    // ------------------------------------------------------------------

    /**
     * Plugin + PHP + SeAT environment basics.
     *
     * The plugin version is read live from Composer's runtime metadata
     * (Composer\InstalledVersions) which is the actual version of the
     * package as composer installed it. Falls back to the static
     * config/structure-manager.php version key if Composer's runtime
     * isn't available (it should always be on Composer 2). Then queries
     * Packagist for the latest stable version and surfaces an
     * 'UPDATE AVAILABLE' notice when the install is behind. Packagist
     * response is cached for 1 hour to avoid hammering their API.
     */
    private function checkEnvironment(): array
    {
        $versionInfo = $this->resolvePluginVersionInfo();

        $details = [
            'Plugin version'   => $versionInfo['installed_display'],
            'Latest on Packagist' => $versionInfo['latest_display'],
            'Update status'    => $versionInfo['status_label'],
            'PHP version'      => PHP_VERSION,
            'Laravel version'  => app()->version(),
            'App timezone'     => config('app.timezone'),
            'DB timezone (now)' => $this->dbTimezone(),
            'Debug mode'       => config('app.debug') ? 'ON' : 'off',
            'Queue driver'     => config('queue.default'),
        ];

        // Bubble update-available status up to the badge so admins see
        // it without expanding the Detail block.
        $checkStatus = match ($versionInfo['status']) {
            'outdated'    => 'warn',
            'unchecked'   => 'info',
            'dev-branch'  => 'info',
            default       => 'info',
        };

        $msg = 'Structure Manager ' . $versionInfo['installed_display'] . ' on PHP ' . PHP_VERSION;
        if ($versionInfo['status'] === 'outdated') {
            $msg .= '. Update available: ' . $versionInfo['latest_display'];
        }

        return [
            'status'  => $checkStatus,
            'message' => $msg,
            'details' => $details,
        ];
    }

    /**
     * Resolve the plugin's installed version (via Composer runtime), the
     * latest version on Packagist (cached), and the comparison status.
     *
     * Returns:
     *   [
     *     'installed_raw'     => string  raw value from Composer or config
     *     'installed_display' => string  user-facing label (e.g. '3.0.0' or 'dev-dev-4.0')
     *     'is_dev'            => bool    true if running a dev branch
     *     'latest_raw'        => string  raw value from Packagist (or '?' if unreachable)
     *     'latest_display'    => string  user-facing label
     *     'status'            => 'up_to_date' | 'outdated' | 'dev-branch' | 'unchecked'
     *     'status_label'      => string  human-readable status sentence
     *   ]
     */
    private function resolvePluginVersionInfo(): array
    {
        $package = 'mattfalahe/structure-manager';

        // ---- Installed version (live from Composer runtime) ----
        $installedRaw = null;
        if (class_exists(\Composer\InstalledVersions::class)) {
            try {
                if (\Composer\InstalledVersions::isInstalled($package)) {
                    $installedRaw = \Composer\InstalledVersions::getPrettyVersion($package);
                }
            } catch (\Throwable $e) {
                // fall through to config fallback below
            }
        }
        if ($installedRaw === null || $installedRaw === '') {
            // Fallback to the config file — useful when the package was
            // path-installed and Composer doesn't expose a clean version.
            $installedRaw = config('structure-manager.version', 'unknown');
        }

        // Detect dev / branch installs (Composer reports them as 'dev-foo'
        // or 'dev-dev-4.0' depending on require constraint).
        $isDev = (bool) preg_match('/^dev-|-dev$/', $installedRaw);

        // ---- Packagist latest stable (cached 1h) ----
        $latestRaw = Cache::remember(
            'structure-manager:packagist:latest',
            now()->addHour(),
            function () use ($package) {
                try {
                    $resp = Http::connectTimeout(3)->timeout(5)
                        ->get("https://repo.packagist.org/p2/{$package}.json");
                    if (!$resp->ok()) {
                        return null;
                    }
                    $payload = $resp->json();
                    $versions = $payload['packages'][$package] ?? [];
                    if (empty($versions)) {
                        return null;
                    }
                    // Pick the highest STABLE version. Skip dev/alpha/beta/rc.
                    $best = null;
                    foreach ($versions as $entry) {
                        $v = $entry['version'] ?? null;
                        if (!$v) continue;
                        // Reject non-stable
                        if (preg_match('/^dev-|-(alpha|beta|rc|dev)/i', $v)) {
                            continue;
                        }
                        $clean = ltrim($v, 'vV');
                        if ($best === null || version_compare($clean, ltrim($best, 'vV'), '>')) {
                            $best = $v;
                        }
                    }
                    return $best;
                } catch (\Throwable $e) {
                    return null;
                }
            }
        );

        // ---- Comparison ----
        $status = 'unchecked';
        $statusLabel = 'Unable to reach Packagist (cached for 1h).';
        $latestDisplay = $latestRaw ?? '?';
        $installedDisplay = $installedRaw;

        if ($isDev) {
            $status = 'dev-branch';
            $statusLabel = 'Running development branch. Packagist comparison skipped.';
        } elseif ($latestRaw !== null) {
            $cleanInstalled = ltrim($installedRaw, 'vV');
            $cleanLatest    = ltrim($latestRaw, 'vV');
            $cmp = version_compare($cleanInstalled, $cleanLatest);
            if ($cmp < 0) {
                $status = 'outdated';
                $statusLabel = 'Update available: ' . $latestRaw . ' (running ' . $installedDisplay . ').';
            } elseif ($cmp === 0) {
                $status = 'up_to_date';
                $statusLabel = 'Up to date.';
            } else {
                // installed > latest — running ahead of Packagist (typical
                // when a tagged release hasn't been pushed yet).
                $status = 'up_to_date';
                $statusLabel = 'Running ahead of Packagist (' . $installedDisplay . ' > ' . $latestRaw . ').';
            }
        }

        return [
            'installed_raw'     => $installedRaw,
            'installed_display' => $installedDisplay,
            'is_dev'            => $isDev,
            'latest_raw'        => $latestRaw,
            'latest_display'    => $latestDisplay,
            'status'            => $status,
            'status_label'      => $statusLabel,
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
                array_keys(TypeIdRegistry::FUEL_BLOCK_NAMES),
                array_values(TypeIdRegistry::FUEL_BLOCK_NAMES)
            ),
            'Strontium & Magmatic Gas' => [
                ['id' => TypeIdRegistry::STRONTIUM,           'expected' => 'Strontium Clathrates'],
                ['id' => TypeIdRegistry::MAGMATIC_GAS,   'expected' => 'Magmatic Gas'],
            ],
            'Starbase Charters' => array_map(
                fn($id, $expected) => ['id' => $id, 'expected' => $expected],
                array_keys(TypeIdRegistry::CHARTER_NAMES),
                array_values(TypeIdRegistry::CHARTER_NAMES)
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

        // Stuck latches: latch set true but structure is above warning threshold.
        // Computed via a direct join (StructureNotificationStatus doesn't have
        // a Laravel relation to corporation_structures — the IDs share the
        // same structure_id column but there's no defined belongsTo).
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
     * ESI notification path health.
     *
     * When Manager Core is installed, show the shared key pool status + recent
     * fast-poll counts (by querying MC's tables read-only — we never write).
     *
     * When Manager Core is absent, show Structure Manager's local dedup table
     * state (how many notifications the fallback job processed recently).
     */
    private function checkEsiPollingState(): array
    {
        // Branch on the EFFECTIVE detection state, not just MC availability.
        // Operator's `esi_detection_mode` choice may opt out of MC fast-poll
        // even when MC is installed (mode=seat_native), or disable detection
        // entirely (mode=off). Surface that explicitly so admins can see at
        // a glance which path is actually running.
        $configured = ManagerCoreIntegration::detectionMode();
        if ($configured === ManagerCoreIntegration::MODE_OFF) {
            return $this->checkEsiPollingStateOff();
        }
        if (ManagerCoreIntegration::isFastPollEnabled()) {
            return $this->checkEsiPollingStateWithManagerCore();
        }

        // Native sweep is active — either MC is absent, or operator chose
        // 'seat_native' mode. Both paths use the same SM-local infrastructure.
        return $this->checkEsiPollingStateStandalone();
    }

    /**
     * Mode = off — operator disabled all ESI detection.
     */
    private function checkEsiPollingStateOff(): array
    {
        return [
            'status'  => 'warn',
            'message' => 'ESI detection is set to OFF. Shield/armor/hull/destroyed events will NOT fire. Fuel alerts (poll-based) still work.',
            'details' => [
                'Detection mode' => 'off (manually disabled)',
                'Effect'         => 'No CCP notifications processed; no structure.alert.* events published',
                'How to fix'     => 'Settings > Structure Events > Detection Mode → switch to Auto or SeAT native',
            ],
        ];
    }

    /**
     * MC-installed path: read MC's shared tables for key pool + notification stats.
     */
    private function checkEsiPollingStateWithManagerCore(): array
    {
        $details = [
            'Detection mode' => 'Fast-poll via Manager Core (~2 min)',
        ];

        if (Schema::hasTable('manager_core_esi_key_holders')) {
            $totalKeyHolders = DB::table('manager_core_esi_key_holders')->count();
            $enabledKeyHolders = DB::table('manager_core_esi_key_holders')->where('enabled', true)->count();
            $healthyKeyHolders = DB::table('manager_core_esi_key_holders')
                ->where('enabled', true)
                ->where('last_poll_status', 'success')
                ->count();
            $suspendedKeyHolders = DB::table('manager_core_esi_key_holders')
                ->where('consecutive_failures', '>=', 5)
                ->count();

            $details['Shared key holders in pool'] = $totalKeyHolders;
            $details['Enabled key holders']       = $enabledKeyHolders;
            $details['Healthy (last poll OK)']    = $healthyKeyHolders;
            $details['Suspended (5+ failures)']   = $suspendedKeyHolders;
        } else {
            $totalKeyHolders = 0;
            $enabledKeyHolders = 0;
            $healthyKeyHolders = 0;
            $suspendedKeyHolders = 0;
            $details['Shared key holders in pool'] = 'table missing';
        }

        if (Schema::hasTable('manager_core_esi_notifications')) {
            $sinceHour = \Carbon\Carbon::now()->subHour();
            $details['Notifications (last hour)'] = DB::table('manager_core_esi_notifications')
                ->where('created_at', '>=', $sinceHour)->count();
            $details['Total via fast-poll']       = DB::table('manager_core_esi_notifications')
                ->where('source', 'fast_poll')->count();
            $details['Total via SeAT fallback']   = DB::table('manager_core_esi_notifications')
                ->where('source', 'seat_fallback')->count();
        }

        // Is SM registered with MC's notification registry?
        // IMPORTANT: resolve via the `::class` constant, not a single-quoted
        // string with leading backslash. Laravel 11's container no longer
        // normalises leading backslashes, so the two forms hit DIFFERENT
        // binding keys and the leading-backslash form bypasses MC's singleton
        // — every resolve builds a fresh empty registry. See ManagerCoreIntegration::registerStructureEventHandler() for the full note.
        $registered = false;
        try {
            $registry = app(\ManagerCore\Services\ESI\EsiNotificationRegistry::class);
            $registered = $registry->hasHandlersForType('StructureUnderAttack');
        } catch (\Throwable $e) {
            // MC present but registry unavailable — treat as unregistered
        }
        $details['Structure Manager registered with MC'] = $registered ? 'yes' : 'no';

        $status = 'ok';
        if (!$registered) {
            $status = 'error';
            $message = 'Manager Core is installed but Structure Manager has not registered its handler. Check logs for boot errors.';
        } elseif ($totalKeyHolders === 0) {
            $status = 'warn';
            $message = 'No key holders in Manager Core\'s shared pool. Add directors in Manager Core > ESI Key Pool.';
        } elseif ($enabledKeyHolders === 0) {
            $status = 'error';
            $message = 'All key holders are disabled — no fast-polling will happen.';
        } elseif ($suspendedKeyHolders > 0) {
            $status = 'warn';
            $message = "{$enabledKeyHolders} enabled, {$healthyKeyHolders} healthy, {$suspendedKeyHolders} suspended.";
        } else {
            $message = "{$enabledKeyHolders} enabled director(s) in shared pool, {$healthyKeyHolders} healthy.";
        }

        return [
            'status'  => $status,
            'message' => $message,
            'details' => $details,
        ];
    }

    /**
     * Native-sweep path. Reached in two situations:
     *   - MC is absent (auto mode falls back) — "standalone" in the strict sense
     *   - MC is installed but operator chose mode=seat_native (opt-out)
     *
     * Reports on SM's local dedup table state for both cases.
     */
    private function checkEsiPollingStateStandalone(): array
    {
        $mcAvailable = ManagerCoreIntegration::isAvailable();
        $configured  = ManagerCoreIntegration::detectionMode();
        $optedOut    = $mcAvailable && $configured === ManagerCoreIntegration::MODE_SEAT_NATIVE;

        $details = [
            'Detection mode'    => 'SeAT native (~15-20 min bucket)',
            'Configured mode'   => $configured,
            'Manager Core'      => $mcAvailable ? 'installed (fast-poll opted out)' : 'not installed',
        ];

        if (!$mcAvailable) {
            $details['Install Manager Core'] = 'for 2-min detection + shared key pool';
        }

        if (Schema::hasTable('structure_manager_esi_notifications')) {
            $sinceHour = \Carbon\Carbon::now()->subHour();
            $details['Local dedup rows (last hour)'] = EsiNotification::where('created_at', '>=', $sinceHour)->count();
            $details['Local dedup rows (total)']     = EsiNotification::count();
        }

        if ($optedOut) {
            $message = 'Native sweep is active by operator choice: Manager Core is installed but you opted out of fast-poll (mode=seat_native). SM reads from SeAT\'s character_notifications every minute.';
        } else {
            $message = 'Standalone mode: Structure Manager reads from SeAT\'s character_notifications every minute. Install Manager Core for fast-poll.';
        }

        return [
            'status'  => 'info',
            'message' => $message,
            'details' => $details,
        ];
    }

    /**
     * Pricing integration state for the Fuel Economics page.
     *
     * Surfaces whichever of these conditions applies:
     *   - MC pricing absent: Economics page is gracefully disabled.
     *     Reported as INFO so this isn't flagged red on a vanilla
     *     SM-only install.
     *   - MC pricing present but SM hasn't registered yet: WARN.
     *     Usually means the boot call failed once and never retried;
     *     restart the SeAT containers to re-fire it.
     *   - MC pricing present + SM registered: OK with the current
     *     market + price_type + admin-override flag in the detail.
     *
     * Why a check at all: the Economics page is the most expensive
     * cross-plugin integration SM has, and silently broken pricing
     * is the kind of thing that's hard to spot otherwise (the page
     * loads with empty totals and no obvious error message).
     */
    private function checkPricingIntegration(): array
    {
        if (!\StructureManager\Integrations\ManagerCoreIntegration::isPricingAvailable()) {
            return [
                'status'  => 'info',
                'message' => 'Manager Core pricing not installed. Fuel Economics page disabled.',
                'details' => [
                    'mc_pricing_service' => 'absent',
                    'pricing_preference' => null,
                    'effect'             => 'Economics sidebar entry is hidden; SM works fully without it.',
                    'install_link'       => 'https://github.com/MattFalahe/Manager-Core',
                ],
            ];
        }

        // Operator has explicitly opted out via SM Settings > Economics.
        // Distinct from "MC absent" because the admin made an active choice
        // that we should reflect rather than silently treat as a problem.
        if (\StructureManager\Integrations\ManagerCoreIntegration::economicsPricingMode() ===
            \StructureManager\Integrations\ManagerCoreIntegration::ECONOMICS_MODE_DISABLED) {
            return [
                'status'  => 'info',
                'message' => 'Economics integration disabled by operator. Fuel Economics page hidden from sidebar.',
                'details' => [
                    'mc_pricing_service' => 'present',
                    'economics_mode'     => 'disabled',
                    'effect'             => 'No pricing registration runs at boot. Existing MC pricing-preferences row (if any) is left untouched.',
                    'enable_at'          => '/structure-manager/settings#economics',
                ],
            ];
        }

        // MC pricing is present — does SM have a registered preference?
        $pref = null;
        try {
            $pref = \ManagerCore\Models\PricingPreference::forPlugin(
                \StructureManager\Services\FuelEconomicsService::PLUGIN_KEY
            );
        } catch (\Throwable $e) {
            return [
                'status'  => 'warn',
                'message' => 'MC pricing reachable but preference lookup threw: ' . $e->getMessage(),
                'details' => [
                    'mc_pricing_service' => 'present',
                    'pricing_preference' => 'lookup_error',
                    'error'              => $e->getMessage(),
                    'fix'                => 'Restart SeAT containers so SM can re-register at boot.',
                ],
            ];
        }

        if ($pref === null) {
            return [
                'status'  => 'warn',
                'message' => 'MC pricing installed but Structure Manager has not registered a preference yet. Restart SeAT containers.',
                'details' => [
                    'mc_pricing_service' => 'present',
                    'pricing_preference' => 'not_registered',
                    'effect'             => 'Economics page will fall back to MC defaults (jita sell). Restart fixes.',
                ],
            ];
        }

        // Preference is registered. Verify the corresponding type prices
        // are actually cached in MC (they need to be for the Economics
        // page to show non-zero numbers). Cache-miss here is the most
        // common reason an Economics page reports zero across the board.
        $requiredTypes = \StructureManager\Integrations\ManagerCoreIntegration::REQUIRED_PRICING_TYPE_IDS;
        $cachedCount   = 0;
        $missingTypes  = [];
        if (Schema::hasTable('manager_core_market_prices')) {
            $cached = DB::table('manager_core_market_prices')
                ->where('market', $pref->market)
                ->where('price_type', $pref->price_type === 'avg' ? 'sell' : $pref->price_type)
                ->whereIn('type_id', $requiredTypes)
                ->pluck('type_id')
                ->all();
            $cachedCount = count(array_unique($cached));
            $missingTypes = array_values(array_diff($requiredTypes, $cached));
        }
        $totalTypes = count($requiredTypes);
        $allCached  = $cachedCount === $totalTypes;

        // Split missing types into "core fuel" vs "POS-only". The Economics
        // page's Upwell + Metenox projections need the 5 core fuel types
        // (4 fuel blocks + magmatic gas). Strontium clathrates + the 6 empire
        // charters only matter if the corp runs POSes — and the charters
        // ONLY trade on high-sec markets. When the configured pricing market
        // is a nullsec / low-sec citadel (the common case for alliance
        // installs) those 6 charter types will simply never cache, no matter
        // how many times the operator re-registers. So:
        //   - missing CORE fuel  → genuine WARN, "Re-register" advice applies
        //   - missing POS-only   → INFO, expected at non-high-sec markets
        $coreFuelTypes  = [4051, 4246, 4247, 4312, 81143];
        $missingCore    = array_values(array_intersect($missingTypes, $coreFuelTypes));
        $missingPosOnly = array_values(array_diff($missingTypes, $coreFuelTypes));

        $marketLabel = strtoupper($pref->market)
            . ($pref->admin_overridden ? ' (admin override)' : ' (plugin default)');
        $priceLabel  = strtoupper($pref->price_type);

        if ($allCached) {
            $status  = 'ok';
            $message = sprintf(
                'Registered: %s on %s. All %d fuel typeIDs cached.',
                $priceLabel, $marketLabel, $totalTypes
            );
        } elseif (!empty($missingCore)) {
            // Core fuel missing — Economics projections for affected
            // structures will read zero. This is the actionable case.
            $status  = 'warn';
            $message = sprintf(
                'Registered: %s on %s. %d core fuel typeID(s) not cached (%s). Economics projections for affected structures will read zero — click "Re-register now" in SM Settings > Economics to subscribe + refresh.',
                $priceLabel, $marketLabel,
                count($missingCore), implode(', ', $missingCore)
            );
            if (!empty($missingPosOnly)) {
                $message .= sprintf(
                    ' (%d POS-only typeID(s) also uncached — expected when the market does not carry strontium / empire charters.)',
                    count($missingPosOnly)
                );
            }
        } else {
            // Only POS-only types missing — expected at many markets, not a fault.
            $status  = 'info';
            $message = sprintf(
                'Registered: %s on %s. All 5 core fuel types cached. %d POS-only typeID(s) not cached (%s) — strontium + empire charters only trade on high-sec markets, so this is expected when the configured pricing market is a citadel / nullsec market. Only matters if you run POSes; set a high-sec market in Manager Core > Pricing Preferences if you need charter pricing.',
                $priceLabel, $marketLabel,
                count($missingPosOnly), implode(', ', $missingPosOnly)
            );
        }

        return [
            'status'  => $status,
            'message' => $message,
            'details' => [
                'plugin_key'          => $pref->plugin_key,
                'market'              => $pref->market,
                'price_type'          => $pref->price_type,
                'admin_overridden'    => $pref->admin_overridden ? 'yes' : 'no',
                'notes'               => $pref->notes,
                'price_cache_cached'  => $cachedCount,
                'price_cache_total'   => $totalTypes,
                'missing_core_types'  => empty($missingCore) ? 'none' : implode(', ', $missingCore),
                'missing_pos_types'   => empty($missingPosOnly) ? 'none' : implode(', ', $missingPosOnly),
                'configurable_at'     => '/manager-core/pricing-preferences',
            ],
        ];
    }

    /**
     * v2.0.0 — Webhook delivery health from the telemetry table.
     *
     * Surfaces "is each webhook actually delivering?" by reading
     * structure_manager_webhook_deliveries (populated by
     * WebhookDeliveryService::send on every dispatch attempt).
     *
     * Reports per-webhook:
     *   - last attempt timestamp + outcome
     *   - 24h success rate
     *   - recent failure samples (HTTP code + error)
     *
     * Status decision tree:
     *   - error  : any webhook has 24h success rate < 50% with >=3 attempts
     *   - warn   : any webhook has 24h success rate < 95% with >=3 attempts,
     *              OR any enabled webhook has no telemetry yet (zero dispatches)
     *   - info   : telemetry table empty (fresh install, no dispatches yet)
     *   - ok     : all webhooks ≥95% success
     */
    private function checkWebhookDelivery(): array
    {
        if (!Schema::hasTable('structure_manager_webhook_deliveries')) {
            return [
                'status'  => 'info',
                'message' => 'Webhook delivery telemetry table not yet created. Migration 000005 will add it on next container restart.',
                'details' => [
                    'table' => 'structure_manager_webhook_deliveries',
                    'state' => 'missing',
                ],
            ];
        }

        $cutoff = \Carbon\Carbon::now()->subHours(24);
        $webhooks = \StructureManager\Models\WebhookConfiguration::query()
            ->orderBy('id')
            ->get();

        if ($webhooks->isEmpty()) {
            return [
                'status'  => 'info',
                'message' => 'No webhooks configured yet. Add destinations via Webhook Configuration tab in Settings.',
                'details' => ['webhook_count' => 0],
            ];
        }

        // Bulk-fetch 24h stats per webhook in ONE query (no N+1)
        $stats = DB::table('structure_manager_webhook_deliveries')
            ->where('attempted_at', '>=', $cutoff)
            ->select(
                'webhook_id',
                DB::raw('COUNT(*) as total'),
                DB::raw('SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as successes'),
                DB::raw('SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failures'),
                DB::raw('MAX(attempted_at) as last_attempt_at'),
                DB::raw('AVG(duration_ms) as avg_ms')
            )
            ->groupBy('webhook_id')
            ->get()
            ->keyBy('webhook_id');

        // Pull the most recent failure per webhook for diagnostic display
        $recentFailures = DB::table('structure_manager_webhook_deliveries')
            ->whereIn('id', function ($sub) use ($cutoff) {
                $sub->selectRaw('MAX(id)')
                    ->from('structure_manager_webhook_deliveries')
                    ->where('success', false)
                    ->where('attempted_at', '>=', $cutoff)
                    ->groupBy('webhook_id');
            })
            ->select('webhook_id', 'attempted_at', 'status_code', 'error_message', 'category_key')
            ->get()
            ->keyBy('webhook_id');

        $perWebhook = [];
        $overallStatus = 'ok';
        $warnCount = 0;
        $errorCount = 0;
        $noTelemetryCount = 0;

        foreach ($webhooks as $wh) {
            $stat = $stats->get($wh->id);
            $total = $stat ? (int) $stat->total : 0;
            $successes = $stat ? (int) $stat->successes : 0;
            $failures = $stat ? (int) $stat->failures : 0;
            $rate = $total > 0 ? round(($successes / $total) * 100, 1) : null;

            // Per-webhook status
            $whStatus = 'ok';
            if (!$wh->enabled) {
                $whStatus = 'info';
            } elseif ($total === 0) {
                // Enabled but no dispatches yet — could be brand new or could
                // mean the bindings have no categories. Soft warn.
                $whStatus = 'warn';
                $noTelemetryCount++;
            } elseif ($total >= 3 && $rate !== null && $rate < 50) {
                $whStatus = 'error';
                $errorCount++;
            } elseif ($total >= 3 && $rate !== null && $rate < 95) {
                $whStatus = 'warn';
                $warnCount++;
            }

            if ($whStatus === 'error') $overallStatus = 'error';
            elseif ($whStatus === 'warn' && $overallStatus !== 'error') $overallStatus = 'warn';

            $failure = $recentFailures->get($wh->id);

            $perWebhook[] = [
                'id'              => $wh->id,
                'label'           => $wh->description ?: ('Webhook #' . $wh->id),
                'enabled'         => (bool) $wh->enabled,
                'corp_scope'      => $wh->corporation_id ? ('corp ' . $wh->corporation_id) : 'global',
                'attempts_24h'    => $total,
                'successes_24h'   => $successes,
                'failures_24h'    => $failures,
                'success_rate'    => $rate,
                'avg_duration_ms' => $stat ? (int) round((float) $stat->avg_ms) : null,
                'last_attempt_at' => $stat ? $stat->last_attempt_at : null,
                'last_failure'    => $failure ? [
                    'at'           => $failure->attempted_at,
                    'status_code'  => (int) $failure->status_code,
                    'error_short'  => $failure->error_message
                        ? mb_substr((string) $failure->error_message, 0, 120)
                        : null,
                    'category_key' => $failure->category_key,
                ] : null,
                'status'          => $whStatus,
            ];
        }

        // Headline message
        if ($overallStatus === 'error') {
            $message = "{$errorCount} webhook(s) have <50% delivery success in the last 24h.";
        } elseif ($overallStatus === 'warn') {
            $parts = [];
            if ($warnCount > 0) $parts[] = "{$warnCount} webhook(s) below 95% success";
            if ($noTelemetryCount > 0) $parts[] = "{$noTelemetryCount} enabled webhook(s) with no dispatches yet";
            $message = implode(', ', $parts) . '.';
        } else {
            $totalDispatches = array_sum(array_column($perWebhook, 'attempts_24h'));
            $message = "All {$webhooks->count()} webhook(s) healthy. {$totalDispatches} dispatch(es) in the last 24h.";
        }

        return [
            'status'  => $overallStatus,
            'message' => $message,
            'details' => [
                'webhook_count'        => $webhooks->count(),
                'enabled_count'        => $webhooks->where('enabled', true)->count(),
                'webhooks'             => $perWebhook,
                'window'               => 'last 24h',
                'telemetry_started_at' => DB::table('structure_manager_webhook_deliveries')
                    ->min('attempted_at'),
            ],
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
        foreach (TypeIdRegistry::UPWELL_TYPE_IDS as $id => $info) {
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
     * Build System Validation payload — verifies the plugin's HARDCODED
     * constants and DEPENDENCIES are sound. Distinct from Master Test which
     * checks runtime state. This catches "CCP renamed a type", "SeAT removed
     * a class", "MC version too old", "constants out of order" and similar.
     *
     * Returns:
     *   [
     *     'overall' => 'ok|warn|error',
     *     'counts'  => ['ok'=>N,'warn'=>N,'error'=>N],
     *     'total'   => N,
     *     'groups'  => [ ['title'=>..., 'description'=>..., 'items'=>[...] ], ... ],
     *   ]
     */
    private function buildSystemValidation(): array
    {
        $groups = [];

        // ---- Group 1: Threshold invariants ----------------------------------
        $thresholdItems = [];

        // Upwell defaults — these are LOCKED in code, so verify the constant
        // ordering is sane. Critical must be < warning.
        $upwellCrit = \StructureManager\Helpers\FuelThresholds::UPWELL_FUEL_CRITICAL_DAYS;
        $upwellWarn = \StructureManager\Helpers\FuelThresholds::UPWELL_FUEL_WARNING_DAYS;
        $thresholdItems[] = [
            'label'   => 'Upwell critical < warning',
            'status'  => $upwellCrit < $upwellWarn ? 'ok' : 'error',
            'message' => "critical={$upwellCrit}d, warning={$upwellWarn}d",
        ];

        // POS defaults — DEFAULTS only (admins can override per install).
        // Validate the defaults make sense. Live values are checked in
        // Settings Health (Phase 3).
        $posCritDef  = \StructureManager\Helpers\FuelThresholds::POS_FUEL_CRITICAL_DAYS_DEFAULT;
        $posWarnDef  = \StructureManager\Helpers\FuelThresholds::POS_FUEL_WARNING_DAYS_DEFAULT;
        $thresholdItems[] = [
            'label'   => 'POS critical < warning (defaults)',
            'status'  => $posCritDef < $posWarnDef ? 'ok' : 'error',
            'message' => "critical={$posCritDef}d, warning={$posWarnDef}d",
        ];

        $stCrit = \StructureManager\Helpers\FuelThresholds::POS_STRONTIUM_CRITICAL_HOURS_DEFAULT;
        $stWarn = \StructureManager\Helpers\FuelThresholds::POS_STRONTIUM_WARNING_HOURS_DEFAULT;
        $stGood = \StructureManager\Helpers\FuelThresholds::POS_STRONTIUM_GOOD_HOURS_DEFAULT;
        $thresholdItems[] = [
            'label'   => 'POS strontium critical < warning < good (defaults)',
            'status'  => ($stCrit < $stWarn && $stWarn < $stGood) ? 'ok' : 'error',
            'message' => "critical={$stCrit}h, warning={$stWarn}h, good={$stGood}h",
        ];

        $charterCrit = \StructureManager\Helpers\FuelThresholds::POS_CHARTER_CRITICAL_DAYS_DEFAULT;
        $thresholdItems[] = [
            'label'   => 'POS charter critical (default) is positive',
            'status'  => $charterCrit > 0 ? 'ok' : 'error',
            'message' => "critical={$charterCrit}d",
        ];

        $pharCrit = \StructureManager\Helpers\FuelThresholds::PHAROLUX_LIQUID_OZONE_CRITICAL_QTY;
        $pharWarn = \StructureManager\Helpers\FuelThresholds::PHAROLUX_LIQUID_OZONE_WARNING_QTY;
        $thresholdItems[] = [
            'label'   => 'Pharolux liquid ozone critical < warning',
            'status'  => $pharCrit < $pharWarn ? 'ok' : 'error',
            'message' => "critical={$pharCrit} qty, warning={$pharWarn} qty",
        ];

        $tenCrit = \StructureManager\Helpers\FuelThresholds::TENEBREX_STRONTIUM_CRITICAL_QTY;
        $tenWarn = \StructureManager\Helpers\FuelThresholds::TENEBREX_STRONTIUM_WARNING_QTY;
        $thresholdItems[] = [
            'label'   => 'Tenebrex strontium critical < warning',
            'status'  => $tenCrit < $tenWarn ? 'ok' : 'error',
            'message' => "critical={$tenCrit} qty, warning={$tenWarn} qty",
        ];

        $groups[] = [
            'title' => 'Threshold invariants',
            'description' => 'Hardcoded threshold defaults must be ordered correctly (critical < warning < good). Live admin-set values are checked in Settings Health.',
            'items' => $thresholdItems,
        ];

        // ---- Group 2: Required helper / handler classes ---------------------
        $smClasses = [
            \StructureManager\Helpers\FuelCalculator::class,
            \StructureManager\Helpers\PosFuelCalculator::class,
            \StructureManager\Helpers\FuelThresholds::class,
            \StructureManager\Helpers\AlertEventEnvelope::class,
            \StructureManager\Helpers\TimerEventEnvelope::class,
            \StructureManager\Handlers\StructureEventHandler::class,
            \StructureManager\Services\WebhookDispatcher::class,
            \StructureManager\Services\TimerEventPublisher::class,
            \StructureManager\Services\FakeNotificationBuilder::class,
            \StructureManager\Services\TestDataGenerator::class,
            \StructureManager\Integrations\ManagerCoreIntegration::class,
        ];
        $smItems = [];
        foreach ($smClasses as $fqcn) {
            $smItems[] = [
                'label'   => $fqcn,
                'status'  => class_exists($fqcn) ? 'ok' : 'error',
                'message' => class_exists($fqcn) ? 'Class loaded' : 'Class NOT FOUND (autoload broken?)',
            ];
        }
        $groups[] = [
            'title' => 'Plugin classes (autoload sanity)',
            'description' => 'Verifies every helper, service, and handler the plugin relies on is reachable via the autoloader.',
            'items' => $smItems,
        ];

        // ---- Group 3: Required Eloquent models ------------------------------
        $smModels = [
            \StructureManager\Models\StructureFuelHistory::class,
            \StructureManager\Models\StarbaseFuelHistory::class,
            \StructureManager\Models\StructureNotificationStatus::class,
            \StructureManager\Models\EsiNotification::class,
            \StructureManager\Models\StructureManagerSettings::class,
            \StructureManager\Models\WebhookConfiguration::class,
        ];
        $modelItems = [];
        foreach ($smModels as $fqcn) {
            $modelItems[] = [
                'label'   => $fqcn,
                'status'  => class_exists($fqcn) ? 'ok' : 'error',
                'message' => class_exists($fqcn) ? 'Model loaded' : 'Model NOT FOUND',
            ];
        }
        $groups[] = [
            'title' => 'Plugin models',
            'description' => 'Eloquent models used by the plugin must be reachable. Missing models usually mean a botched composer install or a renamed class without an alias.',
            'items' => $modelItems,
        ];

        // ---- Group 4: Required SeAT classes ---------------------------------
        $seatClasses = [
            \Seat\Eveapi\Models\RefreshToken::class                          => 'OAuth tokens for ESI',
            \Seat\Eveapi\Models\Corporation\CorporationInfo::class           => 'Corp metadata',
            \Seat\Eveapi\Models\Corporation\CorporationStructure::class      => 'Upwell structures',
            \Seat\Eveapi\Models\Corporation\CorporationStarbase::class       => 'POSes',
            \Seat\Eveapi\Models\Universe\UniverseStructure::class            => 'Structure name resolver',
            \Seat\Eveapi\Models\Character\CharacterAffiliation::class        => 'Character → corp resolver',
        ];
        $seatItems = [];
        foreach ($seatClasses as $fqcn => $purpose) {
            $exists = class_exists($fqcn);
            $seatItems[] = [
                'label'   => $fqcn,
                'status'  => $exists ? 'ok' : 'error',
                'message' => $exists ? "OK ({$purpose})" : "MISSING. SeAT may have removed this class. Used for: {$purpose}",
            ];
        }
        // CharacterNotification lives in a different namespace depending on
        // SeAT version, check both spellings rather than fail.
        $charNotifClasses = [
            'Seat\\Eveapi\\Models\\Character\\CharacterNotification',
            'Seat\\Eveapi\\Models\\Character\\Notification',
        ];
        $charNotifFound = null;
        foreach ($charNotifClasses as $candidate) {
            if (class_exists($candidate)) { $charNotifFound = $candidate; break; }
        }
        $seatItems[] = [
            'label'   => 'CharacterNotification model',
            'status'  => $charNotifFound ? 'ok' : 'warn',
            'message' => $charNotifFound
                ? "Found: {$charNotifFound}"
                : 'Not found at expected namespaces. Sweep job uses raw character_notifications table directly so this is informational.',
        ];
        $groups[] = [
            'title' => 'SeAT package classes',
            'description' => 'SeAT models the plugin depends on. If any are missing, SeAT may have changed its layout in a major version bump.',
            'items' => $seatItems,
        ];

        // ---- Group 5: Required SDE / SeAT tables ----------------------------
        $tableItems = [];
        foreach (self::REQUIRED_SEAT_TABLES as $tbl) {
            $exists = Schema::hasTable($tbl);
            $tableItems[] = [
                'label'   => $tbl,
                'status'  => $exists ? 'ok' : 'error',
                'message' => $exists ? 'present' : 'MISSING',
            ];
        }
        $groups[] = [
            'title' => 'Required SeAT + SDE tables',
            'description' => 'Tables the plugin reads directly. Missing SDE tables usually mean the SDE seeder did not run; missing SeAT tables mean a migration was skipped or rolled back.',
            'items' => $tableItems,
        ];

        // ---- Group 6: Manager Core capability surface (when present) --------
        $mcClasses = [
            'ManagerCore\\Services\\ESI\\EsiNotificationRegistry' => 'ESI fast-poll notification registration',
            'ManagerCore\\Services\\PluginBridge'                  => 'Cross-plugin capability registry',
            'ManagerCore\\Services\\EventBus'                      => 'Cross-plugin event bus',
            'ManagerCore\\Services\\PricingService'                => 'Pricing service (Fuel Economics page consumer)',
            'ManagerCore\\Models\\PricingPreference'               => 'Per-plugin pricing preference model',
            'ManagerCore\\ManagerCoreServiceProvider'              => 'MC service provider',
        ];
        $mcItems = [];
        $mcAvailable = ManagerCoreIntegration::isAvailable();
        if (!$mcAvailable) {
            $mcItems[] = [
                'label'   => 'Manager Core',
                'status'  => 'info',
                'message' => 'Not installed. SM falls back to the every-minute SeAT character_notifications sweep (~ 15-20 min detection latency).',
            ];
        } else {
            foreach ($mcClasses as $fqcn => $purpose) {
                $exists = class_exists($fqcn);
                $mcItems[] = [
                    'label'   => $fqcn,
                    'status'  => $exists ? 'ok' : 'warn',
                    'message' => $exists ? "OK ({$purpose})" : "MC is detected but {$fqcn} missing (version mismatch or partial install).",
                ];
            }
        }
        $groups[] = [
            'title' => 'Manager Core capability surface',
            'description' => 'When MC is installed, SM uses it for ESI fast-poll (2-min detection) and cross-plugin events. SM works standalone without MC.',
            'items' => $mcItems,
        ];

        // ---- Group 7: Notification handler coverage -------------------------
        $allTypes = array_merge(
            \StructureManager\Handlers\StructureEventHandler::ATTACK_TYPES,
            \StructureManager\Handlers\StructureEventHandler::LIFECYCLE_TYPES,
            \StructureManager\Handlers\StructureEventHandler::FUEL_EVENT_TYPES,
            \StructureManager\Handlers\StructureEventHandler::SERVICES_OFFLINE_TYPES,
            \StructureManager\Handlers\StructureEventHandler::SOVEREIGNTY_TYPES,
        );
        $actualCount = count(array_unique($allTypes));
        $rawCount    = count($allTypes);
        $coverageItems = [];
        $coverageItems[] = [
            'label'   => 'Total declared notification types',
            'status'  => $actualCount > 0 ? 'info' : 'error',
            'message' => "Declared: {$actualCount} unique types across Attack ("
                . count(\StructureManager\Handlers\StructureEventHandler::ATTACK_TYPES) . "), Lifecycle ("
                . count(\StructureManager\Handlers\StructureEventHandler::LIFECYCLE_TYPES) . "), Fuel ("
                . count(\StructureManager\Handlers\StructureEventHandler::FUEL_EVENT_TYPES) . "), Services-Offline ("
                . count(\StructureManager\Handlers\StructureEventHandler::SERVICES_OFFLINE_TYPES) . "), Sovereignty ("
                . count(\StructureManager\Handlers\StructureEventHandler::SOVEREIGNTY_TYPES) . ") families.",
        ];
        $coverageItems[] = [
            'label'   => 'No duplicate type across families',
            'status'  => $actualCount === $rawCount ? 'ok' : 'error',
            'message' => $actualCount === $rawCount
                ? 'Each type appears in exactly one family.'
                : 'Same type listed in two families (webhook would fire twice for matching notifications).',
        ];
        $groups[] = [
            'title' => 'Notification handler coverage',
            'description' => 'Sanity-check that every notification type the plugin claims to handle is registered exactly once. If any duplicates appear, the dispatch path will fire twice.',
            'items' => $coverageItems,
        ];

        // ---- Group 8: PHP / Laravel versions --------------------------------
        $versionItems = [];
        $phpOk = version_compare(PHP_VERSION, '8.0.0', '>=');
        $versionItems[] = [
            'label'   => 'PHP version >= 8.0',
            'status'  => $phpOk ? 'ok' : 'error',
            'message' => 'Running PHP ' . PHP_VERSION,
        ];
        if (class_exists(\Illuminate\Foundation\Application::class)) {
            $laravel = \Illuminate\Foundation\Application::VERSION;
            $versionItems[] = [
                'label'   => 'Laravel framework',
                'status'  => 'info',
                'message' => "Running Laravel {$laravel}",
            ];
        }
        $groups[] = [
            'title' => 'PHP / Laravel baseline',
            'description' => 'Minimum runtime baseline the plugin assumes.',
            'items' => $versionItems,
        ];

        // ---- Aggregate counts ----------------------------------------------
        $counts = ['ok' => 0, 'warn' => 0, 'error' => 0, 'info' => 0];
        $total = 0;
        foreach ($groups as $g) {
            foreach ($g['items'] as $item) {
                $st = $item['status'] ?? 'info';
                if (!isset($counts[$st])) $st = 'info';
                $counts[$st]++;
                $total++;
            }
        }
        $overall = 'ok';
        if ($counts['error'] > 0) $overall = 'error';
        elseif ($counts['warn'] > 0) $overall = 'warn';

        return [
            'overall' => $overall,
            'counts'  => $counts,
            'total'   => $total,
            'groups'  => $groups,
        ];
    }

    /**
     * Build Settings Health audit. For every setting the plugin reads:
     *   - Show current value
     *   - Show default
     *   - Whether it has been changed from default
     *   - Whether it is respected (some legacy keys are read but ignored
     *     in favour of locked code constants)
     *   - Whether the value is valid (passes the per-setting validator)
     *
     * Then sweep the settings table for "orphan" keys (rows whose key is
     * not in the registry below). Orphan keys mean either:
     *   - A legacy migration left junk
     *   - The codebase was renamed but the old row was not cleaned up
     *   - Someone hand-edited the table and used a wrong key
     */
    private function buildSettingsHealth(): array
    {
        // Per-setting registry. The single source of truth for what
        // settings the plugin actively reads + how they are validated.
        $registry = [
            // ----- POS thresholds (configurable per install) ----------------
            'pos_fuel_critical_days' => [
                'category'    => 'POS fuel thresholds',
                'default'     => 7,
                'type'        => 'integer',
                'description' => 'Days remaining before POS fuel state goes critical.',
                'respected_by'=> ['FuelThresholds::posFuelCritical()', 'NotifyPosLowFuel', 'PosManagerController', 'StarbaseFuelHistory'],
                'respected'   => true,
                'validate'    => function ($v) { $i = (int) $v; return ($i >= 1 && $i <= 60) ? null : 'Must be 1-60 days'; },
            ],
            'pos_fuel_warning_days' => [
                'category'    => 'POS fuel thresholds',
                'default'     => 14,
                'type'        => 'integer',
                'description' => 'Days remaining before POS fuel state goes to warning.',
                'respected_by'=> ['FuelThresholds::posFuelWarning()', 'NotifyPosLowFuel', 'PosManagerController', 'StarbaseFuelHistory'],
                'respected'   => true,
                'validate'    => function ($v) { $i = (int) $v; return ($i >= 1 && $i <= 90) ? null : 'Must be 1-90 days'; },
            ],
            'pos_strontium_critical_hours' => [
                'category'    => 'POS strontium thresholds',
                'default'     => 6,
                'type'        => 'integer',
                'description' => 'Hours of strontium remaining before going critical.',
                'respected_by'=> ['FuelThresholds::posStrontiumCritical()', 'NotifyPosLowFuel', 'PosFuelCalculator'],
                'respected'   => true,
                'validate'    => function ($v) { $i = (int) $v; return ($i >= 1 && $i <= 48) ? null : 'Must be 1-48 hours'; },
            ],
            'pos_strontium_warning_hours' => [
                'category'    => 'POS strontium thresholds',
                'default'     => 12,
                'type'        => 'integer',
                'description' => 'Hours of strontium remaining before going to warning.',
                'respected_by'=> ['FuelThresholds::posStrontiumWarning()', 'NotifyPosLowFuel', 'PosFuelCalculator'],
                'respected'   => true,
                'validate'    => function ($v) { $i = (int) $v; return ($i >= 1 && $i <= 96) ? null : 'Must be 1-96 hours'; },
            ],
            'pos_strontium_good_hours' => [
                'category'    => 'POS strontium thresholds',
                'default'     => 24,
                'type'        => 'integer',
                'description' => 'Hours of strontium considered healthy stockpile.',
                'respected_by'=> ['FuelThresholds::posStrontiumGood()', 'PosFuelCalculator'],
                'respected'   => true,
                'validate'    => function ($v) { $i = (int) $v; return ($i >= 1 && $i <= 168) ? null : 'Must be 1-168 hours (1 week)'; },
            ],
            'pos_charter_critical_days' => [
                'category'    => 'POS charter thresholds',
                'default'     => 7,
                'type'        => 'integer',
                'description' => 'Days of charter remaining before going critical (high-sec only).',
                'respected_by'=> ['FuelThresholds::posCharterCritical()', 'NotifyPosLowFuel', 'StarbaseFuelHistory'],
                'respected'   => true,
                'validate'    => function ($v) { $i = (int) $v; return ($i >= 1 && $i <= 30) ? null : 'Must be 1-30 days'; },
            ],
            // ----- Notification cadence ------------------------------------
            'pos_fuel_notification_interval' => [
                'category'    => 'POS notifications',
                'default'     => 0,
                'type'        => 'integer',
                'description' => 'Hours between fuel/charter critical reminders. 0 = status change only.',
                'respected_by'=> ['NotifyPosLowFuel'],
                'respected'   => true,
                'validate'    => function ($v) { $i = (int) $v; return ($i >= 0 && $i <= 168) ? null : 'Must be 0-168 hours'; },
            ],
            'pos_strontium_notification_interval' => [
                'category'    => 'POS notifications',
                'default'     => 0,
                'type'        => 'integer',
                'description' => 'Hours between strontium critical reminders. 0 = status change only.',
                'respected_by'=> ['NotifyPosLowFuel'],
                'respected'   => true,
                'validate'    => function ($v) { $i = (int) $v; return ($i >= 0 && $i <= 168) ? null : 'Must be 0-168 hours'; },
            ],
            'pos_strontium_zero_notify_once' => [
                'category'    => 'POS notifications',
                'default'     => 1,
                'type'        => 'boolean',
                'description' => 'Suppress repeat alerts when strontium is exactly zero.',
                'respected_by'=> ['NotifyPosLowFuel'],
                'respected'   => true,
                'validate'    => function ($v) { return in_array((string) $v, ['0','1','true','false'], true) ? null : 'Must be 0 or 1'; },
            ],
            'pos_strontium_zero_grace_period' => [
                'category'    => 'POS notifications',
                'default'     => 2,
                'type'        => 'integer',
                'description' => 'Grace period (hours) for zero-strontium repeat suppression.',
                'respected_by'=> ['NotifyPosLowFuel'],
                'respected'   => true,
                'validate'    => function ($v) { $i = (int) $v; return ($i >= 0 && $i <= 24) ? null : 'Must be 0-24 hours'; },
            ],
            'pos_discord_role_mention' => [
                'category'    => 'POS notifications',
                'default'     => null,
                'type'        => 'string',
                'description' => 'Discord role mention for critical POS alerts (e.g. <@&123456789>).',
                'respected_by'=> ['NotifyPosLowFuel'],
                'respected'   => true,
                'validate'    => function ($v) {
                    if ($v === null || $v === '') return null;
                    return preg_match('/^<@&\d+>$/', (string) $v) ? null : 'Must be Discord role mention format <@&ID>';
                },
            ],
            // ----- Upwell notification cadence -----------------------------
            'upwell_fuel_notification_interval' => [
                'category'    => 'Upwell notifications',
                'default'     => 0,
                'type'        => 'integer',
                'description' => 'Hours between Upwell fuel critical reminders. 0 = status change only.',
                'respected_by'=> ['NotifyUpwellLowFuel'],
                'respected'   => true,
                'validate'    => function ($v) { $i = (int) $v; return ($i >= 0 && $i <= 168) ? null : 'Must be 0-168 hours'; },
            ],
            // ----- Notification category toggles ---------------------------
            'notify_structure_attack' => [
                'category'    => 'Notification categories',
                'default'     => 1,
                'type'        => 'boolean',
                'description' => 'Master toggle for the structure attack/loss notification family.',
                'respected_by'=> ['StructureEventHandler::dispatch()'],
                'respected'   => true,
                'validate'    => function ($v) { return in_array((string) $v, ['0','1','true','false'], true) ? null : 'Must be 0 or 1'; },
            ],
            'notify_structure_lifecycle' => [
                'category'    => 'Notification categories',
                'default'     => 1,
                'type'        => 'boolean',
                'description' => 'Master toggle for lifecycle notifications (anchor, transfer, etc.).',
                'respected_by'=> ['StructureEventHandler::dispatch()'],
                'respected'   => true,
                'validate'    => function ($v) { return in_array((string) $v, ['0','1','true','false'], true) ? null : 'Must be 0 or 1'; },
            ],
            'notify_structure_fuel_events' => [
                'category'    => 'Notification categories',
                'default'     => 1,
                'type'        => 'boolean',
                'description' => 'Master toggle for fuel state change notifications (low power, online, etc.).',
                'respected_by'=> ['StructureEventHandler::dispatch()'],
                'respected'   => true,
                'validate'    => function ($v) { return in_array((string) $v, ['0','1','true','false'], true) ? null : 'Must be 0 or 1'; },
            ],
            'esi_attack_role_mention' => [
                'category'    => 'Notification categories',
                'default'     => null,
                'type'        => 'string',
                'description' => 'Discord role mention for structure attack alerts.',
                'respected_by'=> ['StructureEventHandler::dispatch()'],
                'respected'   => true,
                'validate'    => function ($v) {
                    if ($v === null || $v === '') return null;
                    return preg_match('/^<@&\d+>$/', (string) $v) ? null : 'Must be Discord role mention format <@&ID>';
                },
            ],
            // ----- ESI fast-poll integration -------------------------------
            'esi_polling_enabled' => [
                'category'    => 'ESI integration',
                'default'     => 1,
                'type'        => 'boolean',
                'description' => 'Whether SM should request MC fast-poll on its behalf when MC is installed.',
                'respected_by'=> ['ManagerCoreIntegration::shouldFastPoll()'],
                'respected'   => true,
                'validate'    => function ($v) { return in_array((string) $v, ['0','1','true','false'], true) ? null : 'Must be 0 or 1'; },
            ],
            'esi_polling_interval' => [
                'category'    => 'ESI integration',
                'default'     => 2,
                'type'        => 'integer',
                'description' => 'Informational. Actual polling cadence is controlled by MC. Stored for UI consistency.',
                'respected_by'=> [],
                'respected'   => false,
                'respected_note' => 'Display-only. MC controls poll cadence directly.',
                'validate'    => function ($v) { $i = (int) $v; return ($i >= 1 && $i <= 60) ? null : 'Must be 1-60 minutes'; },
            ],
            'esi_detection_mode' => [
                'category'    => 'ESI integration',
                'default'     => null,
                'type'        => 'string',
                'description' => 'auto | mc | seat. null means auto-detect (default).',
                'respected_by'=> ['ManagerCoreIntegration'],
                'respected'   => true,
                'validate'    => function ($v) {
                    if ($v === null || $v === '') return null;
                    return in_array((string) $v, ['auto','mc','seat'], true) ? null : "Must be 'auto', 'mc', 'seat', or unset";
                },
            ],
            // ----- Reserves / hangars --------------------------------------
            'excluded_hangars' => [
                'category'    => 'Reserves',
                'default'     => '[]',
                'type'        => 'json',
                'description' => 'Array of hangar division numbers to exclude from fuel reserve scans.',
                'respected_by'=> ['FuelReserveController'],
                'respected'   => true,
                'validate'    => function ($v) {
                    if ($v === null || $v === '') return null;
                    if (is_array($v)) return null;
                    $decoded = json_decode((string) $v, true);
                    return is_array($decoded) ? null : 'Must be JSON array of hangar numbers';
                },
            ],
            // ----- Test webhook --------------------------------------------
            'test_webhook_url' => [
                'category'    => 'Diagnostic',
                'default'     => '',
                'type'        => 'string',
                'description' => 'Notification Lab routes synthetic notifications here only. Empty = lab disabled.',
                'respected_by'=> ['Notification Lab', 'StructureEventHandler (test mode)'],
                'respected'   => true,
                'validate'    => function ($v) {
                    if ($v === null || $v === '') return null;
                    return filter_var((string) $v, FILTER_VALIDATE_URL) !== false ? null : 'Must be a valid URL';
                },
            ],
            // ----- Structure board defaults --------------------------------
            'command_board_default_window_days' => [
                'category'    => 'Structure board',
                'default'     => 7,
                'type'        => 'integer',
                'description' => 'Default window for board view in days.',
                'respected_by'=> ['StructureBoardController'],
                'respected'   => true,
                'validate'    => function ($v) { $i = (int) $v; return ($i >= 1 && $i <= 30) ? null : 'Must be 1-30 days'; },
            ],
            'command_board_default_opsec_role_id' => [
                'category'    => 'Structure board',
                'default'     => 0,
                'type'        => 'integer',
                'description' => 'Default OPSEC role for board visibility. 0 = none.',
                'respected_by'=> ['StructureBoardController'],
                'respected'   => true,
                'validate'    => function ($v) { $i = (int) $v; return ($i >= 0) ? null : 'Must be >= 0'; },
            ],
            // ----- Structure data staleness --------------------------------
            'stale_structure_threshold_days' => [
                'category'    => 'Structure visibility',
                'default'     => 30,
                'type'        => 'integer',
                'description' => 'Hide Upwell structures whose ESI data has not refreshed in this many days (corp removed its token). 0 = disabled, show everything. POSes are exempt: a starbase row freezes when the tower is stable/offline, so updated_at age is not a reliable staleness signal for towers.',
                'respected_by'=> ['FuelThresholds::staleStructureCutoff()', 'StructureManagerController', 'FuelAlertController (Upwell queries only)'],
                'respected'   => true,
                'validate'    => function ($v) { $i = (int) $v; return ($i >= 0 && $i <= 365) ? null : 'Must be 0-365 days'; },
            ],
            // ----- Manager Core integration --------------------------------
            'economics_pricing_mode' => [
                'category'    => 'Manager Core integration',
                'default'     => 'auto',
                'type'        => 'string',
                'description' => 'Fuel Economics page mode: auto (use Manager Core pricing when installed) or disabled (hide the page).',
                'respected_by'=> ['ManagerCoreIntegration::economicsPricingMode()', 'EconomicsController'],
                'respected'   => true,
                'validate'    => function ($v) {
                    if ($v === null || $v === '') return null;
                    return in_array((string) $v, ['auto', 'disabled'], true) ? null : "Must be 'auto' or 'disabled'";
                },
            ],
            // ----- Pre-timer reminders / threat intel ----------------------
            'pre_timer_reminders_enabled' => [
                'category'    => 'Pre-timer reminders',
                'default'     => 1,
                'type'        => 'boolean',
                'description' => 'Master kill-switch for all pre-timer reminder pings. Off silences every reminder regardless of category bindings.',
                'respected_by'=> ['PreTimerReminderHandler'],
                'respected'   => true,
                'validate'    => function ($v) { return in_array((string) $v, ['0','1','true','false'], true) ? null : 'Must be 0 or 1'; },
            ],
            'attacker_threat_intel_enabled' => [
                'category'    => 'Attacker threat intel',
                'default'     => 0,
                'type'        => 'boolean',
                'description' => 'Master toggle for the zKillboard attacker threat-intel follow-up embed. Opt-in — default off.',
                'respected_by'=> ['DispatchAttackerThreatIntel'],
                'respected'   => true,
                'validate'    => function ($v) { return in_array((string) $v, ['0','1','true','false'], true) ? null : 'Must be 0 or 1'; },
            ],
            // ----- DEPRECATED / locked-in-code -----------------------------
            'upwell_fuel_critical_days' => [
                'category'    => 'Upwell thresholds (LOCKED)',
                'default'     => 7,
                'type'        => 'integer',
                'description' => 'LOCKED in code as 7d. Any value here is ignored.',
                'respected_by'=> [],
                'respected'   => false,
                'respected_note' => 'IGNORED. Hardcoded as FuelThresholds::UPWELL_FUEL_CRITICAL_DAYS = 7.',
                'deprecated'  => true,
                'validate'    => function ($v) { return null; },
            ],
            'upwell_fuel_warning_days' => [
                'category'    => 'Upwell thresholds (LOCKED)',
                'default'     => 14,
                'type'        => 'integer',
                'description' => 'LOCKED in code as 14d. Any value here is ignored.',
                'respected_by'=> [],
                'respected'   => false,
                'respected_note' => 'IGNORED. Hardcoded as FuelThresholds::UPWELL_FUEL_WARNING_DAYS = 14.',
                'deprecated'  => true,
                'validate'    => function ($v) { return null; },
            ],
            'pos_webhook_url' => [
                'category'    => 'Legacy webhook',
                'default'     => null,
                'type'        => 'string',
                'description' => 'Legacy single-webhook field. Replaced by structure_manager_webhooks table in 000016.',
                'respected_by'=> [],
                'respected'   => false,
                'respected_note' => 'IGNORED. Use Webhook Configuration tab instead.',
                'deprecated'  => true,
                'validate'    => function ($v) { return null; },
            ],
            'pos_webhook_enabled' => [
                'category'    => 'Legacy webhook',
                'default'     => '0',
                'type'        => 'boolean',
                'description' => 'Legacy enable flag for single-webhook field. Replaced by per-row enabled flag in 000016.',
                'respected_by'=> [],
                'respected'   => false,
                'respected_note' => 'IGNORED. Use Webhook Configuration tab instead.',
                'deprecated'  => true,
                'validate'    => function ($v) { return null; },
            ],
        ];

        // Pull live values for everything we know about
        $allRows = DB::table('structure_manager_settings')
            ->get(['key', 'value', 'type', 'category'])
            ->keyBy('key');

        $items = [];
        foreach ($registry as $key => $meta) {
            $row = $allRows->get($key);
            $hasRow = $row !== null;
            $rawValue = $hasRow ? $row->value : $meta['default'];

            // Coerce default for display
            $defaultDisplay = $meta['default'] === null
                ? '(null)'
                : (is_array($meta['default']) ? json_encode($meta['default']) : (string) $meta['default']);
            $valueDisplay = $rawValue === null
                ? '(null)'
                : (is_array($rawValue) ? json_encode($rawValue) : (string) $rawValue);

            $changed = $hasRow && (string) $rawValue !== (string) ($meta['default'] ?? '');

            // Validation
            $validateError = null;
            try {
                $validateError = ($meta['validate'])($rawValue);
            } catch (\Throwable $e) {
                $validateError = 'Validator threw: ' . $e->getMessage();
            }
            $valid = $validateError === null;

            // Status determination
            $isDeprecated = !empty($meta['deprecated']);
            $respected    = !empty($meta['respected']);

            if (!$valid) {
                $status = 'error';
                $statusMsg = "Invalid: {$validateError}";
            } elseif ($isDeprecated && $changed) {
                $status = 'warn';
                $statusMsg = 'Custom value set on a deprecated setting. ' . ($meta['respected_note'] ?? '');
            } elseif (!$respected && $changed) {
                $status = 'warn';
                $statusMsg = 'Setting changed but not respected. ' . ($meta['respected_note'] ?? '');
            } elseif (!$respected) {
                $status = 'info';
                $statusMsg = $meta['respected_note'] ?? 'Stored but not actively read.';
            } elseif ($changed) {
                $status = 'ok';
                $statusMsg = 'Changed from default and respected by ' . count($meta['respected_by']) . ' surface(s).';
            } else {
                $status = 'ok';
                $statusMsg = 'At default value.';
            }

            $items[] = [
                'key'           => $key,
                'category'      => $meta['category'],
                'value'         => $valueDisplay,
                'default'       => $defaultDisplay,
                'type'          => $meta['type'],
                'description'   => $meta['description'],
                'respected_by'  => $meta['respected_by'],
                'respected'     => $respected,
                'changed'       => $changed,
                'has_row'       => $hasRow,
                'deprecated'    => $isDeprecated,
                'valid'         => $valid,
                'validation_msg'=> $validateError,
                'status'        => $status,
                'status_msg'    => $statusMsg,
            ];
        }

        // Hide deprecated settings that are at default — they're pure
        // noise when the operator hasn't set them. The audit STILL knows
        // about them (so a WARN fires the moment someone sets a value),
        // but they don't clutter the UI when there's nothing to act on.
        // Counted separately so we can show a small hint at the bottom.
        $hiddenDeprecated = [];
        $visibleItems = [];
        foreach ($items as $item) {
            if ($item['deprecated'] && !$item['changed']) {
                $hiddenDeprecated[] = $item['key'];
                continue;
            }
            $visibleItems[] = $item;
        }

        // Group VISIBLE items by category. Empty categories disappear
        // automatically (no rendered header for a category with 0 items).
        $byCategory = [];
        foreach ($visibleItems as $item) {
            $byCategory[$item['category']][] = $item;
        }

        // Detect orphan keys (in DB but not in registry)
        $registeredKeys = array_keys($registry);
        $dbKeys = $allRows->keys()->toArray();
        $orphans = array_diff($dbKeys, $registeredKeys);

        // Counts across VISIBLE items + orphan flag. Hidden deprecated-at-
        // default rows don't contribute - they're not problems.
        $counts = ['ok' => 0, 'warn' => 0, 'error' => 0, 'info' => 0];
        foreach ($visibleItems as $item) {
            $counts[$item['status']]++;
        }
        if (count($orphans) > 0) {
            $counts['warn']++;
        }
        $total = count($visibleItems) + (count($orphans) > 0 ? 1 : 0);

        $overall = 'ok';
        if ($counts['error'] > 0) $overall = 'error';
        elseif ($counts['warn'] > 0) $overall = 'warn';

        return [
            'overall'           => $overall,
            'counts'            => $counts,
            'total'             => $total,
            'by_category'       => $byCategory,
            'orphans'           => array_values($orphans),
            'registered_count'  => count($registry),
            'db_row_count'      => count($dbKeys),
            'hidden_deprecated' => $hiddenDeprecated,
        ];
    }

    /**
     * Build Data Integrity audit. Read-only DB-level consistency checks.
     * Walks every plugin-owned table, counts rows, flags FK orphans and
     * stale dedup rows, and reports the failed-jobs queue.
     *
     * Output groups:
     *   - Plugin table inventory (row counts + issue counts)
     *   - FK / orphan checks
     *   - Stale dedup rows
     *   - Failed jobs (Laravel queue)
     *   - Settings table integrity
     */
    private function buildDataIntegrity(): array
    {
        $groups = [];

        // ---- Group 1: Plugin table row inventory ---------------------------
        // Row counts come from information_schema.TABLES in ONE query rather
        // than a COUNT(*) per table. TABLE_ROWS is an InnoDB estimate (it can
        // drift from the exact count on large tables), which is fine for an
        // "is this table growing / suspiciously empty" inventory — and it
        // avoids the per-table full-index COUNT scan that the class comment
        // above flags as the dominant page-load cost on installs with months
        // of hourly fuel history. When the estimate is 0 we fall back to an
        // exact COUNT(*) — instant on a genuinely empty / tiny table — so the
        // "0 rows = stuck job" signal stays trustworthy.
        $rowEstimates = [];
        try {
            $placeholders = implode(',', array_fill(0, count(self::PLUGIN_TABLES), '?'));
            $estRows = DB::select(
                "SELECT TABLE_NAME AS t, TABLE_ROWS AS r
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$placeholders})",
                self::PLUGIN_TABLES
            );
            foreach ($estRows as $row) {
                $rowEstimates[$row->t] = (int) $row->r;
            }
        } catch (\Throwable $e) {
            // information_schema unavailable — the loop falls back to exact COUNT.
        }

        $tableInventory = [];
        foreach (self::PLUGIN_TABLES as $tbl) {
            if (!Schema::hasTable($tbl)) {
                $tableInventory[] = [
                    'label'   => $tbl,
                    'status'  => 'error',
                    'message' => 'Table missing.',
                ];
                continue;
            }
            try {
                $estimate = $rowEstimates[$tbl] ?? null;
                if ($estimate !== null && $estimate > 0) {
                    $tableInventory[] = [
                        'label'   => $tbl,
                        'status'  => 'info',
                        'message' => '~' . number_format($estimate) . ' rows (approx)',
                    ];
                } else {
                    // Estimate is 0 or unavailable — confirm with an exact
                    // count (cheap when the table really is empty or small).
                    $exact = DB::table($tbl)->count();
                    $tableInventory[] = [
                        'label'   => $tbl,
                        'status'  => 'info',
                        'message' => number_format($exact) . ' rows',
                    ];
                }
            } catch (\Throwable $e) {
                $tableInventory[] = [
                    'label'   => $tbl,
                    'status'  => 'warn',
                    'message' => 'Count failed: ' . $e->getMessage(),
                ];
            }
        }
        $groups[] = [
            'title' => 'Plugin table inventory',
            'description' => 'Row counts per plugin-owned table (approximate, from information_schema, to keep this tab fast on large installs — tables reading 0 are confirmed with an exact count). A table that mysteriously stops growing or shows zero rows can hint at a stuck job.',
            'items' => $tableInventory,
        ];

        // ---- Group 2: Orphan FK checks -------------------------------------
        $orphanChecks = [];

        // structure_fuel_history.structure_id should exist in corporation_structures
        if (Schema::hasTable('structure_fuel_history') && Schema::hasTable('corporation_structures')) {
            $orphans = DB::table('structure_fuel_history as sfh')
                ->leftJoin('corporation_structures as cs', 'sfh.structure_id', '=', 'cs.structure_id')
                ->whereNull('cs.structure_id')
                ->count();
            $orphanChecks[] = [
                'label'   => 'structure_fuel_history orphans',
                'status'  => $orphans === 0 ? 'ok' : 'warn',
                'message' => $orphans === 0
                    ? 'All rows reference a known structure.'
                    : "{$orphans} rows reference a structure_id missing from corporation_structures. Could be unanchored / abandoned structures still in fuel history.",
            ];
        }

        // structure_fuel_reserves.structure_id
        if (Schema::hasTable('structure_fuel_reserves') && Schema::hasTable('corporation_structures')) {
            $orphans = DB::table('structure_fuel_reserves as sfr')
                ->leftJoin('corporation_structures as cs', 'sfr.structure_id', '=', 'cs.structure_id')
                ->whereNull('cs.structure_id')
                ->count();
            $orphanChecks[] = [
                'label'   => 'structure_fuel_reserves orphans',
                'status'  => $orphans === 0 ? 'ok' : 'warn',
                'message' => $orphans === 0
                    ? 'All rows reference a known structure.'
                    : "{$orphans} rows reference a structure_id missing from corporation_structures.",
            ];
        }

        // structure_notification_status.structure_id
        if (Schema::hasTable('structure_notification_status') && Schema::hasTable('corporation_structures')) {
            $orphans = DB::table('structure_notification_status as sns')
                ->leftJoin('corporation_structures as cs', 'sns.structure_id', '=', 'cs.structure_id')
                ->whereNull('cs.structure_id')
                ->count();
            $orphanChecks[] = [
                'label'   => 'structure_notification_status orphans',
                'status'  => $orphans === 0 ? 'ok' : 'warn',
                'message' => $orphans === 0
                    ? 'All status rows reference a known structure.'
                    : "{$orphans} dedup rows for structures that no longer exist.",
            ];
        }

        // starbase_fuel_history.starbase_id
        if (Schema::hasTable('starbase_fuel_history') && Schema::hasTable('corporation_starbases')) {
            $orphans = DB::table('starbase_fuel_history as sfh')
                ->leftJoin('corporation_starbases as csb', 'sfh.starbase_id', '=', 'csb.starbase_id')
                ->whereNull('csb.starbase_id')
                ->count();
            $orphanChecks[] = [
                'label'   => 'starbase_fuel_history orphans',
                'status'  => $orphans === 0 ? 'ok' : 'warn',
                'message' => $orphans === 0
                    ? 'All rows reference a known POS.'
                    : "{$orphans} rows reference a starbase_id missing from corporation_starbases.",
            ];
        }

        // starbase_fuel_reserves.starbase_id
        if (Schema::hasTable('starbase_fuel_reserves') && Schema::hasTable('corporation_starbases')) {
            $orphans = DB::table('starbase_fuel_reserves as sfr')
                ->leftJoin('corporation_starbases as csb', 'sfr.starbase_id', '=', 'csb.starbase_id')
                ->whereNull('csb.starbase_id')
                ->count();
            $orphanChecks[] = [
                'label'   => 'starbase_fuel_reserves orphans',
                'status'  => $orphans === 0 ? 'ok' : 'warn',
                'message' => $orphans === 0
                    ? 'All rows reference a known POS.'
                    : "{$orphans} rows reference a starbase_id missing from corporation_starbases.",
            ];
        }

        // starbase_fuel_consumption.starbase_id
        if (Schema::hasTable('starbase_fuel_consumption') && Schema::hasTable('corporation_starbases')) {
            $orphans = DB::table('starbase_fuel_consumption as sfc')
                ->leftJoin('corporation_starbases as csb', 'sfc.starbase_id', '=', 'csb.starbase_id')
                ->whereNull('csb.starbase_id')
                ->count();
            $orphanChecks[] = [
                'label'   => 'starbase_fuel_consumption orphans',
                'status'  => $orphans === 0 ? 'ok' : 'warn',
                'message' => $orphans === 0
                    ? 'All consumption records reference a known POS.'
                    : "{$orphans} consumption records reference a missing POS.",
            ];
        }

        // structure_manager_webhooks.corporation_id (where non-null)
        if (Schema::hasTable('structure_manager_webhooks') && Schema::hasTable('corporation_infos')) {
            $orphans = DB::table('structure_manager_webhooks as smw')
                ->whereNotNull('smw.corporation_id')
                ->leftJoin('corporation_infos as ci', 'smw.corporation_id', '=', 'ci.corporation_id')
                ->whereNull('ci.corporation_id')
                ->count();
            $orphanChecks[] = [
                'label'   => 'structure_manager_webhooks corporation orphans',
                'status'  => $orphans === 0 ? 'ok' : 'warn',
                'message' => $orphans === 0
                    ? 'All scoped webhooks reference a known corp (or are global).'
                    : "{$orphans} webhooks reference a corporation_id missing from corporation_infos. Webhook will silently fail to dispatch.",
            ];
        }

        // structure_manager_category_webhook.webhook_id and category_id orphans
        if (Schema::hasTable('structure_manager_category_webhook')) {
            $cwOrphans = 0;
            if (Schema::hasTable('structure_manager_webhooks')) {
                $cwOrphans += DB::table('structure_manager_category_webhook as cw')
                    ->leftJoin('structure_manager_webhooks as w', 'cw.webhook_id', '=', 'w.id')
                    ->whereNull('w.id')
                    ->count();
            }
            if (Schema::hasTable('structure_manager_notification_categories')) {
                $cwOrphans += DB::table('structure_manager_category_webhook as cw')
                    ->leftJoin('structure_manager_notification_categories as nc', 'cw.category_id', '=', 'nc.id')
                    ->whereNull('nc.id')
                    ->count();
            }
            $orphanChecks[] = [
                'label'   => 'category_webhook bindings orphans',
                'status'  => $cwOrphans === 0 ? 'ok' : 'warn',
                'message' => $cwOrphans === 0
                    ? 'All bindings reference live webhooks and categories.'
                    : "{$cwOrphans} binding rows point at a deleted webhook or category. Binding will silently no-op.",
            ];
        }

        $groups[] = [
            'title' => 'Foreign key consistency',
            'description' => 'Orphan rows mean the parent row was deleted but the child was not cascade-cleaned. Usually safe to leave but indicates incomplete teardown.',
            'items' => $orphanChecks,
        ];

        // ---- Group 2.5: Snapshot poll coverage (last 24h) ------------------
        //
        // Counts actual rows in *_fuel_history per structure / starbase over
        // the past 24h vs the expected cadence (hourly for Upwell, every
        // 10 min for POS). Surfaces:
        //   - a summary line (total structures, total missed polls)
        //   - the top N worst offenders so a sustained issue on a specific
        //     structure stands out without flooding the page on large installs
        //
        // Why this matters: the v2.0.2 race guards intentionally SKIP writing
        // a snapshot when SeAT's corporation_assets table is mid-refresh,
        // rather than write a wrong value. The trade-off is gaps in the
        // history rather than poisoned rows — which is preferable for every
        // downstream consumer (consumption chart, event classifier, refuel
        // detection all normalise by hours_elapsed) but worth surfacing here
        // so operators can spot if the underlying SeAT problem is rare
        // (1-3 misses/24h = noise) or sustained (12+/24h = SeAT-side
        // investigation needed). Excessive misses also predict that real
        // critical notifications will be 10 min delayed at most.
        //
        // Cap the per-structure list at 10 rows so a misconfigured install
        // doesn't render a 200-row group; the summary still reports the
        // full total miss count across all structures regardless.
        $coverageChecks = [];
        $MAX_FLAGGED_PER_TABLE = 10;
        $oneDayAgo = now()->subDay();

        // Upwell coverage — structure_fuel_history (hourly cron, 24 expected)
        if (Schema::hasTable('structure_fuel_history') && Schema::hasTable('corporation_structures')) {
            try {
                $pollCounts = DB::table('structure_fuel_history')
                    ->where('created_at', '>=', $oneDayAgo)
                    ->select('structure_id', DB::raw('COUNT(*) as cnt'))
                    ->groupBy('structure_id')
                    ->pluck('cnt', 'structure_id')
                    ->all();

                // Only include structures known to SeAT for >24h, otherwise a
                // freshly-added structure looks like a permanent miss.
                $structures = DB::table('corporation_structures as cs')
                    ->leftJoin('universe_structures as us', 'cs.structure_id', '=', 'us.structure_id')
                    ->whereNotNull('cs.fuel_expires')
                    ->where(function ($q) use ($oneDayAgo) {
                        $q->where('cs.created_at', '<', $oneDayAgo)
                          ->orWhereNull('cs.created_at');
                    })
                    ->select('cs.structure_id', 'us.name as structure_name')
                    ->get();

                $totalStructures = $structures->count();
                $totalMissed = 0;
                $flagged = [];

                foreach ($structures as $s) {
                    $actual = (int) ($pollCounts[$s->structure_id] ?? 0);
                    // Hourly cron — 24 expected. Cap at 24 so a structure
                    // with multiple snapshots per hour (shouldn't happen but
                    // defensive) doesn't underflow.
                    $missed = max(0, 24 - min($actual, 24));
                    $totalMissed += $missed;
                    if ($missed > 0) {
                        $flagged[] = [
                            'structure_id' => $s->structure_id,
                            'name'         => $s->structure_name ?? ('Structure #' . $s->structure_id),
                            'actual'       => $actual,
                            'missed'       => $missed,
                        ];
                    }
                }

                usort($flagged, fn($a, $b) => $b['missed'] <=> $a['missed']);
                $totalFlagged = count($flagged);

                $summaryStatus = $totalMissed === 0
                    ? 'ok'
                    : ($totalFlagged < max(1, (int) ($totalStructures * 0.5)) && $totalMissed < $totalStructures * 4 ? 'info' : 'warn');

                $summaryMessage = $totalMissed === 0
                    ? sprintf('%d Upwell structure(s) all have full 24/24 coverage in the past 24h.', $totalStructures)
                    : sprintf(
                        '%d of %d structures missed at least one snapshot in the last 24h (%d total misses). Expected: 24 per structure (hourly track-fuel cron). Frequent misses can indicate SeAT corp-assets refresh races (see NotifyUpwellLowFuel and TrackFuelConsumption race-guard warnings in laravel.log), cron queue lag, or sustained ESI scope issues.',
                        $totalFlagged,
                        $totalStructures,
                        $totalMissed
                    );

                $coverageChecks[] = [
                    'label'   => 'Upwell snapshot coverage (last 24h)',
                    'status'  => $summaryStatus,
                    'message' => $summaryMessage,
                ];

                foreach (array_slice($flagged, 0, $MAX_FLAGGED_PER_TABLE) as $row) {
                    $coverageChecks[] = [
                        'label'   => sprintf('• %s (%d/24)', $row['name'], $row['actual']),
                        'status'  => $row['missed'] >= 12 ? 'warn' : ($row['missed'] >= 4 ? 'info' : 'ok'),
                        'message' => sprintf('%d missed snapshot(s) in the last 24h.', $row['missed']),
                    ];
                }
                if ($totalFlagged > $MAX_FLAGGED_PER_TABLE) {
                    $coverageChecks[] = [
                        'label'   => sprintf('… and %d more flagged structure(s)', $totalFlagged - $MAX_FLAGGED_PER_TABLE),
                        'status'  => 'info',
                        'message' => 'Top 10 shown by miss count. Re-check after a known SeAT incident to see if the list shrinks.',
                    ];
                }
            } catch (\Throwable $e) {
                $coverageChecks[] = [
                    'label'   => 'Upwell snapshot coverage (last 24h)',
                    'status'  => 'warn',
                    'message' => 'Coverage check failed: ' . $e->getMessage(),
                ];
            }
        }

        // POS coverage — starbase_fuel_history (10-min cron, 144 expected)
        if (Schema::hasTable('starbase_fuel_history') && Schema::hasTable('corporation_starbases')) {
            try {
                $pollCounts = DB::table('starbase_fuel_history')
                    ->where('created_at', '>=', $oneDayAgo)
                    ->select('starbase_id', DB::raw('COUNT(*) as cnt'))
                    ->groupBy('starbase_id')
                    ->pluck('cnt', 'starbase_id')
                    ->all();

                $starbases = DB::table('corporation_starbases as csb')
                    ->leftJoin('universe_structures as us', 'csb.starbase_id', '=', 'us.structure_id')
                    ->where(function ($q) use ($oneDayAgo) {
                        $q->where('csb.created_at', '<', $oneDayAgo)
                          ->orWhereNull('csb.created_at');
                    })
                    ->select('csb.starbase_id', 'us.name as starbase_name', 'csb.state')
                    ->get();

                $totalPos = $starbases->count();
                $totalMissed = 0;
                $flagged = [];

                foreach ($starbases as $sb) {
                    // POSes only get polled while online/reinforced — skip
                    // offline/unanchoring/anchored states (state codes are
                    // strings in SeAT v5: 'online'=4, 'reinforced'=3, etc.).
                    // A POS in 'offline' legitimately has no fuel history rows
                    // and shouldn't be counted as a miss.
                    $state = (string) ($sb->state ?? '');
                    if (!in_array($state, ['online', 'reinforced', '4', '3'], true)) {
                        continue;
                    }

                    $actual = (int) ($pollCounts[$sb->starbase_id] ?? 0);
                    $missed = max(0, 144 - min($actual, 144));
                    $totalMissed += $missed;
                    if ($missed >= 6) {
                        // Only flag POSes that missed 6+ polls (~ 1h of polls).
                        // Random 1-2 misses on the 10-min cron are noise.
                        $flagged[] = [
                            'starbase_id' => $sb->starbase_id,
                            'name'        => $sb->starbase_name ?? ('POS #' . $sb->starbase_id),
                            'actual'      => $actual,
                            'missed'      => $missed,
                        ];
                    }
                }

                usort($flagged, fn($a, $b) => $b['missed'] <=> $a['missed']);
                $totalFlagged = count($flagged);

                $summaryStatus = $totalMissed === 0
                    ? 'ok'
                    : ($totalFlagged < max(1, (int) ($totalPos * 0.5)) ? 'info' : 'warn');

                $summaryMessage = $totalPos === 0
                    ? 'No online or reinforced POSes to check.'
                    : ($totalMissed === 0
                        ? sprintf('%d active POS(es) all have full 144/144 coverage in the past 24h.', $totalPos)
                        : sprintf(
                            '%d of %d active POS(es) missed 6+ snapshots in the last 24h (%d total misses). Expected: 144 per POS (10-min track-poses-fuel cron). Flagging threshold is 6+ to filter noise. Sustained misses point at cron worker lag or ESI key issues.',
                            $totalFlagged,
                            $totalPos,
                            $totalMissed
                        ));

                $coverageChecks[] = [
                    'label'   => 'POS snapshot coverage (last 24h)',
                    'status'  => $summaryStatus,
                    'message' => $summaryMessage,
                ];

                foreach (array_slice($flagged, 0, $MAX_FLAGGED_PER_TABLE) as $row) {
                    $coverageChecks[] = [
                        'label'   => sprintf('• %s (%d/144)', $row['name'], $row['actual']),
                        'status'  => $row['missed'] >= 72 ? 'warn' : 'info',
                        'message' => sprintf('%d missed snapshot(s) in the last 24h.', $row['missed']),
                    ];
                }
                if ($totalFlagged > $MAX_FLAGGED_PER_TABLE) {
                    $coverageChecks[] = [
                        'label'   => sprintf('… and %d more flagged POS(es)', $totalFlagged - $MAX_FLAGGED_PER_TABLE),
                        'status'  => 'info',
                        'message' => 'Top 10 shown by miss count.',
                    ];
                }
            } catch (\Throwable $e) {
                $coverageChecks[] = [
                    'label'   => 'POS snapshot coverage (last 24h)',
                    'status'  => 'warn',
                    'message' => 'Coverage check failed: ' . $e->getMessage(),
                ];
            }
        }

        if (!empty($coverageChecks)) {
            $groups[] = [
                'title' => 'Snapshot poll coverage (last 24h)',
                'description' => 'Counts actual fuel-history snapshots per structure vs the expected hourly (Upwell) or 10-minute (POS) cadence. Missed snapshots arise from SeAT corp-assets refresh races (the v2.0.2 race guards intentionally skip writing rows during the SeAT DELETE-INSERT window rather than recording wrong values), worker queue lag, or sustained ESI scope issues. Rare misses are healthy gap-tolerance; sustained or clustered misses are a signal to investigate upstream.',
                'items' => $coverageChecks,
            ];
        }

        // ---- Group 3: Stale dedup rows -------------------------------------
        $staleChecks = [];

        // ESI notifications older than 30 days
        if (Schema::hasTable('structure_manager_esi_notifications')) {
            $cutoff = now()->subDays(30);
            $stale = DB::table('structure_manager_esi_notifications')
                ->where('created_at', '<', $cutoff)
                ->count();
            $total = DB::table('structure_manager_esi_notifications')->count();
            $staleChecks[] = [
                'label'   => 'structure_manager_esi_notifications older than 30 days',
                'status'  => $stale === 0 ? 'ok' : ($stale < 1000 ? 'info' : 'warn'),
                'message' => $stale === 0
                    ? "Total rows: {$total}. No stale entries."
                    : "Stale: " . number_format($stale) . " of " . number_format($total) . " total. Cleanup is scheduled by structure-manager:cleanup-history (daily).",
            ];
        }

        // structure_notification_status — rows that have not been touched in 90 days
        if (Schema::hasTable('structure_notification_status')) {
            $cutoff = now()->subDays(90);
            $stale = DB::table('structure_notification_status')
                ->where('updated_at', '<', $cutoff)
                ->count();
            $staleChecks[] = [
                'label'   => 'structure_notification_status untouched 90+ days',
                'status'  => $stale === 0 ? 'ok' : 'info',
                'message' => $stale === 0
                    ? 'No stale dedup rows.'
                    : number_format($stale) . ' rows last updated 90+ days ago. May be dead structures.',
            ];
        }

        // structure_manager_disappearance_tracking — unresolved trackers
        // not seen in 7+ days. The actual column is `last_seen_at` (timestamp
        // of the last poll where the structure was present); a stuck tracker
        // is one where last_seen_at is old AND we have non-zero misses
        // recorded AND status is still null/watching.
        if (Schema::hasTable('structure_manager_disappearance_tracking')
            && Schema::hasColumn('structure_manager_disappearance_tracking', 'resolved_at')
            && Schema::hasColumn('structure_manager_disappearance_tracking', 'last_seen_at')
            && Schema::hasColumn('structure_manager_disappearance_tracking', 'consecutive_misses')) {
            $cutoff = now()->subDays(7);
            $stuck = DB::table('structure_manager_disappearance_tracking')
                ->whereNull('resolved_at')
                ->where('last_seen_at', '<', $cutoff)
                ->where('consecutive_misses', '>', 0)
                ->count();
            $staleChecks[] = [
                'label'   => 'disappearance trackers stuck > 7 days',
                'status'  => $stuck === 0 ? 'ok' : 'warn',
                'message' => $stuck === 0
                    ? 'No stuck trackers.'
                    : number_format($stuck) . ' unresolved trackers (last seen 7+ days ago, status still watching). They should classify within 24-48h normally.',
            ];
        }

        $groups[] = [
            'title' => 'Stale / dedup table state',
            'description' => 'Long-lived data that should be regularly cleaned up by scheduled jobs. Use these counts to verify cleanup-history is running.',
            'items' => $staleChecks,
        ];

        // ---- Group 4: Settings table integrity -----------------------------
        $settingsChecks = [];
        if (Schema::hasTable('structure_manager_settings')) {
            // Duplicate keys (table has UNIQUE so should be 0)
            $dupKeys = DB::table('structure_manager_settings')
                ->select('key', DB::raw('COUNT(*) as c'))
                ->groupBy('key')
                ->havingRaw('c > 1')
                ->get();
            $settingsChecks[] = [
                'label'   => 'No duplicate keys',
                'status'  => $dupKeys->isEmpty() ? 'ok' : 'error',
                'message' => $dupKeys->isEmpty()
                    ? 'All keys unique.'
                    : 'Duplicate keys: ' . $dupKeys->pluck('key')->implode(', ') . '. Bypassed unique constraint somehow. Manual fix needed.',
            ];

            // NULL keys (impossible but check)
            $nullKeys = DB::table('structure_manager_settings')
                ->whereNull('key')
                ->orWhere('key', '')
                ->count();
            $settingsChecks[] = [
                'label'   => 'No empty / NULL keys',
                'status'  => $nullKeys === 0 ? 'ok' : 'error',
                'message' => $nullKeys === 0
                    ? 'All rows have a non-empty key.'
                    : "{$nullKeys} rows have NULL or empty key. Manual fix needed.",
            ];
        }
        $groups[] = [
            'title' => 'Settings table integrity',
            'description' => 'Sanity checks on the settings storage itself. Failures here are very rare but indicate data corruption.',
            'items' => $settingsChecks,
        ];

        // ---- Group 5: Failed jobs (Laravel queue) --------------------------
        $jobChecks = [];
        if (Schema::hasTable('failed_jobs')) {
            // Count SM-related failed jobs (best-effort string match on payload)
            try {
                $smFailed = DB::table('failed_jobs')
                    ->where('payload', 'like', '%StructureManager%')
                    ->count();
                $jobChecks[] = [
                    'label'   => 'Failed jobs referencing StructureManager',
                    'status'  => $smFailed === 0 ? 'ok' : ($smFailed < 5 ? 'warn' : 'error'),
                    'message' => $smFailed === 0
                        ? 'No SM jobs in failed_jobs.'
                        : number_format($smFailed) . ' failed SM jobs. Inspect via `php artisan queue:failed` or the failed_jobs table.',
                ];
            } catch (\Throwable $e) {
                $jobChecks[] = [
                    'label'   => 'Failed jobs inspection',
                    'status'  => 'warn',
                    'message' => 'Could not query failed_jobs: ' . $e->getMessage(),
                ];
            }

            // Total failed jobs (any plugin)
            try {
                $totalFailed = DB::table('failed_jobs')->count();
                $jobChecks[] = [
                    'label'   => 'Total failed jobs (all plugins)',
                    'status'  => 'info',
                    'message' => number_format($totalFailed) . ' rows in failed_jobs. Not all are ours.',
                ];
            } catch (\Throwable $e) {
                // ignore
            }
        } else {
            $jobChecks[] = [
                'label'   => 'failed_jobs table',
                'status'  => 'info',
                'message' => 'Table not present (Laravel queue may not be using DB driver, or table never created).',
            ];
        }
        $groups[] = [
            'title' => 'Job queue health',
            'description' => 'Failed jobs accumulate when a job throws repeatedly. Investigate any non-zero count to find the underlying error.',
            'items' => $jobChecks,
        ];

        // ---- Aggregate counts ----------------------------------------------
        $counts = ['ok' => 0, 'warn' => 0, 'error' => 0, 'info' => 0];
        $total = 0;
        foreach ($groups as $g) {
            foreach ($g['items'] as $item) {
                $st = $item['status'] ?? 'info';
                if (!isset($counts[$st])) $st = 'info';
                $counts[$st]++;
                $total++;
            }
        }
        $overall = 'ok';
        if ($counts['error'] > 0) $overall = 'error';
        elseif ($counts['warn'] > 0) $overall = 'warn';

        return [
            'overall' => $overall,
            'counts'  => $counts,
            'total'   => $total,
            'groups'  => $groups,
        ];
    }

    /**
     * Build the catalog of structures and POSes that the trace tab can
     * select from. Returns:
     *   [
     *     'items'           => [ ... combined Upwell + POS rows ... ],
     *     'cap'             => int (per-type display cap),
     *     'upwell_total'    => int (rows actually present in DB),
     *     'pos_total'       => int (rows actually present in DB),
     *     'upwell_shown'    => int (after cap),
     *     'pos_shown'       => int (after cap),
     *     'upwell_truncated'=> bool,
     *     'pos_truncated'   => bool,
     *   ]
     *
     * Admin-only page so we deliberately do NOT scope by user corp.
     */
    private function buildFuelTraceCatalog(): array
    {
        // Per-type display cap. 1000 each (= 2000 max rows in dropdown) is
        // still cheap for both the SQL query and for browser rendering of
        // <option> elements. Bumping further only helps mega-alliance scale,
        // and at that scale a name-search filter would be the right answer.
        $cap = 1000;

        $items = [];
        $upwellTotal = 0;
        $upwellShown = 0;
        $posTotal = 0;
        $posShown = 0;

        // Upwell structures
        if (Schema::hasTable('corporation_structures')) {
            $upwellTotal = DB::table('corporation_structures')->count();

            $rows = DB::table('corporation_structures as cs')
                ->leftJoin('universe_structures as us', 'cs.structure_id', '=', 'us.structure_id')
                ->leftJoin('corporation_infos as ci', 'cs.corporation_id', '=', 'ci.corporation_id')
                ->leftJoin('invTypes as it', 'cs.type_id', '=', 'it.typeID')
                ->select(
                    'cs.structure_id',
                    'cs.corporation_id',
                    'cs.type_id',
                    'us.name as us_name',
                    'ci.name as corp_name',
                    'it.typeName as type_name'
                )
                ->orderBy('us.name')
                ->limit($cap)
                ->get();
            foreach ($rows as $r) {
                $items[] = [
                    'id'        => (int) $r->structure_id,
                    'type'      => 'upwell',
                    'name'      => $r->us_name ?: ('Structure #' . $r->structure_id),
                    'subtitle'  => trim(($r->type_name ?: 'Unknown type') . ' / ' . ($r->corp_name ?: 'Corp #' . $r->corporation_id)),
                ];
            }
            $upwellShown = $rows->count();
        }

        // POSes
        if (Schema::hasTable('corporation_starbases')) {
            $posTotal = DB::table('corporation_starbases')->count();

            $rows = DB::table('corporation_starbases as csb')
                ->leftJoin('corporation_infos as ci', 'csb.corporation_id', '=', 'ci.corporation_id')
                ->leftJoin('invTypes as it', 'csb.type_id', '=', 'it.typeID')
                ->leftJoin('mapDenormalize as md', 'csb.system_id', '=', 'md.itemID')
                ->select(
                    'csb.starbase_id',
                    'csb.corporation_id',
                    'csb.type_id',
                    'csb.system_id',
                    'it.typeName as type_name',
                    'ci.name as corp_name',
                    'md.itemName as system_name'
                )
                ->orderBy('md.itemName')
                ->limit($cap)
                ->get();
            foreach ($rows as $r) {
                $items[] = [
                    'id'        => (int) $r->starbase_id,
                    'type'      => 'pos',
                    'name'      => ($r->type_name ?: 'POS') . ' in ' . ($r->system_name ?: 'system #' . $r->system_id),
                    'subtitle'  => $r->corp_name ?: 'Corp #' . $r->corporation_id,
                ];
            }
            $posShown = $rows->count();
        }

        return [
            'items'            => $items,
            'cap'              => $cap,
            'upwell_total'     => $upwellTotal,
            'pos_total'        => $posTotal,
            'upwell_shown'     => $upwellShown,
            'pos_shown'        => $posShown,
            'upwell_truncated' => $upwellTotal > $upwellShown,
            'pos_truncated'    => $posTotal > $posShown,
        ];
    }

    /**
     * Run the fuel trace for one entity. Returns a stepwise pipeline of
     * what the plugin sees and would do for this row.
     *
     * Steps:
     *   1. Input row (raw DB)
     *   2. Universe context (system, security)
     *   3. Fuel reserves snapshot (latest)
     *   4. Fuel history (last 5 entries)
     *   5. Threshold determination (which class of threshold applies)
     *   6. Notification gate (toggles + last fire + cadence)
     *   7. Recent dedup entries
     *
     * Each step produces a status, message, and a details payload the view
     * can expand for the full picture.
     */
    private function buildFuelTrace(int $id, string $type): ?array
    {
        if ($id <= 0 || !in_array($type, ['upwell', 'pos'], true)) {
            return null;
        }

        $steps = [];
        $entityName = '(unknown)';

        if ($type === 'upwell') {
            // ---- Step 1: input row ----
            $row = DB::table('corporation_structures')
                ->where('structure_id', $id)
                ->first();
            if (!$row) {
                return [
                    'id'    => $id,
                    'type'  => $type,
                    'name'  => 'Structure not found',
                    'steps' => [[
                        'title'   => 'Lookup',
                        'status'  => 'error',
                        'message' => "structure_id {$id} not present in corporation_structures.",
                    ]],
                ];
            }
            $usRow = Schema::hasTable('universe_structures')
                ? DB::table('universe_structures')->where('structure_id', $id)->first()
                : null;
            $typeRow = Schema::hasTable('invTypes')
                ? DB::table('invTypes')->where('typeID', $row->type_id)->first(['typeName'])
                : null;
            $entityName = $usRow->name ?? ('Structure #' . $id);

            $steps[] = [
                'title'   => '1. Input row (corporation_structures)',
                'status'  => 'info',
                'message' => "type={$row->type_id} (" . ($typeRow->typeName ?? '?') . "), state={$row->state}, fuel_expires=" . ($row->fuel_expires ?? '(null)'),
                'details' => (array) $row,
            ];

            // ---- Step 2: universe context ----
            $sysRow = ($usRow && Schema::hasTable('mapDenormalize'))
                ? DB::table('mapDenormalize')->where('itemID', $usRow->solar_system_id ?? 0)->first(['itemName', 'security', 'regionID'])
                : null;
            $regionRow = ($sysRow && Schema::hasTable('mapDenormalize'))
                ? DB::table('mapDenormalize')->where('itemID', $sysRow->regionID)->first(['itemName'])
                : null;
            $steps[] = [
                'title'   => '2. Universe context',
                'status'  => $sysRow ? 'info' : 'warn',
                'message' => $sysRow
                    ? sprintf('System: %s (truesec %.3f), Region: %s', $sysRow->itemName, (float) $sysRow->security, $regionRow->itemName ?? '?')
                    : 'Universe data unavailable. Structure may not have been resolved by SeAT yet.',
                'details' => $sysRow ? (array) $sysRow : null,
            ];

            // ---- Step 3: fuel reserves snapshot ----
            $isMetenox = (int) $row->type_id === 81826;
            if (Schema::hasTable('structure_fuel_reserves')) {
                $reserves = DB::table('structure_fuel_reserves')
                    ->where('structure_id', $id)
                    ->orderBy('updated_at', 'desc')
                    ->limit(1)
                    ->first();
                $steps[] = [
                    'title'   => '3. Fuel reserves snapshot',
                    'status'  => $reserves ? 'info' : 'warn',
                    'message' => $reserves
                        ? "Last updated " . ($reserves->updated_at ?? '?') . ". " . ($isMetenox ? 'Metenox: dual-fuel (blocks + gas).' : 'Standard fuel-block consumer.')
                        : 'No reserve scan data. structure-manager:track-fuel may not be running.',
                    'details' => $reserves ? (array) $reserves : null,
                ];
            }

            // ---- Step 4: fuel history (last 5) ----
            // v2.0.0 columns also surfaced: event_type, expected_consumption,
            // unexplained_delta, reserves_delta (added by migration 000004).
            if (Schema::hasTable('structure_fuel_history')) {
                $history = DB::table('structure_fuel_history')
                    ->where('structure_id', $id)
                    ->orderBy('created_at', 'desc')
                    ->limit(5)
                    ->get();
                $latest = $history->first();
                $eventTypeTail = '';
                if ($latest && Schema::hasColumn('structure_fuel_history', 'event_type')) {
                    $eventTypeTail = ' event_type=' . ($latest->event_type ?? '?');
                }
                $steps[] = [
                    'title'   => '4. Fuel history (latest 5)',
                    'status'  => $latest ? 'info' : 'warn',
                    'message' => $latest
                        ? sprintf('Latest: %s. days_remaining=%s. fuel_expires=%s.%s',
                            $latest->created_at ?? '?',
                            isset($latest->days_remaining) ? (string) $latest->days_remaining : '?',
                            $latest->fuel_expires ?? '?',
                            $eventTypeTail)
                        : 'No history rows. Tracker has not run for this structure yet.',
                    'details' => $history->map(fn($h) => (array) $h)->all(),
                ];
            }

            // ---- Step 4b: Fuel event classification breakdown (v2.0.0) ----
            // Counts the recent 30 history rows by event_type so operators
            // can see at a glance whether this structure is mostly normal
            // consumption, getting regular refuels, or seeing suspicious
            // withdrawals. Skipped when the v2 column doesn't exist yet
            // (pre-migration installs).
            if (Schema::hasTable('structure_fuel_history')
                && Schema::hasColumn('structure_fuel_history', 'event_type')) {
                $eventBreakdown = DB::table('structure_fuel_history')
                    ->where('structure_id', $id)
                    ->orderBy('created_at', 'desc')
                    ->limit(30)
                    ->get(['event_type', 'expected_consumption', 'unexplained_delta', 'reserves_delta', 'created_at']);

                $byType = [];
                foreach ($eventBreakdown as $row) {
                    $type = $row->event_type ?: 'unclassified';
                    $byType[$type] = ($byType[$type] ?? 0) + 1;
                }
                ksort($byType);

                $withdrawalCount = ($byType['withdrawal_bay'] ?? 0) + ($byType['withdrawal_reserves'] ?? 0);
                $anomalyCount = ($byType['consumption_anomaly'] ?? 0) + ($byType['unexplained_gain'] ?? 0);
                $status = 'info';
                if ($withdrawalCount > 0) {
                    $status = 'warn';
                }

                $summaryParts = [];
                foreach ($byType as $type => $count) {
                    $summaryParts[] = "{$count} {$type}";
                }

                $steps[] = [
                    'title'   => '4b. Event classification (last 30 polls)',
                    'status'  => $status,
                    'message' => count($byType) > 0
                        ? sprintf('%s. Withdrawals: %d, anomalies: %d.', implode(', ', $summaryParts), $withdrawalCount, $anomalyCount)
                        : 'No classified history yet. Classifier runs on each fuel-tracking poll going forward.',
                    'details' => [
                        'breakdown' => $byType,
                        'withdrawal_count' => $withdrawalCount,
                        'anomaly_count' => $anomalyCount,
                        'last_5_classified' => $eventBreakdown->take(5)->map(fn($r) => (array) $r)->all(),
                    ],
                ];
            }

            // ---- Step 4c: Forensic candidates (v2.0.0 Tier 2) ----
            // For the most-recent withdrawal_* event on this structure,
            // show the suspect-narrowing candidates that
            // WithdrawalForensicsJob identified. This is the smoking gun
            // for "who likely pulled fuel" investigations. Hard ESI limit:
            // probabilistic, not deterministic — operator must verify.
            if (Schema::hasTable('structure_fuel_event_candidates')
                && Schema::hasTable('structure_fuel_history')
                && Schema::hasColumn('structure_fuel_history', 'event_type')) {
                $latestWithdrawal = DB::table('structure_fuel_history')
                    ->where('structure_id', $id)
                    ->whereIn('event_type', ['withdrawal_bay', 'withdrawal_reserves'])
                    ->orderBy('created_at', 'desc')
                    ->first();

                if ($latestWithdrawal) {
                    $candidates = DB::table('structure_fuel_event_candidates')
                        ->where('fuel_history_id', $latestWithdrawal->id)
                        ->orderByRaw("FIELD(confidence, 'HIGH', 'MEDIUM', 'LOW')")
                        ->orderByDesc('score')
                        ->get();

                    $highCount = $candidates->where('confidence', 'HIGH')->count();
                    $mediumCount = $candidates->where('confidence', 'MEDIUM')->count();
                    $lowCount = $candidates->where('confidence', 'LOW')->count();
                    $status = $highCount > 0 ? 'warn' : ($candidates->isEmpty() ? 'info' : 'info');

                    $steps[] = [
                        'title'   => '4c. Forensic candidates (latest withdrawal event)',
                        'status'  => $status,
                        'message' => $candidates->isEmpty()
                            ? sprintf('Latest withdrawal at %s (%s, magnitude %s blocks). No candidate handlers identified — WithdrawalForensicsJob may not have run yet, or no signals matched.',
                                $latestWithdrawal->created_at,
                                $latestWithdrawal->event_type,
                                abs((int) ($latestWithdrawal->unexplained_delta ?? 0)))
                            : sprintf('Latest withdrawal at %s (%s, magnitude %s blocks). Candidates: %d HIGH / %d MEDIUM / %d LOW. ESI cannot expose actor identity — these are probabilistic inferences.',
                                $latestWithdrawal->created_at,
                                $latestWithdrawal->event_type,
                                abs((int) ($latestWithdrawal->unexplained_delta ?? 0)),
                                $highCount,
                                $mediumCount,
                                $lowCount),
                        'details' => [
                            'withdrawal_event' => (array) $latestWithdrawal,
                            'candidates' => $candidates->map(fn($c) => (array) $c)->all(),
                        ],
                    ];
                }
            }

            // ---- Step 5: threshold determination ----
            $upwellCrit = \StructureManager\Helpers\FuelThresholds::UPWELL_FUEL_CRITICAL_DAYS;
            $upwellWarn = \StructureManager\Helpers\FuelThresholds::UPWELL_FUEL_WARNING_DAYS;
            $steps[] = [
                'title'   => '5. Threshold determination',
                'status'  => 'info',
                'message' => "Upwell thresholds are LOCKED in code: critical={$upwellCrit}d, warning={$upwellWarn}d. "
                    . ($isMetenox ? 'Metenox limiting factor: min(fuel_days, magmatic_gas_days).' : 'Fuel-block depletion drives status.'),
                'details' => [
                    'upwell_critical_days' => $upwellCrit,
                    'upwell_warning_days'  => $upwellWarn,
                    'metenox'              => $isMetenox,
                ],
            ];

            // ---- Step 6: notification gate ----
            // structure_notification_status real columns:
            // last_fuel_notification_at, last_fuel_notification_status,
            // fuel_final_alert_sent, last_gas_notification_at,
            // last_gas_notification_status (Metenox).
            $fuelEventsEnabled = (bool) StructureManagerSettings::get('notify_structure_fuel_events', 1);
            $cadenceHours = (int) StructureManagerSettings::get('upwell_fuel_notification_interval', 0);
            $lastFire = null;
            if (Schema::hasTable('structure_notification_status')
                && Schema::hasColumn('structure_notification_status', 'last_fuel_notification_at')) {
                $lastFire = DB::table('structure_notification_status')
                    ->where('structure_id', $id)
                    ->orderBy('last_fuel_notification_at', 'desc')
                    ->first();
            }
            $gateStatus = $fuelEventsEnabled ? 'ok' : 'warn';
            $gateMessage = $fuelEventsEnabled
                ? 'Fuel-event category is enabled. Cadence: ' . ($cadenceHours === 0 ? 'status-change-only.' : "{$cadenceHours}h between repeats.")
                : 'Fuel-event category DISABLED in settings. No alerts will fire even if fuel goes critical.';
            if ($lastFire) {
                $gateMessage .= ' Last fuel-fire: ' . ($lastFire->last_fuel_notification_at ?? '?')
                    . ' (level: ' . ($lastFire->last_fuel_notification_status ?? '?') . ').';
                if (!empty($lastFire->last_gas_notification_at)) {
                    $gateMessage .= ' Last gas-fire: ' . $lastFire->last_gas_notification_at
                        . ' (level: ' . ($lastFire->last_gas_notification_status ?? '?') . ').';
                }
            }
            $steps[] = [
                'title'   => '6. Notification gate',
                'status'  => $gateStatus,
                'message' => $gateMessage,
                'details' => [
                    'notify_structure_fuel_events'      => $fuelEventsEnabled,
                    'upwell_fuel_notification_interval' => $cadenceHours,
                    'last_fire_row'                     => $lastFire ? (array) $lastFire : null,
                ],
            ];

            // ---- Step 7: recent dedup entries ----
            if (Schema::hasTable('structure_manager_esi_notifications')) {
                $dedup = DB::table('structure_manager_esi_notifications')
                    ->where('text', 'like', '%' . $id . '%')
                    ->orderBy('timestamp', 'desc')
                    ->limit(5)
                    ->get(['notification_id', 'type', 'timestamp', 'source', 'processed']);
                $steps[] = [
                    'title'   => '7. Recent ESI dedup entries (best-effort match)',
                    'status'  => $dedup->isEmpty() ? 'info' : 'ok',
                    'message' => $dedup->isEmpty()
                        ? 'No notifications referencing this structure_id in recent dedup table.'
                        : "{$dedup->count()} notifications matching this structure_id in dedup table.",
                    'details' => $dedup->map(fn($d) => (array) $d)->all(),
                ];
            }
        } else {
            // ---- POS trace ----
            $row = DB::table('corporation_starbases')
                ->where('starbase_id', $id)
                ->first();
            if (!$row) {
                return [
                    'id'    => $id,
                    'type'  => $type,
                    'name'  => 'POS not found',
                    'steps' => [[
                        'title'   => 'Lookup',
                        'status'  => 'error',
                        'message' => "starbase_id {$id} not present in corporation_starbases.",
                    ]],
                ];
            }
            $typeRow = Schema::hasTable('invTypes')
                ? DB::table('invTypes')->where('typeID', $row->type_id)->first(['typeName'])
                : null;
            $sysRow = Schema::hasTable('mapDenormalize')
                ? DB::table('mapDenormalize')->where('itemID', $row->system_id)->first(['itemName', 'security', 'regionID'])
                : null;
            $entityName = ($typeRow->typeName ?? 'POS') . ' in ' . ($sysRow->itemName ?? 'system #' . $row->system_id);

            $steps[] = [
                'title'   => '1. Input row (corporation_starbases)',
                'status'  => 'info',
                'message' => sprintf('type=%d (%s), state=%s, system=%s', $row->type_id, $typeRow->typeName ?? '?', $row->state ?? '?', $sysRow->itemName ?? '?'),
                'details' => (array) $row,
            ];

            $secValue = $sysRow ? (float) $sysRow->security : null;
            $highSec = $secValue !== null && $secValue >= \StructureManager\Helpers\PosFuelCalculator::HIGH_SEC_THRESHOLD;
            $steps[] = [
                'title'   => '2. Universe context',
                'status'  => $sysRow ? 'info' : 'warn',
                'message' => $sysRow
                    ? sprintf('System: %s (truesec %.3f). Charter required: %s.', $sysRow->itemName, $secValue, $highSec ? 'YES (high-sec)' : 'NO')
                    : 'Universe data unavailable.',
                'details' => $sysRow ? (array) $sysRow : null,
            ];

            // ---- Step 3: fuel reserves ----
            if (Schema::hasTable('starbase_fuel_reserves')) {
                $reserves = DB::table('starbase_fuel_reserves')
                    ->where('starbase_id', $id)
                    ->orderBy('updated_at', 'desc')
                    ->limit(1)
                    ->first();
                $steps[] = [
                    'title'   => '3. Fuel reserves snapshot',
                    'status'  => $reserves ? 'info' : 'warn',
                    'message' => $reserves
                        ? 'Last reserve fetch: ' . ($reserves->updated_at ?? '?')
                        : 'No reserve scan data. structure-manager:track-poses-fuel may not be running.',
                    'details' => $reserves ? (array) $reserves : null,
                ];
            }

            // ---- Step 4: fuel history ----
            // Real columns on starbase_fuel_history (per migrations 000007 +
            // 000015): fuel_blocks_quantity, fuel_days_remaining,
            // strontium_quantity, strontium_hours_available (NOT _remaining),
            // strontium_status (NOT fuel_status), charter_quantity,
            // charter_days_remaining, actual_days_remaining, limiting_factor,
            // state (added by 000015).
            if (Schema::hasTable('starbase_fuel_history')) {
                $history = DB::table('starbase_fuel_history')
                    ->where('starbase_id', $id)
                    ->orderBy('created_at', 'desc')
                    ->limit(5)
                    ->get();
                $latest = $history->first();
                $steps[] = [
                    'title'   => '4. Fuel history (latest 5)',
                    'status'  => $latest ? 'info' : 'warn',
                    'message' => $latest
                        ? sprintf('Latest: %s. fuel_days=%s, strontium_hours=%s, charter_days=%s, strontium_status=%s, limiting_factor=%s',
                            $latest->created_at ?? '?',
                            $latest->fuel_days_remaining ?? '?',
                            $latest->strontium_hours_available ?? '?',
                            $latest->charter_days_remaining ?? '(none)',
                            $latest->strontium_status ?? '?',
                            $latest->limiting_factor ?? '?')
                        : 'No history rows. POS tracker has not run yet.',
                    'details' => $history->map(fn($h) => (array) $h)->all(),
                ];
            }

            // ---- Step 5: threshold determination (CONFIGURABLE for POS) ----
            $T = \StructureManager\Helpers\FuelThresholds::class;
            $thresholdDetails = [
                'pos_fuel_critical_days'         => $T::posFuelCritical(),
                'pos_fuel_warning_days'          => $T::posFuelWarning(),
                'pos_strontium_critical_hours'   => $T::posStrontiumCritical(),
                'pos_strontium_warning_hours'    => $T::posStrontiumWarning(),
                'pos_strontium_good_hours'       => $T::posStrontiumGood(),
                'pos_charter_critical_days'      => $highSec ? $T::posCharterCritical() : null,
            ];
            $steps[] = [
                'title'   => '5. Threshold determination (POS, CONFIGURABLE)',
                'status'  => 'info',
                'message' => sprintf('fuel<%dd=critical, fuel<%dd=warn. strontium<%dh=critical, <%dh=warn, <%dh=good.%s',
                    $thresholdDetails['pos_fuel_critical_days'],
                    $thresholdDetails['pos_fuel_warning_days'],
                    $thresholdDetails['pos_strontium_critical_hours'],
                    $thresholdDetails['pos_strontium_warning_hours'],
                    $thresholdDetails['pos_strontium_good_hours'],
                    $highSec ? " charter<{$thresholdDetails['pos_charter_critical_days']}d=critical." : ''
                ),
                'details' => $thresholdDetails,
            ];

            // ---- Step 6: notification gate ----
            $fuelEventsEnabled = (bool) StructureManagerSettings::get('notify_structure_fuel_events', 1);
            $fuelInterval = (int) StructureManagerSettings::get('pos_fuel_notification_interval', 0);
            $stronInterval = (int) StructureManagerSettings::get('pos_strontium_notification_interval', 0);
            $gateStatus = $fuelEventsEnabled ? 'ok' : 'warn';
            $gateMessage = $fuelEventsEnabled
                ? 'Fuel-event category enabled. Fuel cadence: '
                    . ($fuelInterval === 0 ? 'status-change-only' : "{$fuelInterval}h")
                    . ', strontium cadence: ' . ($stronInterval === 0 ? 'status-change-only' : "{$stronInterval}h") . '.'
                : 'Fuel-event category DISABLED. No alerts will fire.';
            $steps[] = [
                'title'   => '6. Notification gate',
                'status'  => $gateStatus,
                'message' => $gateMessage,
                'details' => [
                    'notify_structure_fuel_events'         => $fuelEventsEnabled,
                    'pos_fuel_notification_interval'       => $fuelInterval,
                    'pos_strontium_notification_interval'  => $stronInterval,
                ],
            ];
        }

        return [
            'id'    => $id,
            'type'  => $type,
            'name'  => $entityName,
            'steps' => $steps,
        ];
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
     * Remove all Structure Manager test data across every safe range.
     *
     * Delegates to TestDataGenerator::cleanupAll() which deletes:
     *   - Test corporations (2.1B range) — cascades structures + POSes via FK
     *   - Test characters (2.4B range) — cascades notifications via FK
     *   - Test Upwell structures (2.3B range)
     *   - Legacy Metenox / Astrahus rows (9999999998 / 9999999999)
     *   - Test POSes (2.2B range) + their fuel history
     *   - Test notifications in character_notifications + SM dedup table (8e18 range)
     *   - Test corp assets (range matched by location_id and corporation_id)
     *   - Structure Board test rows (matched by structure_id or corp_id ranges)
     *
     * Also still calls the older `--cleanup` paths on the legacy create-test-*
     * commands so any data they wrote in their own (non-test-range) tables
     * gets swept up — defensive belt-and-braces.
     */
    public function cleanupTestData(Request $request)
    {
        if (!$this->confirmed($request)) {
            return back()->with('error', 'Cleanup requires the confirmation checkbox. No changes made.');
        }

        $errors = [];
        $counts = [];

        // Primary cleanup: TestDataGenerator handles every modern range
        try {
            $counts = TestDataGenerator::cleanupAll();
        } catch (\Throwable $e) {
            $errors[] = 'TestDataGenerator cleanup: ' . $e->getMessage();
        }

        // Belt-and-braces: also call the legacy commands' --cleanup paths.
        // These mostly handle the SAME ranges TestDataGenerator already swept,
        // but the Metenox command also has its own internal cleanup logic for
        // structure-services + fuel-history rows we shouldn't duplicate here.
        try {
            Artisan::call('structure-manager:create-test-poses', [
                '--cleanup' => true,
                '--force'   => true,
            ]);
        } catch (\Throwable $e) {
            $errors[] = 'Legacy POS cleanup: ' . $e->getMessage();
        }
        try {
            Artisan::call('structure-manager:create-test-metenox', ['--cleanup' => true]);
        } catch (\Throwable $e) {
            $errors[] = 'Legacy Metenox cleanup: ' . $e->getMessage();
        }

        $totalRowsDeleted = array_sum($counts);

        // Build a friendly per-table breakdown for display. Only includes
        // tables that actually had rows deleted, sorted by count descending
        // so the most impactful entries lead.
        $details = $this->formatCleanupDetails($counts);

        if (!empty($errors)) {
            Log::warning('Structure Manager diagnostic: test cleanup had errors', ['errors' => $errors, 'counts' => $counts]);
            return back()
                ->with('error', 'Cleanup finished with errors: ' . implode(' | ', $errors))
                ->with('cleanup_details', $details);
        }

        Log::info('Structure Manager diagnostic: cleaned up test data', ['counts' => $counts, 'total' => $totalRowsDeleted]);

        $tablesWithRows = count(array_filter($counts, fn($n) => $n > 0));
        $summary = $tablesWithRows === 0
            ? 'No test data to clean up — already empty.'
            : sprintf('All test data cleaned up: %d row(s) across %d table(s).', $totalRowsDeleted, $tablesWithRows);

        return back()
            ->with('success', $summary)
            ->with('cleanup_details', $details);
    }

    /**
     * Translate raw cleanup count keys into a friendly, sorted breakdown
     * suitable for the success-flash detail panel.
     *
     * @param  array<string,int>  $counts  Output of TestDataGenerator::cleanupAll
     * @return array<int,array{label:string,count:int,table:string}>
     */
    private function formatCleanupDetails(array $counts): array
    {
        $labels = [
            'character_notifications'              => 'Fake notifications (SeAT character_notifications)',
            'structure_manager_esi_notifications'  => 'SM dedup rows (structure_manager_esi_notifications)',
            'character_affiliations'               => 'Test character affiliations',
            'character_infos'                      => 'Test characters',
            'corporation_structures'               => 'Test Upwell structures (2.3B range)',
            'universe_structures'                  => 'Test universe_structures rows',
            'legacy_structures'                    => 'Legacy Metenox / Astrahus structures',
            'corporation_starbases'                => 'Test POSes (2.2B range)',
            'starbase_fuel_history'                => 'Test POS fuel history rows',
            'corporation_assets_in_structures'     => 'Test assets stored in test structures',
            'corporation_assets_owned'             => 'Test corp-owned assets',
            'corporation_infos'                    => 'Test corporations (2.1B range)',
            'structure_manager_timers'             => 'Structure Board test rows',
        ];

        $rows = [];
        foreach ($counts as $key => $n) {
            if ($n <= 0) continue;
            $rows[] = [
                'label' => $labels[$key] ?? $key,
                'count' => (int) $n,
                'table' => $key,
            ];
        }

        usort($rows, fn($a, $b) => $b['count'] <=> $a['count']);
        return $rows;
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
     * Dispatch the appropriate notification job.
     *
     * - If Manager Core is installed: dispatch MC's fast-poll job directly
     *   (via ManagerCoreIntegration::triggerFastPoll). Uses direct dispatch
     *   instead of Artisan::call because MC's ServiceProvider only registers
     *   commands during CLI runs — Artisan::call from HTTP context would
     *   fail with "command does not exist".
     * - If Manager Core is absent: dispatch SM's fallback job directly too
     *   for consistency.
     */
    public function runEsiPollNow(Request $request)
    {
        if (!$this->confirmed($request)) {
            return back()->with('error', 'ESI poll requires the confirmation checkbox.');
        }

        try {
            if (ManagerCoreIntegration::isAvailable()) {
                $dispatched = ManagerCoreIntegration::triggerFastPoll();
                if (!$dispatched) {
                    Log::error('Structure Manager diagnostic: MC detected but fast-poll job class not found');
                    return back()->with('error', 'Manager Core detected but its fast-poll job class could not be loaded. Check MC version.');
                }
                Log::info('Structure Manager diagnostic: dispatched MC fast-poll job');
                return back()->with('success', 'Manager Core fast-poll dispatched. Shared key holders are being polled now.');
            }

            // Standalone mode — dispatch SM's own fallback job directly
            dispatch(new \StructureManager\Jobs\ProcessStructureNotifications());
            Log::info('Structure Manager diagnostic: dispatched SM fallback notification processor');
            return back()->with('success', 'Fallback notification processor dispatched. Install Manager Core for faster fast-poll.');
        } catch (\Throwable $e) {
            Log::error('Structure Manager diagnostic: notification job failed - ' . $e->getMessage());
            return back()->with('error', 'Notification job failed: ' . $e->getMessage());
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

    // ==================================================================
    // Test Notification Lab
    //
    // Creates fake structures + injects fake CCP-shaped notifications so
    // admins can verify the full SM dispatch pipeline (board timer → EventBus
    // publish → webhook embed) without waiting for a real attack. Every
    // endpoint enforces TestDataGenerator's safe-range guards so nothing
    // here can touch real data.
    // ==================================================================

    /**
     * Create the full set of 12 test Upwell structures (or a subset).
     *
     * Wraps `structure-manager:create-test-upwell-structures`. The command
     * handles corp + character creation transparently.
     */
    public function generateTestUpwellStructures(Request $request)
    {
        if (!$this->confirmed($request)) {
            return back()->with('error', 'Test-data generation requires the confirmation checkbox. No changes made.');
        }

        $request->validate([
            'types' => 'nullable|string|max:200',
        ]);

        $args = [];
        if (!empty($request->input('types'))) {
            $args['--types'] = $request->input('types');
        }

        try {
            Artisan::call('structure-manager:create-test-upwell-structures', $args);
            $output = Artisan::output();
            Log::info('Structure Manager diagnostic: generated test Upwell structures', ['output_lines' => substr_count($output, "\n")]);
            return back()->with('success', 'Test Upwell structures generated. See the Test Notification Lab panel.');
        } catch (\Throwable $e) {
            Log::error('Structure Manager diagnostic: test Upwell generation failed', ['error' => $e->getMessage()]);
            return back()->with('error', 'Test Upwell generation failed: ' . $e->getMessage());
        }
    }

    /**
     * Inject one fake CCP-shaped notification into character_notifications.
     *
     * The user picks a target test structure + a notification type from the
     * UI; this endpoint forwards both to the artisan command, which validates
     * everything against TestDataGenerator's safe ranges before writing.
     */
    public function injectTestNotification(Request $request)
    {
        if (!$this->confirmed($request)) {
            return back()->with('error', 'Notification injection requires the confirmation checkbox. No changes made.');
        }

        $validated = $request->validate([
            'structure_id'           => 'required|integer',
            'type'                   => 'required|string',
            'attacker_corp'          => 'nullable|string|max:100',
            'attacker_alliance'      => 'nullable|string|max:100',
            'attacker_alliance_id'   => 'nullable|integer',
            'time_left_seconds'      => 'nullable|integer|min:60|max:2592000', // 1 min to 30 days
        ]);

        // Belt-and-braces guard: re-check the test-range constraint here
        // before we even invoke the artisan command. The command also enforces
        // this, but failing fast in the controller gives a cleaner error msg.
        if (!TestDataGenerator::isTestStructure((int) $validated['structure_id'])) {
            return back()->with('error', sprintf(
                'Refusing: structure %d is not in the safe test range.',
                $validated['structure_id']
            ));
        }
        if (!isset(FakeNotificationBuilder::SUPPORTED_TYPES[$validated['type']])) {
            return back()->with('error', sprintf(
                'Refusing: notification type "%s" is not supported.',
                $validated['type']
            ));
        }

        $args = [
            '--structure-id' => $validated['structure_id'],
            '--type'         => $validated['type'],
        ];
        if (!empty($validated['attacker_corp'])) {
            $args['--attacker-corp'] = $validated['attacker_corp'];
        }
        if (!empty($validated['attacker_alliance'])) {
            $args['--attacker-alliance'] = $validated['attacker_alliance'];
        }
        if (!empty($validated['attacker_alliance_id'])) {
            $args['--attacker-alliance-id'] = $validated['attacker_alliance_id'];
        }
        if (!empty($validated['time_left_seconds'])) {
            $args['--time-left'] = $validated['time_left_seconds'];
        }

        try {
            Artisan::call('structure-manager:inject-test-notification', $args);
            Log::info('Structure Manager diagnostic: injected test notification', [
                'type'         => $validated['type'],
                'structure_id' => $validated['structure_id'],
            ]);
            return back()->with('success', sprintf(
                'Injected %s on structure #%d. Within ~60 seconds, SM will pick it up and dispatch.',
                $validated['type'],
                $validated['structure_id']
            ));
        } catch (\Throwable $e) {
            Log::error('Structure Manager diagnostic: notification injection failed', ['error' => $e->getMessage()]);
            return back()->with('error', 'Injection failed: ' . $e->getMessage());
        }
    }

    /**
     * Save (or clear) the test webhook URL.
     *
     * When set, fake-injected notifications are routed to this URL ONLY
     * (skipping the production binding lookup) so test traffic never
     * accidentally hits production webhooks. See StructureEventHandler::dispatch.
     */
    public function saveTestWebhookUrl(Request $request)
    {
        if (!$this->confirmed($request)) {
            return back()->with('error', 'Saving the test webhook URL requires the confirmation checkbox.');
        }

        $validated = $request->validate([
            'test_webhook_url' => 'nullable|string|max:500',
        ]);

        $url = trim((string) ($validated['test_webhook_url'] ?? ''));

        if ($url === '') {
            // Clear the setting
            StructureManagerSettings::set('test_webhook_url', '', 'string', 'notifications');
            Log::info('Structure Manager diagnostic: cleared test_webhook_url');
            return back()->with('success', 'Test webhook URL cleared. Test injections will only update the board + EventBus.');
        }

        // Validate the URL shape via the same validator the production webhook
        // path uses, so we never accept obviously malformed URLs here.
        if (!WebhookConfiguration::isValidWebhookUrl($url)) {
            return back()->with('error', 'Test webhook URL is not a valid Discord/Slack webhook URL.');
        }

        StructureManagerSettings::set('test_webhook_url', $url, 'string', 'notifications');
        Log::info('Structure Manager diagnostic: saved test_webhook_url');

        return back()->with('success', 'Test webhook URL saved. Future test injections will route to this URL.');
    }

    /**
     * JSON: current state of the Test Notification Lab.
     *
     * Returns the same shape as the SSR `testLab` data on the diagnostic page,
     * for live AJAX refresh after generate/inject/cleanup actions.
     */
    public function testLabState()
    {
        return response()->json($this->buildTestLabStateData());
    }

    /**
     * Send the SM-side enriched Metenox dual-fuel embed to the test webhook.
     *
     * This bypasses the production polling job — `NotifyUpwellLowFuel` only
     * fires for structures actually below threshold, and routes through
     * corp-bound webhooks. For testing the dual-fuel embed format / limiting
     * factor display, this endpoint synthesizes a critical-gas scenario and
     * posts the same byte-identical embed (with [TEST INJECTION] banner) to
     * the configured test webhook URL.
     *
     * The user picks which resource is the limiting factor via the form:
     *   - magmatic_gas (default) — gas runs out first, the more common case
     *   - fuel_blocks — blocks run out first
     */
    public function sendTestMetenoxDualFuelEmbed(Request $request)
    {
        if (!$this->confirmed($request)) {
            return back()->with('error', 'Sending the test Metenox embed requires the confirmation checkbox.');
        }

        $request->validate([
            'limiting_factor' => 'nullable|in:magmatic_gas,fuel_blocks',
        ]);

        $factor = $request->input('limiting_factor', 'magmatic_gas');

        $ok = \StructureManager\Jobs\NotifyUpwellLowFuel::sendTestMetenoxDualFuelEmbed($factor);

        if (!$ok) {
            return back()->with('error',
                'Could not send test Metenox embed. Confirm a valid test webhook URL is saved above.');
        }

        Log::info('Structure Manager diagnostic: sent test Metenox dual-fuel embed', ['limiting_factor' => $factor]);
        return back()->with('success', sprintf(
            'Sent SM dual-fuel embed (limiting factor: %s) to the test webhook.',
            $factor
        ));
    }
}
