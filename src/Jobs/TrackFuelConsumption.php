<?php

namespace StructureManager\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use StructureManager\Models\StructureFuelHistory;
use Carbon\Carbon;

class TrackFuelConsumption implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Execute the job.
     */
    public function handle()
    {
        $structures = DB::table('corporation_structures')
            ->whereNotNull('fuel_expires')
            ->get();
        
        foreach ($structures as $structure) {
            $this->trackStructureFuel($structure);
        }
        
        // Clean old history (keep 6 months)
        StructureFuelHistory::where('created_at', '<', Carbon::now()->subMonths(6))
            ->delete();
    }
    
    /**
     * Track fuel for a single structure
     */
    private function trackStructureFuel($structure)
    {
        $lastRecord = StructureFuelHistory::where('structure_id', $structure->structure_id)
            ->orderBy('created_at', 'desc')
            ->first();
        
        $shouldCreateRecord = false;
        $fuelBlocksUsed = null;
        $dailyConsumption = null;
        
        if (!$lastRecord) {
            // First record for this structure
            $shouldCreateRecord = true;
        } elseif ($lastRecord->fuel_expires != $structure->fuel_expires) {
            // Fuel was added or structure was refueled
            $shouldCreateRecord = true;
            
            // Calculate fuel consumption
            if ($lastRecord->fuel_expires && $structure->fuel_expires) {
                $oldExpiry = Carbon::parse($lastRecord->fuel_expires);
                $newExpiry = Carbon::parse($structure->fuel_expires);
                $hoursSinceLastRecord = $lastRecord->created_at->diffInHours(now());
                
                if ($newExpiry->gt($oldExpiry)) {
                    // Fuel was added
                    $daysAdded = $newExpiry->diffInDays($oldExpiry);
                    $fuelBlocksUsed = $daysAdded * -40; // Negative indicates fuel added
                } else {
                    // Normal consumption
                    $daysConsumed = $oldExpiry->diffInDays($newExpiry);
                    if ($hoursSinceLastRecord > 0) {
                        $dailyConsumption = ($daysConsumed / $hoursSinceLastRecord) * 24;
                    }
                }
            }
        } elseif ($lastRecord->created_at->diffInHours(now()) >= 24) {
            // Create a daily snapshot even if fuel_expires hasn't changed
            $shouldCreateRecord = true;
            
            // Calculate daily consumption based on time passed
            $hoursPassed = $lastRecord->created_at->diffInHours(now());
            $daysPassed = $hoursPassed / 24;
            
            if ($lastRecord->days_remaining !== null) {
                $currentDaysRemaining = Carbon::parse($structure->fuel_expires)->diffInDays(now());
                $daysConsumed = $lastRecord->days_remaining - $currentDaysRemaining;
                
                if ($daysPassed > 0) {
                    $dailyConsumption = $daysConsumed / $daysPassed;
                    $fuelBlocksUsed = round($dailyConsumption * 40); // Assuming 40 blocks per day
                }
            }
        }
        
        if ($shouldCreateRecord) {
            $daysRemaining = $structure->fuel_expires ? 
                Carbon::parse($structure->fuel_expires)->diffInDays(now()) : null;
            
            StructureFuelHistory::create([
                'structure_id' => $structure->structure_id,
                'corporation_id' => $structure->corporation_id,
                'fuel_expires' => $structure->fuel_expires,
                'days_remaining' => $daysRemaining,
                'fuel_blocks_used' => $fuelBlocksUsed,
                'daily_consumption' => $dailyConsumption,
            ]);
        }
    }
}
