<?php

namespace StructureManager\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use StructureManager\Models\StructureFuelHistory;
use StructureManager\Models\StructureFuelReserves;
use StructureManager\Helpers\FuelCalculator;
use Carbon\Carbon;

class TrackFuelConsumption implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Fuel block type IDs from EVE
     */
    const FUEL_BLOCK_TYPES = [4051, 4246, 4247, 4312];
    
    /**
     * Magmatic Gas type ID
     */
    const MAGMATIC_GAS_TYPE_ID = 81143;
    
    /**
     * Metenox Moon Drill type ID
     */
    const METENOX_TYPE_ID = 81826;

    /**
     * Execute the job.
     */
    public function handle()
    {
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
        
        foreach ($structures as $structure) {
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
            
            // FIXED: Track reserves for ALL structures (including Metenox)
            // Metenox needs tracking for BOTH fuel blocks AND magmatic gas reserves
            if ($this->trackStructureReserves($structure->structure_id, $structure->corporation_id, $isMetenox)) {
                $reservesTracked++;
            }
        }
        
        Log::info("TrackFuelConsumption: Completed. Tracked: $tracked (Fuel Bay: $fuelBaySuccess, Fallback: $fallbackMethod, Metenox: $metenoxTracked), Reserves: $reservesTracked, Skipped: $skipped");
        
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
        } elseif ($lastRecord->created_at->diffInHours(now()) >= 1) {
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
        $currentDaysRemaining = Carbon::parse($structure->fuel_expires)->diffInDays(now());
        
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
        } elseif ($lastRecord->created_at->diffInHours(now()) >= 1) {
            $shouldCreateRecord = true;
        }
        
        // Calculate consumption if we have a previous record
        if ($lastRecord && $shouldCreateRecord) {
            $realHoursPassed = $lastRecord->created_at->diffInHours(now());
            
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
            ]);
            
            Log::info("Structure {$structure->structure_id}: Created fuel bay snapshot #{$record->id} " .
                     "(days: {$currentDaysRemaining}, bay: " . ($fuelBayData['quantity'] ?? 'N/A') . 
                     ", method: {$trackingMethod})");
            
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
        
        // FIXED: Add magmatic gas tracking for Metenox
        if ($isMetenox) {
            $fuelTypes[] = self::MAGMATIC_GAS_TYPE_ID;
        }
        
        // METHOD 1: Direct - Fuel in structure's CorpSAG
        $directReserves = DB::table('corporation_assets')
            ->where('location_id', $structureId)
            ->where('location_flag', 'LIKE', 'CorpSAG%')
            ->whereIn('type_id', $fuelTypes)  // Now includes gas for Metenox
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
        $reserves = $directReserves->merge($nestedReserves);
        
        if ($reserves->isEmpty()) {
            return false;
        }
        
        $gasCount = $reserves->where('type_id', self::MAGMATIC_GAS_TYPE_ID)->count();
        $fuelCount = $reserves->count() - $gasCount;
        
        if ($isMetenox && $gasCount > 0) {
            Log::debug("Metenox {$structureId}: Found {$reserves->count()} reserve stacks ({$fuelCount} fuel blocks, {$gasCount} magmatic gas) (Direct: {$directReserves->count()}, Nested: {$nestedReserves->count()})");
        } else {
            Log::debug("Structure {$structureId}: Found {$reserves->count()} reserve stacks (Direct: {$directReserves->count()}, Nested: {$nestedReserves->count()})");
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
                Log::info("Structure {$structureId}: First time tracking {$reserve->quantity} {$resourceType} in {$reserve->location_flag}");
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
            } elseif ($lastReserve->created_at->diffInHours(now()) >= 24) {
                // Create daily snapshot even if no change
                $shouldTrack = true;
                $quantityChange = 0;
            }
            
            if ($shouldTrack) {
                StructureFuelReserves::create([
                    'structure_id' => $structureId,
                    'corporation_id' => $corporationId,
                    'fuel_type_id' => $reserve->type_id,
                    'reserve_quantity' => $reserve->quantity,
                    'location_flag' => $reserve->location_flag,
                    'previous_quantity' => $lastReserve ? $lastReserve->reserve_quantity : null,
                    'quantity_change' => $quantityChange,
                    'is_refuel_event' => $isRefuelEvent,
                    'metadata' => json_encode([
                        'item_id' => $reserve->item_id,
                        'location_id' => $reserve->location_id,
                        'tracking_method' => isset($reserve->location_id) && $reserve->location_id != $structureId ? 'nested_office' : 'direct',
                        'is_metenox' => $isMetenox,
                        'resource_type' => $resourceType,
                    ]),
                ]);
                
                $tracked = true;
            }
        }
        
        return $tracked;
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
        
        // Get fuel from StructureFuel bay ONLY (what's being consumed)
        $fuelBayAsset = DB::table('corporation_assets')
            ->where('location_id', $structureId)
            ->where('location_flag', 'StructureFuel')
            ->whereIn('type_id', self::FUEL_BLOCK_TYPES)
            ->first();
        
        if ($fuelBayAsset) {
            $result['quantity'] = $fuelBayAsset->quantity;
            $result['available'] = true;
            $result['fuel_type_id'] = $fuelBayAsset->type_id;
            
            Log::debug("Structure {$structureId}: Found {$fuelBayAsset->quantity} blocks (type {$fuelBayAsset->type_id}) in fuel bay");
        } else {
            Log::debug("Structure {$structureId}: No fuel bay data found, will use days_remaining method");
        }
        
        return $result;
    }
}
