<?php

namespace StructureManager\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use StructureManager\Models\StarbaseFuelHistory;
use StructureManager\Models\StarbaseFuelReserves;
use StructureManager\Helpers\PosFuelCalculator;
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
        $reservesTracked = 0;
        
        foreach ($poses as $pos) {
            // Track POS fuel levels
            $result = $this->trackPosFuel($pos);
            if ($result['tracked']) {
                $tracked++;
            } else {
                $skipped++;
            }
            
            // Track reserves for this POS (fuel in nearby structures)
            if ($this->trackPosReserves($pos->starbase_id, $pos->corporation_id, $pos->system_id)) {
                $reservesTracked++;
            }
        }
        
        Log::info("TrackPosesFuel: Completed. Tracked: $tracked, Reserves: $reservesTracked, Skipped: $skipped");
        
        // Clean old history (keep 6 months)
        $deleted = StarbaseFuelHistory::where('created_at', '<', Carbon::now()->subMonths(6))->delete();
        if ($deleted > 0) {
            Log::info("TrackPosesFuel: Cleaned $deleted old history records");
        }
        
        // Clean old reserve records (keep 3 months)
        $deletedReserves = StarbaseFuelReserves::where('created_at', '<', Carbon::now()->subMonths(3))->delete();
        if ($deletedReserves > 0) {
            Log::info("TrackPosesFuel: Cleaned $deletedReserves old reserve records");
        }
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
            // Get current fuel blocks
            $fuelBlocks = DB::table('corporation_starbase_fuels')
                ->where('starbase_id', $pos->starbase_id)
                ->where('corporation_id', $pos->corporation_id)
                ->whereIn('type_id', array_keys(PosFuelCalculator::FUEL_BLOCKS))
                ->sum('quantity');
            
            // Get current strontium
            $strontium = DB::table('corporation_starbase_fuels')
                ->where('starbase_id', $pos->starbase_id)
                ->where('corporation_id', $pos->corporation_id)
                ->where('type_id', PosFuelCalculator::STRONTIUM)
                ->value('quantity') ?? 0;
            
            // Get current charters (if high-sec)
            $charters = DB::table('corporation_starbase_fuels')
                ->where('starbase_id', $pos->starbase_id)
                ->where('corporation_id', $pos->corporation_id)
                ->whereIn('type_id', array_keys(PosFuelCalculator::CHARTER_TYPES))
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
            
            // Calculate fuel consumption since last check
            $fuelBlocksUsed = null;
            $fuelHourlyConsumption = null;
            
            $lastHistory = StarbaseFuelHistory::where('starbase_id', $pos->starbase_id)
                ->orderBy('created_at', 'desc')
                ->first();
            
            if ($lastHistory && $lastHistory->fuel_blocks_quantity) {
                $fuelBlocksUsed = $lastHistory->fuel_blocks_quantity - $fuelBlocks;
                $hoursSinceLastCheck = Carbon::now()->diffInHours($lastHistory->created_at);
                
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
            
            // Convert state string to integer
            // SeAT stores state as string in corporation_starbases, but we need integer
            $stateInteger = $this->convertStateToInteger($pos->state);
            
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
     * Track fuel reserves for a POS (stored in nearby structures/stations)
     * 
     * Scans corporation_assets for fuel blocks, strontium, and charters
     * in structures/stations in the same system or nearby
     * 
     * @param int $starbaseId
     * @param int $corporationId
     * @param int $systemId
     * @return bool Success
     */
    private function trackPosReserves($starbaseId, $corporationId, $systemId)
    {
        try {
            // Get all corporate assets that are fuel-related
            // Look for: fuel blocks, strontium, charters in hangars
            
            // Fuel block type IDs
            $fuelTypeIds = array_keys(PosFuelCalculator::FUEL_BLOCKS);
            // Strontium type ID
            $strontiumTypeId = PosFuelCalculator::STRONTIUM;
            // Charter type IDs
            $charterTypeIds = array_keys(PosFuelCalculator::CHARTER_TYPES);
            
            // Get all relevant assets in hangars (location_flag like 'CorpSAG%')
            $reserves = DB::table('corporation_assets')
                ->where('corporation_id', $corporationId)
                ->where(function($query) use ($fuelTypeIds, $strontiumTypeId, $charterTypeIds) {
                    $query->whereIn('type_id', $fuelTypeIds)
                          ->orWhere('type_id', $strontiumTypeId)
                          ->orWhereIn('type_id', $charterTypeIds);
                })
                ->where('location_flag', 'like', 'CorpSAG%') // Corporate hangars
                ->whereNotNull('quantity')
                ->where('quantity', '>', 0)
                ->get();
            
            $tracked = 0;
            
            foreach ($reserves as $reserve) {
                // Determine resource category
                if (in_array($reserve->type_id, $fuelTypeIds)) {
                    $category = 'fuel';
                } elseif ($reserve->type_id == $strontiumTypeId) {
                    $category = 'strontium';
                } elseif (in_array($reserve->type_id, $charterTypeIds)) {
                    $category = 'charter';
                } else {
                    continue;
                }
                
                // Get previous quantity to detect changes
                $lastReserve = StarbaseFuelReserves::where('starbase_id', $starbaseId)
                    ->where('location_id', $reserve->location_id)
                    ->where('resource_type_id', $reserve->type_id)
                    ->orderBy('created_at', 'desc')
                    ->first();
                
                $previousQuantity = $lastReserve ? $lastReserve->reserve_quantity : null;
                $quantityChange = $previousQuantity !== null ? ($reserve->quantity - $previousQuantity) : null;
                
                // Detect refuel event (quantity decreased = moved to POS)
                $isRefuelEvent = $quantityChange !== null && $quantityChange < 0;
                
                // Create reserve record
                StarbaseFuelReserves::create([
                    'starbase_id' => $starbaseId,
                    'corporation_id' => $corporationId,
                    'location_id' => $reserve->location_id,
                    'resource_type_id' => $reserve->type_id,
                    'resource_category' => $category,
                    'reserve_quantity' => $reserve->quantity,
                    'location_flag' => $reserve->location_flag,
                    'previous_quantity' => $previousQuantity,
                    'quantity_change' => $quantityChange,
                    'is_refuel_event' => $isRefuelEvent,
                    'refuel_detected_at' => $isRefuelEvent ? Carbon::now() : null,
                    'metadata' => [
                        'system_id' => $systemId,
                    ],
                ]);
                
                $tracked++;
            }
            
            return $tracked > 0;
            
        } catch (\Exception $e) {
            Log::error("TrackPosesFuel: Error tracking reserves for POS {$starbaseId}: " . $e->getMessage());
            return false;
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
