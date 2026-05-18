<?php

namespace StructureManager\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use StructureManager\Models\StructureFuelHistory;
use StructureManager\Models\StructureFuelReserves;
use StructureManager\Helpers\FuelCalculator;
use StructureManager\Helpers\TypeIdRegistry;
use StructureManager\Services\FuelEventClassifier;
use StructureManager\Services\LocationResolver;
use StructureManager\Jobs\WithdrawalForensicsJob;
use Carbon\Carbon;

class TrackFuelConsumption implements ShouldQueue
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
     * Fuel block type IDs from EVE.
     * @deprecated use TypeIdRegistry::FUEL_BLOCK_IDS
     */
    const FUEL_BLOCK_TYPES = TypeIdRegistry::FUEL_BLOCK_IDS;

    /**
     * Magmatic Gas type ID.
     * @deprecated use TypeIdRegistry::MAGMATIC_GAS
     */
    const MAGMATIC_GAS_TYPE_ID = TypeIdRegistry::MAGMATIC_GAS;

    /**
     * Metenox Moon Drill type ID.
     * @deprecated use TypeIdRegistry::METENOX
     */
    const METENOX_TYPE_ID = TypeIdRegistry::METENOX;

    /**
     * Execute the job.
     */
    public function handle()
    {
        // Flush per-request location cache so a long-running worker
        // doesn't accumulate stale entries across job runs.
        LocationResolver::flushCache();

        $structures = DB::table('corporation_structures')
            ->whereNotNull('fuel_expires')
            ->get();

        Log::info('TrackFuelConsumption: Processing ' . $structures->count() . ' structures');

        $tracked = 0;
        $skipped = 0;
        $fuelBaySuccess = 0;
        $fallbackMethod = 0;
        $reservesTracked = 0;
        $metenoxTracked = 0;
        $externalReservesTracked = 0;

        foreach ($structures as $structure) {
            // Isolate each structure so one bad row (malformed fuel_expires, missing
            // SDE join, etc.) does not abort the whole job and skip the rest.
            try {
                // Check if this is a Metenox Moon Drill
                $isMetenox = $structure->type_id == self::METENOX_TYPE_ID;

                if ($isMetenox) {
                    $result = $this->trackMetenoxFuel($structure);
                    if ($result['tracked']) {
                        $metenoxTracked++;
                        $tracked++;
                    } else {
                        $skipped++;
                    }
                } else {
                    $result = $this->trackStructureFuel($structure);
                    if ($result['tracked']) {
                        $tracked++;
                        if ($result['method'] === 'fuel_bay') {
                            $fuelBaySuccess++;
                        } else {
                            $fallbackMethod++;
                        }
                    } else {
                        $skipped++;
                    }
                }

                // Track reserves for ALL structures (including Metenox): Metenox needs
                // tracking for both fuel blocks AND magmatic gas reserves.
                if ($this->trackStructureReserves($structure->structure_id, $structure->corporation_id, $isMetenox)) {
                    $reservesTracked++;
                }
            } catch (\Throwable $e) {
                Log::error("TrackFuelConsumption: Error tracking structure {$structure->structure_id}: " . $e->getMessage());
                $skipped++;
                // Continue with next structure.
            }
        }
        
        // ============================================================
        // v2.0.0 — corp-wide sweep for EXTERNAL reserves
        // ============================================================
        // Catches CorpSAG fuel staged in NPC stations or other corps'
        // Upwell structures — anywhere outside the corp's own
        // citadels/refineries. Runs after the per-structure loop so we
        // already have the corp-id list and don't need a second query.
        //
        // Note: corps with NO owned structures still need their external
        // reserves tracked — we union them in from corporation_assets
        // directly so they don't get missed.
        try {
            $corpIds = $structures->pluck('corporation_id')->unique();

            // Also include any corp that has CorpSAG fuel ANYWHERE but
            // wasn't already in the structure list (e.g. lost all
            // structures but still has fuel staged in NPC stations).
            $extraCorps = DB::table('corporation_assets')
                ->where('location_flag', 'LIKE', 'CorpSAG%')
                ->whereIn('type_id', array_merge(self::FUEL_BLOCK_TYPES, [self::MAGMATIC_GAS_TYPE_ID]))
                ->whereNotIn('corporation_id', $corpIds)
                ->distinct()
                ->pluck('corporation_id');

            foreach ($corpIds->merge($extraCorps)->unique() as $corpId) {
                $externalReservesTracked += $this->trackExternalReserves((int) $corpId);
            }
        } catch (\Throwable $e) {
            Log::error("TrackFuelConsumption: External reserves sweep failed: " . $e->getMessage());
        }

        // ============================================================
        // v2.0.0 — corp-wide reconciliation pass for DEPLETED reserves
        // ============================================================
        // Without this pass, when fuel is moved OUT of a previously-tracked
        // CorpSAG location, the latest row in structure_fuel_reserves keeps
        // showing the old positive quantity forever (because the move just
        // produces "no rows" in the next sweep, not an explicit "0 row").
        // The UI then renders the same fuel twice: once at the old location
        // (stale data) and once at the new location (fresh data).
        //
        // This pass walks every (structure_id, fuel_type, location_flag)
        // tuple with a positive latest quantity, verifies it's still
        // physically present in corporation_assets, and inserts a depletion
        // row (reserve_quantity=0, quantity_change=-previous, is_refuel_event=
        // true) for any tuple that's missing. Controllers filter
        // reserve_quantity>0 so the UI doesn't render "0 blocks" rows;
        // depletion rows still appear on the Fuel Withdrawals tab as
        // proper withdrawal events with a -N quantity_change.
        $depletedRows = 0;
        try {
            foreach ($corpIds->merge($extraCorps)->unique() as $corpId) {
                $depletedRows += $this->reconcileDepletedReserves((int) $corpId);
            }
        } catch (\Throwable $e) {
            Log::error("TrackFuelConsumption: Depletion reconciliation failed: " . $e->getMessage());
        }

        Log::info("TrackFuelConsumption: Completed. Tracked: $tracked (Fuel Bay: $fuelBaySuccess, Fallback: $fallbackMethod, Metenox: $metenoxTracked), Reserves: $reservesTracked, External reserves: $externalReservesTracked, Depleted: $depletedRows, Skipped: $skipped");

        // Clean old history (keep 6 months)
        $deleted = StructureFuelHistory::where('created_at', '<', Carbon::now()->subMonths(6))->delete();
        if ($deleted > 0) {
            Log::info("TrackFuelConsumption: Cleaned $deleted old history records");
        }
        
        // Clean old reserve records (keep 3 months)
        $deletedReserves = StructureFuelReserves::where('created_at', '<', Carbon::now()->subMonths(3))->delete();
        if ($deletedReserves > 0) {
            Log::info("TrackFuelConsumption: Cleaned $deletedReserves old reserve records");
        }
    }
    
    /**
     * Track fuel for Metenox Moon Drill
     * Tracks BOTH fuel blocks AND magmatic gas
     */
    private function trackMetenoxFuel($structure)
    {
        // Get fuel blocks
        $fuelBlocks = DB::table('corporation_assets')
            ->where('location_id', $structure->structure_id)
            ->where('location_flag', 'StructureFuel')
            ->whereIn('type_id', self::FUEL_BLOCK_TYPES)
            ->sum('quantity');
        
        // Get magmatic gas
        $magmaticGas = DB::table('corporation_assets')
            ->where('location_id', $structure->structure_id)
            ->where('location_flag', 'StructureFuel')
            ->where('type_id', self::MAGMATIC_GAS_TYPE_ID)
            ->sum('quantity');
        
        // Get last record
        $lastRecord = StructureFuelHistory::where('structure_id', $structure->structure_id)
            ->orderBy('created_at', 'desc')
            ->first();
        
        $shouldCreateRecord = false;
        $trackingMethod = 'metenox_fuel_bay';
        
        // Determine if we should create a record
        if (!$lastRecord) {
            $shouldCreateRecord = true;
            Log::info("Metenox {$structure->structure_id}: First snapshot");
        } elseif ($lastRecord->created_at->diffInHours(now(), true) >= 1) {
            $shouldCreateRecord = true;
        }
        
        if ($shouldCreateRecord) {
            // Calculate days remaining for each resource
            $fuelDaysRemaining = $fuelBlocks > 0 ? $fuelBlocks / (5 * 24) : 0;
            $gasDaysRemaining = $magmaticGas > 0 ? $magmaticGas / (200 * 24) : 0;
            $actualDaysRemaining = min($fuelDaysRemaining, $gasDaysRemaining);
            
            // Determine limiting factor
            $limitingFactor = 'none';
            if ($actualDaysRemaining > 0) {
                $limitingFactor = $fuelDaysRemaining < $gasDaysRemaining ? 'fuel_blocks' : 'magmatic_gas';
            }
            
            $metadata = [
                'tracking_method' => $trackingMethod,
                'fuel_blocks' => $fuelBlocks,
                'magmatic_gas' => $magmaticGas,
                'fuel_days_remaining' => round($fuelDaysRemaining, 2),
                'gas_days_remaining' => round($gasDaysRemaining, 2),
                'actual_days_remaining' => round($actualDaysRemaining, 2),
                'limiting_factor' => $limitingFactor,
                'fuel_bay_available' => true,
                'is_metenox' => true,
            ];
            
            $record = StructureFuelHistory::create([
                'structure_id' => $structure->structure_id,
                'corporation_id' => $structure->corporation_id,
                'fuel_expires' => $structure->fuel_expires,
                'days_remaining' => round($actualDaysRemaining),
                'fuel_blocks_used' => null,
                'daily_consumption' => null,
                'consumption_rate' => null,
                'tracking_type' => $trackingMethod,
                'metadata' => json_encode($metadata),
                'magmatic_gas_quantity' => $magmaticGas,
                'magmatic_gas_days' => round($gasDaysRemaining, 1),
            ]);
            
            // Log warning if gas is running low
            if ($limitingFactor === 'magmatic_gas' && $gasDaysRemaining < 7) {
                Log::warning("Metenox {$structure->structure_id}: CRITICAL - Magmatic gas will run out in " . round($gasDaysRemaining, 1) . " days!");
            }
            
            Log::info("Metenox {$structure->structure_id}: Snapshot #{$record->id} " .
                     "(fuel: {$fuelBlocks} blocks = " . round($fuelDaysRemaining, 1) . "d, " .
                     "gas: {$magmaticGas} = " . round($gasDaysRemaining, 1) . "d, " .
                     "limiting: {$limitingFactor})");
            
            return ['tracked' => true, 'method' => $trackingMethod];
        }
        
        return ['tracked' => false, 'method' => null];
    }
    
    /**
     * Track fuel BAY ONLY for consumption analysis (Upwell structures)
     */
    private function trackStructureFuel($structure)
    {
        // METHOD 1: Get fuel blocks from fuel bay ONLY (not reserves)
        $fuelBayData = $this->getFuelBayQuantity($structure->structure_id);
        
        // METHOD 2: Calculate from days_remaining (FALLBACK)
        $currentDaysRemaining = Carbon::parse($structure->fuel_expires)->diffInDays(now(), true);

        // Get last record for comparison
        $lastRecord = StructureFuelHistory::where('structure_id', $structure->structure_id)
            ->orderBy('created_at', 'desc')
            ->first();

        $shouldCreateRecord = false;
        $fuelBlocksUsed = null;
        $dailyConsumption = null;
        $trackingMethod = 'unknown';

        // Determine if we should create a record
        if (!$lastRecord) {
            $shouldCreateRecord = true;
            $trackingMethod = $fuelBayData['available'] ? 'fuel_bay' : 'days_remaining';
            Log::info("Structure {$structure->structure_id}: First snapshot (method: {$trackingMethod})");
        } elseif ($lastRecord->created_at->diffInHours(now(), true) >= 1) {
            $shouldCreateRecord = true;
        }

        // Calculate consumption if we have a previous record
        if ($lastRecord && $shouldCreateRecord) {
            $realHoursPassed = $lastRecord->created_at->diffInHours(now(), true);
            
            if ($realHoursPassed > 0) {
                // METHOD 1: Use actual fuel bay quantities (BEST - ONLY FUEL BAY)
                if ($fuelBayData['available'] && $lastRecord->metadata) {
                    $metadata = json_decode($lastRecord->metadata, true);
                    $previousFuelBlocks = $metadata['fuel_blocks'] ?? null;
                    
                    if ($previousFuelBlocks !== null && $previousFuelBlocks > 0) {
                        $blockChange = $previousFuelBlocks - $fuelBayData['quantity'];
                        
                        if ($blockChange < 0) {
                            // REFUEL detected - fuel bay was topped up
                            $blocksAdded = abs($blockChange);
                            $fuelBlocksUsed = $blockChange; // Negative = fuel added
                            $dailyConsumption = 0;
                            $trackingMethod = 'fuel_bay';
                            Log::info("Structure {$structure->structure_id}: REFUEL detected via fuel bay - added {$blocksAdded} blocks");
                        } else {
                            // Normal consumption
                            $hourlyRate = $blockChange / $realHoursPassed;
                            $dailyRate = $hourlyRate * 24;
                            $dailyConsumption = $dailyRate / 40; // Convert to fuel-days per real day
                            $fuelBlocksUsed = round($blockChange);
                            $trackingMethod = 'fuel_bay';
                            
                            Log::info("Structure {$structure->structure_id}: " . 
                                     "Consumed {$blockChange} blocks over {$realHoursPassed}h = " . 
                                     round($hourlyRate, 2) . " blocks/hour, " .
                                     round($dailyRate) . " blocks/day (method: fuel_bay)");
                        }
                    }
                }
                
                // METHOD 2: Fallback to days_remaining calculation
                if ($trackingMethod === 'unknown' && $lastRecord->days_remaining !== null) {
                    $fuelDaysChange = $lastRecord->days_remaining - $currentDaysRemaining;
                    
                    if ($fuelDaysChange < 0) {
                        // REFUEL detected via days_remaining increase
                        $daysAdded = abs($fuelDaysChange);
                        $fuelBlocksUsed = -1 * round($daysAdded * 40); // Negative indicates fuel added
                        $dailyConsumption = 0;
                        $trackingMethod = 'days_remaining';
                        Log::info("Structure {$structure->structure_id}: REFUEL detected via days_remaining - added ~{$daysAdded} fuel-days");
                    } else {
                        // Normal consumption
                        $realDaysPassed = $realHoursPassed / 24;
                        $consumptionRate = $realDaysPassed > 0 ? $fuelDaysChange / $realDaysPassed : 0;
                        $dailyConsumption = $consumptionRate;
                        $fuelBlocksUsed = round($consumptionRate * 40 * $realDaysPassed);
                        $trackingMethod = 'days_remaining';
                        
                        Log::info("Structure {$structure->structure_id}: " . 
                                 "Consumed {$fuelDaysChange} fuel-days over " . round($realDaysPassed, 2) . 
                                 " real days = " . round($consumptionRate, 2) . " rate, ~{$fuelBlocksUsed} blocks (method: days_remaining)");
                    }
                }
            }
        }
        
        // Create the snapshot record - FUEL BAY ONLY
        if ($shouldCreateRecord) {
            $metadata = [
                'tracking_method' => $trackingMethod,
                'fuel_blocks' => $fuelBayData['quantity'],
                'fuel_bay_available' => $fuelBayData['available'],
                'fuel_type_id' => $fuelBayData['fuel_type_id'],
                'is_metenox' => false,
            ];

            // ============================================================
            // v2.0.0 — Tier 1 event classification
            // ============================================================
            // The classifier needs prev/current bay + reserves + expected
            // hourly burn rate from active services. Wraps everything in a
            // try/catch so a classifier bug never breaks the poll.
            $classification = [
                'event_type' => StructureFuelHistory::EVENT_UNCLASSIFIED,
                'expected_consumption' => null,
                'unexplained_delta' => null,
                'reserves_delta' => null,
            ];

            try {
                $prevBay = null;
                $lastMeta = [];
                if ($lastRecord && $lastRecord->metadata) {
                    $lastMeta = is_array($lastRecord->metadata)
                        ? $lastRecord->metadata
                        : (json_decode($lastRecord->metadata, true) ?: []);
                    $prevBay = $lastMeta['fuel_blocks'] ?? null;
                }

                // Reserves: prev value comes from what we stashed in
                // metadata on the LAST poll. Current value comes from a
                // live read of corporation_assets — NOT the snapshot
                // table, which still holds last-poll values at this
                // point in the job (trackStructureReserves runs AFTER
                // this method per the handle() loop).
                $currentReserves = FuelEventClassifier::liveTotalReserves($structure->structure_id);
                $prevReserves = isset($lastMeta['total_reserves'])
                    ? (int) $lastMeta['total_reserves']
                    : $currentReserves; // first-snapshot fallback

                $hoursElapsed = $lastRecord
                    ? max(0.001, $lastRecord->created_at->diffInHours(now(), true))
                    : 0.0;

                $expectedHourly = FuelEventClassifier::expectedHourlyConsumption(
                    (int) $structure->structure_id
                );

                $classification = FuelEventClassifier::classify([
                    'prev_bay' => $prevBay !== null ? (int) $prevBay : null,
                    'current_bay' => (int) ($fuelBayData['quantity'] ?? 0),
                    'prev_reserves' => $prevReserves,
                    'current_reserves' => $currentReserves,
                    'expected_hourly' => $expectedHourly,
                    'hours_elapsed' => $hoursElapsed,
                ]);

                // Stash the current total reserves so the next poll has
                // an authoritative prev_reserves to diff against.
                $metadata['total_reserves'] = $currentReserves;
            } catch (\Throwable $e) {
                Log::error("Structure {$structure->structure_id}: Classifier error — " . $e->getMessage());
                // Leave classification at the unclassified defaults
            }

            $record = StructureFuelHistory::create([
                'structure_id' => $structure->structure_id,
                'corporation_id' => $structure->corporation_id,
                'fuel_expires' => $structure->fuel_expires,
                'days_remaining' => $currentDaysRemaining,
                'fuel_blocks_used' => $fuelBlocksUsed,
                'daily_consumption' => $dailyConsumption,
                'consumption_rate' => $dailyConsumption,
                'tracking_type' => $trackingMethod,
                'metadata' => json_encode($metadata),
                'magmatic_gas_quantity' => null,
                'magmatic_gas_days' => null,
                'event_type' => $classification['event_type'],
                'expected_consumption' => $classification['expected_consumption'],
                'unexplained_delta' => $classification['unexplained_delta'],
                'reserves_delta' => $classification['reserves_delta'],
            ]);

            Log::info("Structure {$structure->structure_id}: Created fuel bay snapshot #{$record->id} " .
                     "(days: {$currentDaysRemaining}, bay: " . ($fuelBayData['quantity'] ?? 'N/A') .
                     ", method: {$trackingMethod}, event: {$classification['event_type']})");

            // ============================================================
            // Tier 2 — async forensics for withdrawal_* events
            // ============================================================
            // Dispatch to queue so the hourly poll never waits on forensic
            // lookups (which can be slow on large corps).
            if (in_array($classification['event_type'], StructureFuelHistory::WITHDRAWAL_TYPES, true)) {
                try {
                    WithdrawalForensicsJob::dispatch((int) $record->id);
                    Log::info("Structure {$structure->structure_id}: Dispatched WithdrawalForensicsJob for fuel_history #{$record->id} ({$classification['event_type']})");
                } catch (\Throwable $e) {
                    Log::error("Structure {$structure->structure_id}: Failed to dispatch forensics job — " . $e->getMessage());
                }
            }

            return ['tracked' => true, 'method' => $trackingMethod];
        }

        return ['tracked' => false, 'method' => null];
    }
    
    /**
     * Track RESERVES SEPARATELY (CorpSAG hangars)
     * Now handles nested Office containers inside structures
     * UPDATED: Now tracks magmatic gas for Metenox structures
     */
    private function trackStructureReserves($structureId, $corporationId, $isMetenox = false)
    {
        // Determine which fuel types to track
        $fuelTypes = self::FUEL_BLOCK_TYPES;
        
        // FIXED: Always track magmatic gas in ALL structures
        // Gas reserves are stored in regular structures (Astrahus, Fortizar, etc.)
        // because Metenox structures don't have corp hangars!
        $fuelTypes[] = self::MAGMATIC_GAS_TYPE_ID;
        
        // METHOD 1: Direct - Fuel in structure's CorpSAG
        $directReserves = DB::table('corporation_assets')
            ->where('location_id', $structureId)
            ->where('location_flag', 'LIKE', 'CorpSAG%')
            ->whereIn('type_id', $fuelTypes)  
            ->get();
        
        // METHOD 2: Nested - Fuel in Office containers inside the structure
        $nestedReserves = DB::table('corporation_assets as fuel')
            ->join('corporation_assets as office', 'fuel.location_id', '=', 'office.item_id')
            ->join('invTypes as office_type', 'office.type_id', '=', 'office_type.typeID')
            ->where('office.location_id', $structureId)
            ->where('office_type.typeName', 'Office')
            ->where('fuel.location_flag', 'LIKE', 'CorpSAG%')
            ->whereIn('fuel.type_id', $fuelTypes)  // Now includes gas for Metenox
            ->select(
                'fuel.item_id',
                'fuel.type_id',
                'fuel.quantity',
                'fuel.location_flag',
                'fuel.location_id'
            )
            ->get();
        
        // Combine both methods
        $rawReserves = $directReserves->merge($nestedReserves);

        if ($rawReserves->isEmpty()) {
            return false;
        }

        // v2.0.0 dual-stack-aggregation fix:
        // EVE does NOT auto-merge identical items moved into CorpSAG hangars
        // by different characters or at different times. A corp can easily
        // end up with N separate physical stacks of e.g. Hydrogen in
        // CorpSAG3 (one 58,000 stack + ten 1,000 stacks were observed in a
        // single Fortizar). The previous loop iterated each physical stack
        // separately and compared each to a per-tuple lastReserve. Result:
        // every poll wrote a mirror pair of rows (−57,000 / +57,000) as
        // the dedup memory bounced between stacks, polluting the Fuel
        // Withdrawals UI with phantom hourly events.
        //
        // Fix: aggregate every (fuel_type_id, location_flag) tuple by
        // SUMming its physical stacks into a single logical reserve row
        // BEFORE the lastReserve comparison. One row per tuple per poll,
        // regardless of how many stacks the corp has physically left
        // sitting around. Stack-level item_ids are recorded inside the
        // metadata payload for audit (see $stackItemIds below).
        $reserves = $rawReserves
            ->groupBy(fn($r) => $r->type_id . '|' . $r->location_flag)
            ->map(function ($stacks, $key) {
                [$typeId, $locationFlag] = explode('|', $key, 2);
                // Synthesise a single aggregated reserve object. item_id
                // is taken from the FIRST stack for backwards-compat with
                // downstream code that expects a primary item — the full
                // stack list goes into metadata.
                $first = $stacks->first();
                $obj = clone $first;
                $obj->quantity = (int) $stacks->sum('quantity');
                $obj->stack_count = $stacks->count();
                $obj->stack_item_ids = $stacks->pluck('item_id')->all();
                return $obj;
            })
            ->values();

        $gasCount = $reserves->where('type_id', self::MAGMATIC_GAS_TYPE_ID)->count();
        $fuelCount = $reserves->count() - $gasCount;

        if ($isMetenox && $gasCount > 0) {
            Log::debug("Metenox {$structureId}: Found {$reserves->count()} aggregated tuples ({$fuelCount} fuel blocks, {$gasCount} magmatic gas) from {$rawReserves->count()} physical stacks (Direct: {$directReserves->count()}, Nested: {$nestedReserves->count()})");
        } else {
            Log::debug("Structure {$structureId}: Found {$reserves->count()} aggregated tuples from {$rawReserves->count()} physical stacks (Direct: {$directReserves->count()}, Nested: {$nestedReserves->count()})");
        }

        $tracked = false;

        foreach ($reserves as $reserve) {
            // Get last reserve record
            $lastReserve = StructureFuelReserves::where('structure_id', $structureId)
                ->where('fuel_type_id', $reserve->type_id)
                ->where('location_flag', $reserve->location_flag)
                ->orderBy('created_at', 'desc')
                ->first();

            $shouldTrack = false;
            $quantityChange = null;
            $isRefuelEvent = false;

            $resourceType = $reserve->type_id == self::MAGMATIC_GAS_TYPE_ID ? 'magmatic gas' : 'fuel blocks';

            if (!$lastReserve) {
                // First time tracking this reserve
                $shouldTrack = true;
                Log::info("Structure {$structureId}: First time tracking {$reserve->quantity} {$resourceType} in {$reserve->location_flag} (aggregated across {$reserve->stack_count} physical stacks)");
            } elseif ($lastReserve->reserve_quantity != $reserve->quantity) {
                // Quantity changed
                $shouldTrack = true;
                $quantityChange = $reserve->quantity - $lastReserve->reserve_quantity;
                
                // Negative change = fuel moved to bay (refuel event)
                if ($quantityChange < 0) {
                    $isRefuelEvent = true;
                    Log::info("Structure {$structureId}: Reserve {$resourceType} moved - {$quantityChange} from {$reserve->location_flag}");
                } else {
                    Log::info("Structure {$structureId}: Reserve {$resourceType} added - +{$quantityChange} to {$reserve->location_flag}");
                }
            } elseif ($lastReserve->created_at->diffInHours(now(), true) >= 24) {
                // Create daily snapshot even if no change
                $shouldTrack = true;
                $quantityChange = 0;
            }
            
            if ($shouldTrack) {
                // v2.0.0 — resolve denormalized location fields. For owned
                // structures this fills in name + system so the UI doesn't
                // need to join universe_structures / mapDenormalize on every
                // render. LocationResolver caches per-request.
                $locationInfo = LocationResolver::resolve($structureId, (int) $corporationId);

                // Pass the metadata as an ARRAY, not a json_encode()'d
                // string. The model has `metadata` cast to 'array' which
                // means Laravel handles JSON-encoding on save and decoding
                // on read. Passing a pre-encoded string here triggers
                // DOUBLE-ENCODING — the column ends up as a JSON string
                // wrapping a JSON object, which makes
                // JSON_EXTRACT(metadata, '$.tracking_method') return NULL
                // in MariaDB and breaks downstream queries (cleanup
                // command, diagnostic, forensic candidate lookup).
                StructureFuelReserves::create([
                    'structure_id' => $structureId,
                    'corporation_id' => $corporationId,
                    'fuel_type_id' => $reserve->type_id,
                    'reserve_quantity' => $reserve->quantity,
                    'location_flag' => $reserve->location_flag,
                    'previous_quantity' => $lastReserve ? $lastReserve->reserve_quantity : null,
                    'quantity_change' => $quantityChange,
                    'is_refuel_event' => $isRefuelEvent,
                    'location_type' => $locationInfo['location_type'],
                    'location_name' => $locationInfo['location_name'],
                    'location_system_id' => $locationInfo['location_system_id'],
                    'location_system_name' => $locationInfo['location_system_name'],
                    'metadata' => [
                        'item_id' => $reserve->item_id,
                        'location_id' => $reserve->location_id,
                        'tracking_method' => isset($reserve->location_id) && $reserve->location_id != $structureId ? 'nested_office' : 'direct',
                        'is_metenox' => $isMetenox,
                        'resource_type' => $resourceType,
                        // v2.0.0 — record the aggregation so audit trails
                        // can see "this tuple was a sum of N physical
                        // stacks" if/when fuel theft investigations need it.
                        'stack_count' => (int) ($reserve->stack_count ?? 1),
                        'stack_item_ids' => $reserve->stack_item_ids ?? [$reserve->item_id],
                    ],
                ]);

                $tracked = true;
            }
        }

        return $tracked;
    }

    /**
     * Track CorpSAG fuel reserves in NON-owned locations (NPC stations,
     * other corps' Upwell structures, anywhere outside this corp's own
     * citadels/refineries).
     *
     * Two ways fuel can sit in a corp hangar outside owned structures:
     *
     *   1. DIRECT — `corporation_assets.location_id` IS the structure or
     *      station (rare; mostly when CCP changes container semantics).
     *   2. NESTED — fuel sits inside an Office container. The Office is
     *      itself an item; `corporation_assets.location_id` for the fuel
     *      points to the Office's `item_id`, and the Office's own
     *      `location_id` is the actual station/structure. This is the
     *      COMMON case for NPC stations (corps rent Offices and use their
     *      CorpSAG slots for fuel staging).
     *
     * Both paths converge on an "actual_location_id" — the real physical
     * location where the fuel sits — which we hand to LocationResolver
     * for name + system lookup and store as the row's `structure_id`.
     *
     * Dedup against owned structures uses actual_location_id (not the
     * raw fuel.location_id), so fuel-in-an-office-in-an-owned-structure
     * correctly resolves to the owned structure and gets filtered out
     * (trackStructureReserves already tracks those via its own nested
     * Office JOIN in the per-structure loop).
     *
     * Returns the count of unique (location × fuel_type × CorpSAG-N)
     * stacks that were tracked or updated this run.
     */
    private function trackExternalReserves(int $corporationId): int
    {
        $fuelTypes = array_merge(self::FUEL_BLOCK_TYPES, [self::MAGMATIC_GAS_TYPE_ID]);

        // Owned structures — actual_location_id matching one of these is
        // already covered by trackStructureReserves and must be skipped.
        $ownedStructureIds = DB::table('corporation_structures')
            ->where('corporation_id', $corporationId)
            ->pluck('structure_id')
            ->map(fn($v) => (int) $v)
            ->all();

        // STEP 1: DIRECT — CorpSAG fuel whose location_id IS a recognised
        // physical location (NPC station range OR universe_structures row).
        // Excludes container item_ids because those go through STEP 2.
        $directRows = DB::table('corporation_assets')
            ->where('corporation_id', $corporationId)
            ->where('location_flag', 'LIKE', 'CorpSAG%')
            ->whereIn('type_id', $fuelTypes)
            ->where(function ($q) {
                // NPC station ID range
                $q->whereBetween('location_id', [60000000, 69999999])
                  // OR a known Upwell (corp's own OR foreign)
                  ->orWhereIn('location_id', function ($sub) {
                      $sub->from('universe_structures')->select('structure_id');
                  });
            })
            ->select(
                'item_id',
                'type_id',
                'quantity',
                DB::raw('location_id AS actual_location_id'),
                'location_flag'
            )
            ->get();

        // STEP 2: NESTED — CorpSAG fuel INSIDE an Office container. The
        // Office's location_id is the actual station/structure. Same
        // JOIN pattern that trackStructureReserves already uses for
        // owned-structure nested fuel.
        $nestedRows = DB::table('corporation_assets as fuel')
            ->join('corporation_assets as office', 'fuel.location_id', '=', 'office.item_id')
            ->join('invTypes as office_type', 'office.type_id', '=', 'office_type.typeID')
            ->where('fuel.corporation_id', $corporationId)
            ->where('office_type.typeName', 'Office')
            ->where('fuel.location_flag', 'LIKE', 'CorpSAG%')
            ->whereIn('fuel.type_id', $fuelTypes)
            ->select(
                'fuel.item_id',
                'fuel.type_id',
                'fuel.quantity',
                DB::raw('office.location_id AS actual_location_id'),
                'fuel.location_flag'
            )
            ->get();

        // Combine + dedupe by item_id (a row could appear in both queries
        // if both criteria are met, though unlikely in practice). Then
        // filter out actual_location_ids that are corp-owned (those are
        // already tracked by the per-structure loop).
        $rawRows = $directRows->merge($nestedRows)
            ->keyBy('item_id')
            ->reject(fn($r) => in_array((int) $r->actual_location_id, $ownedStructureIds, true))
            ->values();

        if ($rawRows->isEmpty()) {
            return 0;
        }

        // v2.0.0 dual-stack-aggregation fix (matches trackStructureReserves):
        // Aggregate split physical stacks into one logical reserve per
        // (actual_location_id, fuel_type_id, location_flag) tuple. See
        // the long comment in trackStructureReserves for the full
        // background — same fix, same reason, same one-row-per-tuple
        // invariant for downstream dedup.
        $allRows = $rawRows
            ->groupBy(fn($r) => $r->actual_location_id . '|' . $r->type_id . '|' . $r->location_flag)
            ->map(function ($stacks) {
                $first = $stacks->first();
                $obj = clone $first;
                $obj->quantity = (int) $stacks->sum('quantity');
                $obj->stack_count = $stacks->count();
                $obj->stack_item_ids = $stacks->pluck('item_id')->all();
                return $obj;
            })
            ->values();

        Log::debug("Corp {$corporationId}: Found {$allRows->count()} aggregated external tuples from {$rawRows->count()} physical stacks (direct: {$directRows->count()}, nested: {$nestedRows->count()})");

        $tracked = 0;

        foreach ($allRows as $row) {
            $actualLocationId = (int) $row->actual_location_id;

            // Dedup by (structure_id=actual_location_id, fuel_type, location_flag)
            $lastReserve = StructureFuelReserves::where('structure_id', $actualLocationId)
                ->where('fuel_type_id', $row->type_id)
                ->where('location_flag', $row->location_flag)
                ->orderBy('created_at', 'desc')
                ->first();

            $shouldTrack = false;
            $quantityChange = null;
            $isRefuelEvent = false;

            if (!$lastReserve) {
                $shouldTrack = true;
            } elseif ($lastReserve->reserve_quantity != $row->quantity) {
                $shouldTrack = true;
                $quantityChange = $row->quantity - $lastReserve->reserve_quantity;
                if ($quantityChange < 0) {
                    $isRefuelEvent = true;
                }
            } elseif ($lastReserve->created_at->diffInHours(now(), true) >= 24) {
                $shouldTrack = true;
                $quantityChange = 0;
            }

            if (!$shouldTrack) {
                continue;
            }

            $locationInfo = LocationResolver::resolve($actualLocationId, $corporationId);

            // v2.0.0 scope for the Fuel Reserves page: track CorpSAG fuel
            // staged in (a) corp-owned Upwells, (b) NPC stations, and
            // (c) FOREIGN Upwell structures where the corp rents an Office.
            //
            // Owned Upwells are handled by trackStructureReserves in the
            // per-structure loop, so this method only stores npc_station
            // and foreign_structure rows.
            //
            // Real-world example for foreign_structure: a corp rents an
            // Office in a friendly alliance-mate's Fortizar (the host
            // configures Office rentals as a service) and stages refuel
            // hauls in that Office's CorpSAG hangars. This is a legitimate
            // setup and was incorrectly excluded by an earlier scope-
            // narrowing — the data shows operators DO have CorpSAG fuel
            // in foreign Upwells. The previous reasoning ("impossible
            // under current mechanics") was wrong.
            //
            // Other resolver verdicts still skipped:
            //   - owned_structure: defensive guard, pre-filter should have
            //     caught it. Bail anyway so a future filter bug can't
            //     reintroduce the duplicate-data regression that migration
            //     000006 cleaned up.
            //   - unknown_location: resolution failure (location_id
            //     doesn't match any structure or station). Would pollute
            //     the UI with "Unknown Location - Unknown System" rows
            //     with no actionable info — operators can't refuel from
            //     an unknown location anyway.
            $loc = $locationInfo['location_type'];
            $allowedTypes = [
                \StructureManager\Models\StructureFuelReserves::LOCATION_NPC_STATION,
                \StructureManager\Models\StructureFuelReserves::LOCATION_FOREIGN_STRUCTURE,
            ];
            if (!in_array($loc, $allowedTypes, true)) {
                if ($loc === \StructureManager\Models\StructureFuelReserves::LOCATION_OWNED_STRUCTURE) {
                    Log::warning("Corp {$corporationId}: trackExternalReserves skipped {$actualLocationId} — resolver classified as owned_structure (defensive guard; pre-filter should have caught this)");
                } else {
                    Log::debug("Corp {$corporationId}: trackExternalReserves skipped {$actualLocationId} — not a trackable external location (resolver returned {$loc})");
                }
                continue;
            }

            $resourceType = $row->type_id == self::MAGMATIC_GAS_TYPE_ID ? 'magmatic gas' : 'fuel blocks';

            // Pass metadata as ARRAY — model cast = 'array'. See same
            // comment in trackStructureReserves for the double-encoding
            // bug background.
            StructureFuelReserves::create([
                'structure_id' => $actualLocationId,
                'corporation_id' => $corporationId,
                'fuel_type_id' => $row->type_id,
                'reserve_quantity' => $row->quantity,
                'location_flag' => $row->location_flag,
                'previous_quantity' => $lastReserve ? $lastReserve->reserve_quantity : null,
                'quantity_change' => $quantityChange,
                'is_refuel_event' => $isRefuelEvent,
                'location_type' => $locationInfo['location_type'],
                'location_name' => $locationInfo['location_name'],
                'location_system_id' => $locationInfo['location_system_id'],
                'location_system_name' => $locationInfo['location_system_name'],
                'metadata' => [
                    'item_id' => $row->item_id,
                    'location_id' => $actualLocationId,
                    'tracking_method' => 'external',
                    'resource_type' => $resourceType,
                    'stack_count' => (int) ($row->stack_count ?? 1),
                    'stack_item_ids' => $row->stack_item_ids ?? [$row->item_id],
                ],
            ]);

            $tracked++;
        }

        return $tracked;
    }

    /**
     * Corp-wide reconciliation: insert "fuel is gone" depletion rows for
     * every previously-tracked CorpSAG location that no longer has fuel.
     *
     * THE BUG THIS FIXES:
     *   trackStructureReserves + trackExternalReserves only INSERT rows
     *   for fuel they currently see. They have no concept of "this
     *   location used to have fuel but doesn't anymore." When you move
     *   fuel out of CorpSAG3 entirely, the next poll finds no Nitrogen
     *   there → returns no rows → the old "1 block" snapshot stays
     *   forever as the latest row, and the UI keeps rendering it.
     *
     *   Compound bug: if you moved the fuel to a DIFFERENT location,
     *   the new location gets its own positive row. Now BOTH locations
     *   render as having fuel — the same fuel double-counted.
     *
     * HOW THIS METHOD FIXES IT:
     *   1. Pull every (structure_id, fuel_type_id, location_flag) tuple
     *      that has a positive LATEST quantity for this corp.
     *   2. For each tuple, check corporation_assets for actual presence
     *      via the same direct + nested-Office traversal the rest of
     *      the code uses.
     *   3. If a tuple has zero matching corp_assets rows, the fuel was
     *      moved away. Insert a depletion row (reserve_quantity=0,
     *      quantity_change=-previous, is_refuel_event=true).
     *
     *   The depletion row joins the historical chain as a withdrawal
     *   event — it shows on the Fuel Withdrawals tab with the correct
     *   negative quantity_change, and getCurrentReserves now returns
     *   quantity=0 for that tuple so the UI stops rendering it.
     *
     * @return int Number of depletion rows inserted (0 = no moves happened)
     */
    /**
     * Number of consecutive polls a tuple must be ABSENT before we
     * record a depletion-reconciliation row. SeAT's hourly corporation-
     * assets update job is non-atomic (DELETE-then-INSERT pattern, no
     * transaction), so a single poll that observes "fuel missing" can
     * be a race with that refresh rather than a real move. Requiring
     * two consecutive observations of absence collapses the false-
     * positive rate from "every hour" to "essentially never".
     */
    private const DEPLETION_CONFIRM_COUNT = 2;

    /**
     * How long the absence counter survives in cache. Six hours is
     * generous — the next poll will rewrite it on either outcome
     * (detected → forget, still-absent → re-put).
     */
    private const DEPLETION_CACHE_HOURS = 6;

    private function reconcileDepletedReserves(int $corporationId): int
    {
        // Pull the latest row per (structure_id, fuel_type, location_flag)
        // for this corp where the recorded quantity is positive. These
        // are the "currently shown as having fuel" rows we need to verify.
        $tracked = DB::table('structure_fuel_reserves')
            ->whereIn('id', function ($sub) use ($corporationId) {
                $sub->selectRaw('MAX(id)')
                    ->from('structure_fuel_reserves')
                    ->where('corporation_id', $corporationId)
                    ->groupBy('structure_id', 'fuel_type_id', 'location_flag');
            })
            ->where('reserve_quantity', '>', 0)
            ->select(
                'id', 'structure_id', 'fuel_type_id', 'location_flag',
                'reserve_quantity', 'location_type', 'location_name',
                'location_system_id', 'location_system_name'
            )
            ->get();

        if ($tracked->isEmpty()) {
            return 0;
        }

        $depleted = 0;

        foreach ($tracked as $row) {
            $cacheKey = sprintf(
                'sm:depletion_absence:%d:%d:%d:%s',
                $corporationId,
                (int) $row->structure_id,
                (int) $row->fuel_type_id,
                $row->location_flag
            );

            // Is the fuel still at this location? Check both placement
            // patterns: direct (location_id == structure_id) and nested
            // (fuel inside an Office whose location_id == structure_id).
            $directExists = DB::table('corporation_assets')
                ->where('corporation_id', $corporationId)
                ->where('location_id', $row->structure_id)
                ->where('location_flag', $row->location_flag)
                ->where('type_id', $row->fuel_type_id)
                ->exists();

            if ($directExists) {
                Cache::forget($cacheKey); // Reset absence streak — present this poll
                continue;
            }

            $nestedExists = DB::table('corporation_assets as fuel')
                ->join('corporation_assets as office', 'fuel.location_id', '=', 'office.item_id')
                ->join('invTypes as office_type', 'office.type_id', '=', 'office_type.typeID')
                ->where('fuel.corporation_id', $corporationId)
                ->where('office.location_id', $row->structure_id)
                ->where('office_type.typeName', 'Office')
                ->where('fuel.location_flag', $row->location_flag)
                ->where('fuel.type_id', $row->fuel_type_id)
                ->exists();

            if ($nestedExists) {
                Cache::forget($cacheKey); // Reset absence streak — present via Office
                continue;
            }

            // ABSENT this poll. Increment the absence streak counter.
            // Only commit a depletion row once we've seen the absence
            // self::DEPLETION_CONFIRM_COUNT times in a row. This prevents
            // phantom −57k rows that would otherwise fire every hour when
            // TrackFuelConsumption races SeAT's asset-table refresh.
            $absenceCount = ((int) Cache::get($cacheKey, 0)) + 1;

            if ($absenceCount < self::DEPLETION_CONFIRM_COUNT) {
                Cache::put($cacheKey, $absenceCount, now()->addHours(self::DEPLETION_CACHE_HOURS));
                Log::debug(sprintf(
                    'TrackFuelConsumption: depletion suspected for structure %d / fuel %d / flag %s (absence_count=%d / need %d) — deferring',
                    $row->structure_id,
                    $row->fuel_type_id,
                    $row->location_flag,
                    $absenceCount,
                    self::DEPLETION_CONFIRM_COUNT
                ));
                continue;
            }

            // Confirmed depletion. Insert the depletion row so the latest
            // row for this tuple now reads quantity=0, then clear the
            // counter so the next time fuel returns + departs we start
            // the streak fresh.
            StructureFuelReserves::create([
                'structure_id' => $row->structure_id,
                'corporation_id' => $corporationId,
                'fuel_type_id' => $row->fuel_type_id,
                'reserve_quantity' => 0,
                'location_flag' => $row->location_flag,
                'previous_quantity' => $row->reserve_quantity,
                'quantity_change' => -$row->reserve_quantity,
                'is_refuel_event' => true,
                'location_type' => $row->location_type,
                'location_name' => $row->location_name,
                'location_system_id' => $row->location_system_id,
                'location_system_name' => $row->location_system_name,
                'metadata' => [
                    'tracking_method' => 'depletion_reconciliation',
                    'absence_polls_confirmed' => $absenceCount,
                    'note' => 'Previously-tracked CorpSAG no longer holds this fuel — moved out',
                ],
            ]);

            Cache::forget($cacheKey);
            $depleted++;
            Log::info(sprintf(
                'TrackFuelConsumption: depletion row inserted for structure %d / fuel %d / flag %s (was %d blocks, confirmed over %d polls)',
                $row->structure_id,
                $row->fuel_type_id,
                $row->location_flag,
                $row->reserve_quantity,
                $absenceCount
            ));
        }

        return $depleted;
    }

    /**
     * Get fuel block quantity from structure's fuel bay ONLY
     * NO RESERVES - Those are tracked separately
     */
    private function getFuelBayQuantity($structureId)
    {
        $result = [
            'quantity' => 0,
            'available' => false,
            'fuel_type_id' => null,
        ];
        
        // Sum ALL racial fuel block types in the StructureFuel bay.
        // Upwell structures consume any racial block from a single pool, so a
        // mixed-fuel bay is intentional EVE behavior and the totals must be
        // summed (not just the first row taken). GitHub issue #17.
        $totalQty = (int) DB::table('corporation_assets')
            ->where('location_id', $structureId)
            ->where('location_flag', 'StructureFuel')
            ->whereIn('type_id', self::FUEL_BLOCK_TYPES)
            ->sum('quantity');

        if ($totalQty > 0) {
            $result['quantity'] = $totalQty;
            $result['available'] = true;
            // fuel_type_id intentionally left null — type is irrelevant for
            // mixed-fuel Upwell tracking; consumption rate is per-block, not
            // per-racial-type.

            Log::debug("Structure {$structureId}: Found {$totalQty} total blocks in fuel bay (mixed-fuel sum)");
        } else {
            Log::debug("Structure {$structureId}: No fuel bay data found, will use days_remaining method");
        }
        
        return $result;
    }
}
