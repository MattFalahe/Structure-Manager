<?php

namespace StructureManager\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use StructureManager\Models\StarbaseFuelHistory;
use StructureManager\Models\StarbaseFuelReserves;
use StructureManager\Helpers\PosFuelCalculator;
use StructureManager\Helpers\TypeIdRegistry;
use Carbon\Carbon;

/**
 * Track POS (Player Owned Starbase) fuel consumption
 * 
 * Monitors:
 * - Fuel blocks in POS fuel bay
 * - Strontium clathrates (reinforcement timer)
 * - Starbase charters (high-sec only)
 * - Reserves in nearby structures/stations
 */
class TrackPosesFuel implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Max seconds the job is allowed to run before the worker kills it.
     */
    public $timeout = 600;

    /**
     * Retry count on unhandled exceptions.
     */
    public $tries = 3;

    /**
     * Retry back-off schedule in seconds.
     */
    public $backoff = [60, 300, 900];

    /**
     * How long a reserve tuple may sit unchanged before we write a snapshot
     * anyway. Reserve quantities only move when a human moves fuel, so the
     * job writes on change; this heartbeat keeps a recent row alive for every
     * tuple so the "current reserves" lookup (latest row per tuple) still
     * returns something after a retention prune, and so the history has a
     * regular anchor point.
     */
    private const RESERVE_SNAPSHOT_HOURS = 24;

    /**
     * How long a tower may read identically before a history row is written
     * anyway.
     *
     * A tower draws a whole cycle at the top of the hour rather than burning
     * smoothly, and SeAT refreshes corporation_starbase_fuels on a comparable
     * cadence, so a 10-minute poll re-reads the same three quantities several
     * times for every time one of them actually moves. Measured across a week
     * on a live tower: 515 rows carrying 66 distinct fuel readings.
     *
     * The poll interval is unchanged and every reading still goes through the
     * suspect check, so nothing is noticed any later than before. Only the
     * duplicate row is skipped. This heartbeat then guarantees a row a day, so
     * the daily consumption pass always has two to work from and a tower that
     * sits untouched still proves it was being watched.
     */
    private const HISTORY_SNAPSHOT_HOURS = 24;

    /**
     * Number of consecutive polls a tuple must be ABSENT before a depletion
     * row is recorded.
     *
     * SeAT refreshes corporation_assets by upserting each row keyed on
     * item_id with a fixed updated_at stamp, then sweeping everything it did
     * not restamp (DELETE ... WHERE updated_at < start), all outside a
     * transaction. item_id is not stable in EVE: moving, splitting or merging
     * a stack gives it a new one. So a stack can legitimately disappear from
     * the table for a window and come back under a different item_id, and a
     * single poll observing "reserves missing" is not evidence anything
     * moved. This is the same guard TrackFuelConsumption uses on the Upwell
     * side, and for the same reason: without it the reconciliation fires
     * phantom depletions whenever a poll lands inside that window.
     */
    private const DEPLETION_CONFIRM_COUNT = 2;

    /**
     * How long the absence counter survives in cache. The next poll rewrites
     * it either way (present, so forget; still absent, so re-put), so this
     * only needs to outlive a normal poll gap.
     */
    private const DEPLETION_CACHE_HOURS = 6;

    /**
     * POS states, as stored in the history table. SeAT gives them to us as
     * strings; convertStateToInteger() maps them.
     */
    private const STATE_UNANCHORED = 0;
    private const STATE_REINFORCED = 3;
    private const STATE_ONLINE     = 4;

    /**
     * How far past the expected burn a fuel drop has to land before the
     * reading is treated as suspect.
     *
     * A tower's consumption per hour comes from TypeIdRegistry, so between
     * two 10-minute polls the plausible drop is small and well defined.
     * Three times that leaves generous headroom for a late or bunched poll
     * while still catching the "5000 blocks to 0 in ten minutes" shape,
     * which is never consumption. The tolerance is wide enough that the
     * exact rate does not have to be perfect for the guard to work.
     */
    private const FUEL_DROP_TOLERANCE = 3.0;

    /**
     * Consecutive suspect readings before one is accepted as real. Two means
     * a genuine withdrawal is recorded one poll (10 minutes) later than it
     * used to be, and a bad read never lands at all.
     */
    private const FUEL_SUSPECT_CONFIRM_COUNT = 2;

    /**
     * The schedule row's allow_overlap=false only guards the console command,
     * and that command dispatches this job and returns in milliseconds. So
     * without this middleware a fresh job queues every 10 minutes whether or
     * not the previous one has finished, and a slow run turns into a pile of
     * concurrent jobs all grinding the same tables.
     *
     * dontRelease() discards the duplicate rather than requeueing it: this is
     * a periodic poll, so the next scheduled run is the retry.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('structure-manager:track-poses-fuel'))
                ->dontRelease()
                ->expireAfter($this->timeout + 60),
        ];
    }

    /**
     * Execute the job.
     */
    public function handle()
    {
        // Get all active POSes with names and locations from corporation_assets
        $poses = DB::table('corporation_starbases as cs')
            ->join('invTypes as it', 'cs.type_id', '=', 'it.typeID')
            ->leftJoin('mapDenormalize as md', 'cs.system_id', '=', 'md.itemID')
            ->leftJoin('corporation_assets as ca', 'cs.starbase_id', '=', 'ca.item_id')
            ->where('it.groupID', 365) // Control Tower group
            ->select(
                'cs.starbase_id',
                'cs.corporation_id',
                'cs.type_id',
                'cs.system_id',
                'cs.state',
                'it.typeName as tower_type',
                'md.itemName as system_name',
                'md.security as system_security',
                'ca.name as starbase_name',
                'ca.map_name as location_name'
            )
            ->get();
        
        Log::info('TrackPosesFuel: Processing ' . $poses->count() . ' POSes');

        $tracked = 0;
        $skipped = 0;
        $notOnline = 0;
        $deferred = 0;
        $unchanged = 0;
        $reservesTracked = 0;

        // Reserve stock is a CORPORATION-wide figure, not a per-tower one:
        // POSes have no hangars, so the reserve scan is the same query for
        // every tower in the same corp. Fetch it once per corporation rather
        // than re-scanning corporation_assets once per tower.
        $corporationReserves = [];
        foreach ($poses->pluck('corporation_id')->unique() as $corporationId) {
            $corporationReserves[(int) $corporationId] = $this->fetchCorporationReserves((int) $corporationId);
        }

        foreach ($poses as $pos) {
            // Track POS fuel levels
            $result = $this->trackPosFuel($pos);
            if ($result['tracked']) {
                $tracked++;
            } elseif (($result['reason'] ?? null) === 'state') {
                $notOnline++;
            } elseif (($result['reason'] ?? null) === 'suspect') {
                $deferred++;
            } elseif (($result['reason'] ?? null) === 'unchanged') {
                $unchanged++;
            } else {
                $skipped++;
            }

            // Track reserves for this POS (fuel held in corp hangars elsewhere)
            $reservesTracked += $this->trackPosReserves(
                $pos->starbase_id,
                $pos->corporation_id,
                $pos->system_id,
                $corporationReserves[(int) $pos->corporation_id] ?? []
            );
        }

        Log::info("TrackPosesFuel: Completed. Tracked: $tracked, Unchanged: $unchanged, Reserve rows: $reservesTracked, Not online: $notOnline, Deferred: $deferred, Skipped: $skipped");

        // CRITICAL: Clean up orphaned POSes (exist in history but not in corporation_starbases)
        // This handles POSes that were unanchored/removed from the game
        $orphanedCleaned = $this->cleanupOrphanedPoses($poses->pluck('starbase_id')->toArray());
        if ($orphanedCleaned > 0) {
            Log::info("TrackPosesFuel: Cleaned up $orphanedCleaned orphaned POS(es) no longer in corporation_starbases");
        }

        // Retention pruning lives in structure-manager:cleanup-history (daily
        // at 03:00), not here. Running it inline meant two unindexed
        // full-table deletes on every 10-minute poll, duplicating work the
        // cleanup command already does.
    }
    
    /**
     * Track fuel for a single POS
     * 
     * @param object $pos POS data from corporation_starbases
     * @return array ['tracked' => bool, 'message' => string]
     */
    private function trackPosFuel($pos)
    {
        try {
            // SeAT stores state as a string in corporation_starbases; the
            // history column is an integer.
            $stateInteger = $this->convertStateToInteger($pos->state);

            $lastHistory = StarbaseFuelHistory::where('starbase_id', $pos->starbase_id)
                ->orderBy('created_at', 'desc')
                ->first();

            // A tower only burns anything while it is ONLINE (fuel) or
            // REINFORCED (strontium). Offline, onlining, unanchoring and
            // unanchored towers consume nothing, so polling them every 10
            // minutes just writes 144 identical rows a day and gives the
            // consumption chart a flat tail that is not real data.
            //
            // Reinforced is deliberately still tracked: that is exactly when
            // strontium is burning and the operator most needs the timer.
            //
            // The state change itself is worth one row, so the history shows
            // when the tower went down. After that the tower stays quiet
            // until it comes back. (The diagnostic's POS coverage check has
            // always assumed this, skipping non-online/reinforced towers when
            // counting expected polls.)
            if (! in_array($stateInteger, [self::STATE_REINFORCED, self::STATE_ONLINE], true)) {
                if ($lastHistory && (int) $lastHistory->state === (int) $stateInteger) {
                    return [
                        'tracked' => false,
                        'reason'  => 'state',
                        'message' => "POS {$pos->starbase_id} is not online or reinforced, already recorded",
                    ];
                }

                $this->writeStateTransitionRow($pos, $stateInteger, $lastHistory);

                return [
                    'tracked' => false,
                    'reason'  => 'state',
                    'message' => "POS {$pos->starbase_id} changed to state {$stateInteger}, transition recorded",
                ];
            }

            // Get current fuel blocks
            $fuelBlocks = DB::table('corporation_starbase_fuels')
                ->where('starbase_id', $pos->starbase_id)
                ->where('corporation_id', $pos->corporation_id)
                ->whereIn('type_id', array_keys(TypeIdRegistry::FUEL_BLOCK_NAMES))
                ->sum('quantity');
            
            // Get current strontium. Use sum() instead of value() so multiple
            // strontium stacks (edge case observed on some SeAT schemas) are
            // correctly aggregated rather than returning only the first row.
            $strontium = DB::table('corporation_starbase_fuels')
                ->where('starbase_id', $pos->starbase_id)
                ->where('corporation_id', $pos->corporation_id)
                ->where('type_id', TypeIdRegistry::STRONTIUM)
                ->sum('quantity');
            
            // Get current charters (if high-sec)
            $charters = DB::table('corporation_starbase_fuels')
                ->where('starbase_id', $pos->starbase_id)
                ->where('corporation_id', $pos->corporation_id)
                ->whereIn('type_id', array_keys(TypeIdRegistry::CHARTER_NAMES))
                ->sum('quantity');
            
            // Get fuel consumption rates
            $rates = PosFuelCalculator::getFuelConsumptionRate($pos->type_id, $pos->system_security);
            
            // Get strontium status
            $strontiumStatus = PosFuelCalculator::getStrontiumStatus($pos->type_id, $strontium);
            
            // Calculate days remaining
            $daysRemaining = PosFuelCalculator::calculateDaysRemaining(
                $pos->type_id,
                $fuelBlocks,
                $strontium,
                $charters,
                $pos->system_security
            );
            
            // Sanity-check the reading before it becomes a history row.
            // A tower's blocks per hour is known, so a drop far larger than
            // the elapsed time can account for is not consumption. It is
            // either a real withdrawal or a bad read, and a single poll
            // cannot tell those apart. Defer one poll and let the next one
            // decide: if the fuel is still gone it was real and gets
            // written, if it came back it was a bad read and never reaches
            // the consumption chart or the notifier.
            if ($this->fuelReadingIsSuspect($pos, $fuelBlocks, $lastHistory)) {
                return [
                    'tracked' => false,
                    'reason'  => 'suspect',
                    'message' => "POS {$pos->starbase_id} fuel reading deferred for confirmation",
                ];
            }

            // Calculate fuel consumption since last check
            $fuelBlocksUsed = null;
            $fuelHourlyConsumption = null;

            if ($lastHistory && $lastHistory->fuel_blocks_quantity) {
                $fuelBlocksUsed = $lastHistory->fuel_blocks_quantity - $fuelBlocks;
                // v2.0.2 — minute-precision elapsed time. See the matching
                // change in TrackFuelConsumption for the full background;
                // diffInHours floors and inflates per-hour rate calculations
                // when the cron gap isn't a clean integer hour.
                $hoursSinceLastCheck = Carbon::now()->diffInMinutes($lastHistory->created_at, true) / 60.0;

                if ($hoursSinceLastCheck > 0 && $fuelBlocksUsed > 0) {
                    $fuelHourlyConsumption = round($fuelBlocksUsed / $hoursSinceLastCheck, 4);
                }
            }
            
            // Determine space type
            $spaceType = 'Unknown';
            if ($pos->system_security !== null) {
                if ($pos->system_security >= PosFuelCalculator::HIGH_SEC_THRESHOLD) {
                    $spaceType = 'High-Sec';
                } elseif ($pos->system_security > 0) {
                    $spaceType = 'Low-Sec';
                } else {
                    $spaceType = 'Null-Sec';
                }
            }
            
            // Preserve notification tracking fields from previous record
            // This ensures interval tracking works correctly across history records
            $notificationTracking = [];
            if ($lastHistory) {
                $notificationTracking = [
                    'last_fuel_notification_status' => $lastHistory->last_fuel_notification_status,
                    'last_fuel_notification_at' => $lastHistory->last_fuel_notification_at,
                    'fuel_final_alert_sent' => $lastHistory->fuel_final_alert_sent ?? false,
                    'last_strontium_notification_status' => $lastHistory->last_strontium_notification_status,
                    'last_strontium_notification_at' => $lastHistory->last_strontium_notification_at,
                    'strontium_final_alert_sent' => $lastHistory->strontium_final_alert_sent ?? false,
                ];
            }
            
            // Nothing moved, and the heartbeat is not due. The reading was
            // still taken and still checked; there is just no new fact to
            // record.
            if ($this->historyReadingIsRedundant(
                $lastHistory, $fuelBlocks, $strontium, $charters, $stateInteger
            )) {
                return [
                    'tracked' => false,
                    'reason'  => 'unchanged',
                    'message' => "POS {$pos->starbase_id} unchanged since the last reading",
                ];
            }

            // Create history record
            StarbaseFuelHistory::create(array_merge([
                'starbase_id' => $pos->starbase_id,
                'corporation_id' => $pos->corporation_id,
                'tower_type_id' => $pos->type_id,
                'starbase_name' => $pos->starbase_name ?? null, // May not exist in all SeAT versions
                'system_id' => $pos->system_id,
                'state' => $stateInteger, // POS state as integer (converted from string)
                
                // Fuel blocks
                'fuel_blocks_quantity' => $fuelBlocks,
                'fuel_days_remaining' => $daysRemaining['fuel_days'],
                'fuel_blocks_used' => $fuelBlocksUsed,
                'fuel_hourly_consumption' => $fuelHourlyConsumption,
                
                // Strontium
                'strontium_quantity' => $strontium,
                'strontium_hours_available' => $strontiumStatus['hours_available'],
                'strontium_status' => $strontiumStatus['status'],
                
                // Charters
                'charter_quantity' => $charters,
                'charter_days_remaining' => $daysRemaining['charter_days'],
                'requires_charters' => $rates['requires_charters'],
                
                // Calculated
                'actual_days_remaining' => $daysRemaining['actual_days'],
                'limiting_factor' => $daysRemaining['limiting_factor'],
                'estimated_fuel_expiry' => $daysRemaining['fuel_runs_out'],
                
                // Context
                'system_security' => $pos->system_security,
                'space_type' => $spaceType,
                
                'metadata' => [
                    'tower_type' => $pos->tower_type,
                    'system_name' => $pos->system_name,
                    'location_name' => $pos->location_name ?? null, // Moon/location from assets
                    'state' => $pos->state, // Keep original string in metadata for reference
                    'fuel_per_hour' => $rates['fuel_per_hour'],
                    'strontium_per_hour' => $rates['strontium_for_reinforced'],
                    'charters_per_hour' => $rates['charters_per_hour'],
                ],
            ], $notificationTracking));
            
            return [
                'tracked' => true,
                'message' => "POS " . ($pos->starbase_name ?? $pos->starbase_id) . " tracked successfully"
            ];
            
        } catch (\Exception $e) {
            Log::error("TrackPosesFuel: Error tracking POS {$pos->starbase_id}: " . $e->getMessage());
            return [
                'tracked' => false,
                'message' => "Error: " . $e->getMessage()
            ];
        }
    }
    
    /**
     * Decide whether a fuel reading is too far off expected consumption to
     * trust on its own.
     *
     * Returns true to DEFER the reading (skip this poll), false to accept it.
     * A deferral is never permanent: once the same shortfall shows up
     * FUEL_SUSPECT_CONFIRM_COUNT polls running it is accepted and written, so
     * a genuine withdrawal still reaches the notifier, just one poll later.
     *
     * @param object                     $pos
     * @param int|float                  $fuelBlocks  Current blocks in the bay
     * @param \StructureManager\Models\StarbaseFuelHistory|null $lastHistory
     */
    /**
     * True when this reading would produce a row identical to the last one.
     *
     * State is included deliberately. Transitions into and out of the
     * online/reinforced pair are handled earlier, but a tower can move
     * between online and reinforced with every quantity unchanged, and that
     * transition is the one an operator most needs in the history.
     */
    private function historyReadingIsRedundant(
        $lastHistory,
        $fuelBlocks,
        $strontium,
        $charters,
        $stateInteger
    ): bool {
        if (! $lastHistory) {
            return false;
        }

        if ((int) $lastHistory->created_at->diffInMinutes(now(), true)
            >= self::HISTORY_SNAPSHOT_HOURS * 60) {
            return false;
        }

        return (int) $lastHistory->fuel_blocks_quantity === (int) $fuelBlocks
            && (int) $lastHistory->strontium_quantity === (int) $strontium
            && (int) $lastHistory->charter_quantity === (int) $charters
            && (int) $lastHistory->state === (int) $stateInteger;
    }

    private function fuelReadingIsSuspect($pos, $fuelBlocks, $lastHistory): bool
    {
        // TypeIdRegistry is the plugin's source of truth for burn rates.
        // Returns null for a tower neither the registry nor the SDE knows,
        // in which case there is no basis for calling a reading wrong.
        $perHour = (float) (TypeIdRegistry::posTowerHourlyRate((int) $pos->type_id) ?? 0);

        if (! $lastHistory || $perHour <= 0) {
            return false;
        }

        $previous = (int) ($lastHistory->fuel_blocks_quantity ?? 0);
        $drop = $previous - (int) $fuelBlocks;

        // Only a DROP is interesting. Refuelling is an increase, and a
        // reading that matches expectations needs no defence.
        if ($drop <= 0) {
            return false;
        }

        $hours = Carbon::now()->diffInMinutes($lastHistory->created_at, true) / 60.0;

        // A tower does not burn smoothly. EVE deducts a whole hour's fuel in
        // one tick, so a 10-minute poll gap either sees no change at all or
        // the entire hourly batch. Budgeting only perHour * elapsed would
        // flag every tick on every tower. Any window can contain one more
        // tick than the elapsed hours suggest, hence the + 1.
        $expected = $perHour * ($hours + 1.0);
        $threshold = $expected * self::FUEL_DROP_TOLERANCE;

        $cacheKey = 'sm:pos_fuel_suspect:' . $pos->starbase_id;

        if ($drop <= $threshold) {
            // Plausible burn. Any streak from an earlier poll is stale.
            Cache::forget($cacheKey);

            return false;
        }

        $streak = ((int) Cache::get($cacheKey, 0)) + 1;

        if ($streak >= self::FUEL_SUSPECT_CONFIRM_COUNT) {
            // Confirmed across polls, so it is real: a withdrawal, an
            // unfuel, or a tower that genuinely lost its bay. Accept it and
            // let the notifier see it.
            Cache::forget($cacheKey);

            Log::warning(sprintf(
                'TrackPosesFuel: POS %d fuel drop of %d blocks confirmed over %d polls '
                . '(expected about %.1f for %.2fh), recording it',
                $pos->starbase_id,
                $drop,
                $streak,
                $expected,
                $hours
            ));

            return false;
        }

        Cache::put($cacheKey, $streak, Carbon::now()->addHours(self::DEPLETION_CACHE_HOURS));

        Log::warning(sprintf(
            'TrackPosesFuel: POS %d fuel read %d after %d (drop %d, expected about %.1f for %.2fh), '
            . 'deferring for confirmation (%d of %d)',
            $pos->starbase_id,
            (int) $fuelBlocks,
            $previous,
            $drop,
            $expected,
            $hours,
            $streak,
            self::FUEL_SUSPECT_CONFIRM_COUNT
        ));

        return true;
    }

    /**
     * Record a single row marking that a tower left the online/reinforced
     * pair, so the history shows when it went down without then polling it
     * every 10 minutes while nothing about it changes.
     *
     * Fuel quantities are carried over from the last known good reading
     * rather than zeroed: an offline tower still physically holds its fuel,
     * and writing zeros here would read as a withdrawal further downstream.
     *
     * @param object $pos
     * @param int|null $stateInteger
     * @param \StructureManager\Models\StarbaseFuelHistory|null $lastHistory
     */
    private function writeStateTransitionRow($pos, $stateInteger, $lastHistory)
    {
        $spaceType = 'Unknown';
        if ($pos->system_security !== null) {
            if ($pos->system_security >= PosFuelCalculator::HIGH_SEC_THRESHOLD) {
                $spaceType = 'High-Sec';
            } elseif ($pos->system_security > 0) {
                $spaceType = 'Low-Sec';
            } else {
                $spaceType = 'Null-Sec';
            }
        }

        StarbaseFuelHistory::create([
            'starbase_id'    => $pos->starbase_id,
            'corporation_id' => $pos->corporation_id,
            'tower_type_id'  => $pos->type_id,
            'starbase_name'  => $pos->starbase_name ?? null,
            'system_id'      => $pos->system_id,
            'state'          => $stateInteger,

            'fuel_blocks_quantity'      => $lastHistory->fuel_blocks_quantity ?? 0,
            'fuel_days_remaining'       => $lastHistory->fuel_days_remaining ?? 0,
            'strontium_quantity'        => $lastHistory->strontium_quantity ?? 0,
            'strontium_hours_available' => $lastHistory->strontium_hours_available ?? 0,
            'strontium_status'          => $lastHistory->strontium_status ?? 'none',
            'charter_quantity'          => $lastHistory->charter_quantity ?? 0,
            'charter_days_remaining'    => $lastHistory->charter_days_remaining ?? 0,
            'requires_charters'         => $lastHistory->requires_charters ?? false,
            'actual_days_remaining'     => $lastHistory->actual_days_remaining ?? 0,
            'limiting_factor'           => 'not_online',

            'system_security' => $pos->system_security,
            'space_type'      => $spaceType,

            // Start the tower clean, so whatever alert ladder it was on does
            // not resume mid-sequence when it comes back up.
            'last_fuel_notification_status'      => null,
            'last_fuel_notification_at'          => null,
            'fuel_final_alert_sent'              => false,
            'last_strontium_notification_status' => null,
            'last_strontium_notification_at'     => null,
            'strontium_final_alert_sent'         => false,

            'metadata' => [
                'tower_type'     => $pos->tower_type,
                'system_name'    => $pos->system_name,
                'location_name'  => $pos->location_name ?? null,
                'state'          => $pos->state,
                'state_change'   => true,
                'previous_state' => $lastHistory->state ?? null,
            ],
        ]);
    }
    /**
     * Track fuel reserves for a POS (fuel held in corp hangars elsewhere)
     *
     * POSes have no hangars of their own, so their reserves are whatever the
     * corporation is holding in CorpSAG divisions anywhere. $corporationReserves
     * is the already-aggregated result of fetchCorporationReserves() for this
     * POS's corporation, keyed "locationId:typeId".
     *
     * A row is written only when a tuple is new, has actually changed, or has
     * gone RESERVE_SNAPSHOT_HOURS without a snapshot. The previous behaviour
     * wrote every tuple on every 10-minute poll, which on a 28-POS install
     * produced roughly 36k rows a day and a multi-million-row table whose own
     * latest-row lookup then became the job's bottleneck. This mirrors what
     * TrackFuelConsumption already does for Upwell structures.
     *
     * @param int   $starbaseId
     * @param int   $corporationId
     * @param int   $systemId
     * @param array $corporationReserves Aggregated corp stock, keyed "locationId:typeId"
     * @return int Rows written
     */
    private function trackPosReserves($starbaseId, $corporationId, $systemId, array $corporationReserves)
    {
        try {
            // Latest row per (location, resource) for this POS in ONE query,
            // rather than a separate ordered lookup per tuple.
            $latest = StarbaseFuelReserves::whereIn('id', function ($query) use ($starbaseId) {
                    $query->selectRaw('MAX(id)')
                        ->from('starbase_fuel_reserves')
                        ->where('starbase_id', $starbaseId)
                        ->groupBy('location_id', 'resource_type_id');
                })
                ->get()
                ->keyBy(function ($row) {
                    return $row->location_id . ':' . $row->resource_type_id;
                });

            $now = Carbon::now();
            $written = 0;

            foreach ($corporationReserves as $key => $reserve) {
                $last = $latest->get($key);

                $shouldTrack = false;
                $quantityChange = null;
                $isRefuelEvent = false;

                if (! $last) {
                    // First time this tuple has been seen for this POS.
                    $shouldTrack = true;
                } elseif ((int) $last->reserve_quantity !== $reserve['quantity']) {
                    $shouldTrack = true;
                    $quantityChange = $reserve['quantity'] - (int) $last->reserve_quantity;

                    // Negative change means stock left the hangar, which for a
                    // POS reserve most likely means it was hauled to a tower.
                    $isRefuelEvent = $quantityChange < 0;
                } elseif ($last->created_at === null
                    || $last->created_at->diffInHours($now, true) >= self::RESERVE_SNAPSHOT_HOURS) {
                    $shouldTrack = true;
                    $quantityChange = 0;
                }

                if (! $shouldTrack) {
                    continue;
                }

                $this->writeReserveRow(
                    $starbaseId,
                    $corporationId,
                    $systemId,
                    $reserve,
                    $last ? (int) $last->reserve_quantity : null,
                    $quantityChange,
                    $isRefuelEvent,
                    $now
                );

                $written++;
            }

            // Depletion pass. When stock is moved out of a hangar entirely the
            // asset row simply disappears, which shows up above as "no rows"
            // rather than an explicit zero. Without this the latest row for
            // that tuple keeps reporting the old positive quantity forever, so
            // anything filtering on reserve_quantity > 0 renders fuel that is
            // no longer there.
            foreach ($latest as $key => $last) {
                $absenceKey = sprintf(
                    'sm:pos_depletion_absence:%d:%d:%s',
                    $corporationId,
                    $starbaseId,
                    $key
                );

                if (isset($corporationReserves[$key])) {
                    // Present this poll, so any absence streak is stale.
                    Cache::forget($absenceKey);
                    continue;
                }

                if ((int) $last->reserve_quantity <= 0) {
                    continue;
                }

                // Absent this poll. Require the absence to repeat before
                // committing a zero row, so a poll that raced SeAT's
                // corporation_assets refresh does not read as a withdrawal.
                $absenceCount = ((int) Cache::get($absenceKey, 0)) + 1;

                if ($absenceCount < self::DEPLETION_CONFIRM_COUNT) {
                    Cache::put($absenceKey, $absenceCount, Carbon::now()->addHours(self::DEPLETION_CACHE_HOURS));
                    Log::debug(sprintf(
                        'TrackPosesFuel: depletion suspected for POS %d / location %d / resource %d (absence %d of %d), deferring',
                        $starbaseId,
                        (int) $last->location_id,
                        (int) $last->resource_type_id,
                        $absenceCount,
                        self::DEPLETION_CONFIRM_COUNT
                    ));
                    continue;
                }

                Cache::forget($absenceKey);

                $previous = (int) $last->reserve_quantity;

                $this->writeReserveRow(
                    $starbaseId,
                    $corporationId,
                    $systemId,
                    [
                        'location_id'   => (int) $last->location_id,
                        'type_id'       => (int) $last->resource_type_id,
                        'category'      => $last->resource_category,
                        'quantity'      => 0,
                        'location_flag' => $last->location_flag,
                        'divisions'     => [],
                        'stack_count'   => 0,
                    ],
                    $previous,
                    -$previous,
                    true,
                    $now,
                    true
                );

                $written++;
            }

            return $written;

        } catch (\Exception $e) {
            Log::error("TrackPosesFuel: Error tracking reserves for POS {$starbaseId}: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Persist one reserve row.
     *
     * metadata is passed as a real array on purpose. The model casts metadata
     * to 'array', so Laravel encodes it on save; handing it a pre-encoded
     * string here would double-encode the column and break JSON_EXTRACT for
     * every downstream reader.
     *
     * @param array $reserve Aggregated tuple from fetchCorporationReserves()
     */
    private function writeReserveRow(
        $starbaseId,
        $corporationId,
        $systemId,
        array $reserve,
        $previousQuantity,
        $quantityChange,
        $isRefuelEvent,
        Carbon $now,
        bool $depleted = false
    ) {
        StarbaseFuelReserves::create([
            'starbase_id'        => $starbaseId,
            'corporation_id'     => $corporationId,
            'location_id'        => $reserve['location_id'],
            'resource_type_id'   => $reserve['type_id'],
            'resource_category'  => $reserve['category'],
            'reserve_quantity'   => $reserve['quantity'],
            'location_flag'      => $reserve['location_flag'],
            'previous_quantity'  => $previousQuantity,
            'quantity_change'    => $quantityChange,
            'is_refuel_event'    => $isRefuelEvent,
            'refuel_detected_at' => $isRefuelEvent ? $now : null,
            'metadata'           => [
                'system_id'   => $systemId,
                'divisions'   => $reserve['divisions'],
                'stack_count' => $reserve['stack_count'],
                'depleted'    => $depleted,
            ],
        ]);
    }

    /**
     * Fetch and aggregate one corporation's CorpSAG fuel, strontium and
     * charter stock, keyed "locationId:typeId".
     *
     * Aggregation matters. A hangar can hold the same fuel type across several
     * physical stacks, and every reader treats the reserve table as one row
     * per (location, resource). Writing a row per stack meant the latest-row
     * lookup returned whichever stack happened to be written last instead of
     * the total held at that location.
     *
     * @return array<string, array>
     */
    private function fetchCorporationReserves(int $corporationId): array
    {
        $fuelTypeIds = array_keys(TypeIdRegistry::FUEL_BLOCK_NAMES);
        $charterTypeIds = array_keys(TypeIdRegistry::CHARTER_NAMES);

        // One flat whereIn rather than an OR group, so the composite
        // (corporation_id, type_id) index can serve the whole predicate.
        $typeIds = array_merge($fuelTypeIds, [TypeIdRegistry::STRONTIUM], $charterTypeIds);

        $rows = DB::table('corporation_assets')
            ->where('corporation_id', $corporationId)
            ->whereIn('type_id', $typeIds)
            ->where('location_flag', 'like', 'CorpSAG%')
            ->whereNotNull('quantity')
            ->where('quantity', '>', 0)
            ->select('location_id', 'type_id', 'location_flag', 'quantity')
            ->get();

        $aggregated = [];

        foreach ($rows as $row) {
            $category = $this->resourceCategory((int) $row->type_id, $fuelTypeIds, $charterTypeIds);

            if ($category === null) {
                continue;
            }

            $key = $row->location_id . ':' . $row->type_id;

            if (! isset($aggregated[$key])) {
                $aggregated[$key] = [
                    'location_id'   => (int) $row->location_id,
                    'type_id'       => (int) $row->type_id,
                    'category'      => $category,
                    'quantity'      => 0,
                    'location_flag' => $row->location_flag,
                    'divisions'     => [],
                    'stack_count'   => 0,
                ];
            }

            $aggregated[$key]['quantity'] += (int) $row->quantity;
            $aggregated[$key]['stack_count']++;
            $aggregated[$key]['divisions'][$row->location_flag] =
                ($aggregated[$key]['divisions'][$row->location_flag] ?? 0) + (int) $row->quantity;
        }

        // The row carries a single location_flag but the aggregated total can
        // span divisions. Report the division holding the most and keep the
        // full breakdown in metadata.
        foreach ($aggregated as $key => $entry) {
            arsort($aggregated[$key]['divisions']);
            $aggregated[$key]['location_flag'] = (string) array_key_first($aggregated[$key]['divisions']);
        }

        return $aggregated;
    }

    /**
     * Map a type ID to its reserve category.
     *
     * @return string|null null when the type is not a tracked reserve resource
     */
    private function resourceCategory(int $typeId, array $fuelTypeIds, array $charterTypeIds): ?string
    {
        if (in_array($typeId, $fuelTypeIds, true)) {
            return 'fuel';
        }

        if ($typeId === TypeIdRegistry::STRONTIUM) {
            return 'strontium';
        }

        if (in_array($typeId, $charterTypeIds, true)) {
            return 'charter';
        }

        return null;
    }

    /**
     * Clean up orphaned POSes that no longer exist in corporation_starbases
     *
     * When a POS is unanchored/removed from the game, it disappears from corporation_starbases
     * but the history records remain. This method:
     * 1. Finds POSes with recent history that no longer exist in corporation_starbases
     * 2. Creates a final "unanchored" record (state=0) to mark them as removed
     * 3. Resets notification tracking to prevent future alerts
     *
     * @param array $currentPosIds Array of starbase_ids that currently exist
     * @return int Number of orphaned POSes cleaned up
     */
    private function cleanupOrphanedPoses(array $currentPosIds)
    {
        try {
            // Find POSes that have history records but no longer exist in corporation_starbases
            // Only look at POSes whose latest record shows them as online/reinforced (state 3 or 4)
            // to avoid processing POSes that were already marked as unanchored
            $orphanedPoses = StarbaseFuelHistory::select(
                    'starbase_id',
                    'corporation_id',
                    'tower_type_id',
                    'starbase_name',
                    'system_id',
                    'system_security',
                    'space_type',
                    'metadata'
                )
                ->whereIn('id', function($query) {
                    $query->selectRaw('MAX(id)')
                        ->from('starbase_fuel_history')
                        ->groupBy('starbase_id');
                })
                ->whereIn('state', [self::STATE_REINFORCED, self::STATE_ONLINE]) // Last known state was online or reinforced
                ->when(!empty($currentPosIds), function($query) use ($currentPosIds) {
                    // Exclude POSes that currently exist
                    $query->whereNotIn('starbase_id', $currentPosIds);
                }, function($query) {
                    // If no current POSes, check against corporation_starbases directly
                    $query->whereNotExists(function($subQuery) {
                        $subQuery->select(DB::raw(1))
                            ->from('corporation_starbases')
                            ->whereColumn('corporation_starbases.starbase_id', 'starbase_fuel_history.starbase_id');
                    });
                })
                ->get();

            $cleanedCount = 0;

            foreach ($orphanedPoses as $orphan) {
                Log::warning("TrackPosesFuel: Detected orphaned POS {$orphan->starbase_id} (" .
                    ($orphan->starbase_name ?? 'Unnamed') . ") - marking as unanchored");

                // Create a final history record marking the POS as unanchored (state=0)
                // This prevents future notifications and provides an audit trail
                StarbaseFuelHistory::create([
                    'starbase_id' => $orphan->starbase_id,
                    'corporation_id' => $orphan->corporation_id,
                    'tower_type_id' => $orphan->tower_type_id,
                    'starbase_name' => $orphan->starbase_name,
                    'system_id' => $orphan->system_id,
                    'state' => self::STATE_UNANCHORED, // prevents any future notifications

                    // Zero out fuel data since POS no longer exists
                    'fuel_blocks_quantity' => 0,
                    'fuel_days_remaining' => 0,
                    'strontium_quantity' => 0,
                    'strontium_hours_available' => 0,
                    'strontium_status' => 'none',
                    'charter_quantity' => 0,
                    'charter_days_remaining' => 0,
                    'requires_charters' => false,
                    'actual_days_remaining' => 0,
                    'limiting_factor' => 'unanchored',

                    // Context
                    'system_security' => $orphan->system_security,
                    'space_type' => $orphan->space_type,

                    // CRITICAL: Reset all notification tracking to prevent future alerts
                    'last_fuel_notification_status' => null,
                    'last_fuel_notification_at' => null,
                    'fuel_final_alert_sent' => false,
                    'last_strontium_notification_status' => null,
                    'last_strontium_notification_at' => null,
                    'strontium_final_alert_sent' => false,

                    'metadata' => array_merge(
                        is_array($orphan->metadata) ? $orphan->metadata : [],
                        [
                            'cleanup_reason' => 'POS no longer exists in corporation_starbases',
                            'cleanup_at' => Carbon::now()->toIso8601String(),
                            'previous_state' => 'online/reinforced',
                        ]
                    ),
                ]);

                $cleanedCount++;
            }

            return $cleanedCount;

        } catch (\Exception $e) {
            Log::error("TrackPosesFuel: Error cleaning up orphaned POSes: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Convert state string to integer
     *
     * SeAT stores state as string in corporation_starbases (e.g., "online", "offline")
     * but we need to store it as integer in history for proper querying
     *
     * @param mixed $state State value (string or integer)
     * @return int|null State as integer
     */
    private function convertStateToInteger($state)
    {
        // If already an integer, return it
        if (is_int($state)) {
            return $state;
        }
        
        // If null, return null
        if ($state === null) {
            return null;
        }
        
        // Convert string to lowercase for comparison
        $stateString = strtolower(trim($state));
        
        // Map string states to integers
        $stateMap = [
            'unanchored' => 0,
            'offline' => 1,
            'onlining' => 2,
            'reinforced' => 3,
            'online' => 4,
            'unanchoring' => 5, // Also exists in ESI
        ];
        
        // Return mapped value or null if unknown
        return $stateMap[$stateString] ?? null;
    }
}
