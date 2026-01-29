<?php

namespace StructureManager\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use StructureManager\Models\StarbaseFuelHistory;
use StructureManager\Models\StarbaseFuelConsumption;
use StructureManager\Models\StarbaseFuelReserves;
use StructureManager\Helpers\PosFuelCalculator;
use Carbon\Carbon;

/**
 * Analyze POS fuel consumption daily
 * 
 * Aggregates hourly tracking data into daily summaries
 * Detects consumption anomalies and refuel events
 */
class AnalyzePosConsumption implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Execute the job.
     */
    public function handle()
    {
        $yesterday = Carbon::yesterday()->format('Y-m-d');
        
        // Get all unique POSes that have history records
        $poses = StarbaseFuelHistory::select('starbase_id', 'corporation_id')
            ->distinct()
            ->get();
        
        Log::info("AnalyzePosConsumption: Analyzing consumption for {$poses->count()} POSes for date: {$yesterday}");
        
        $analyzed = 0;
        $skipped = 0;
        
        foreach ($poses as $pos) {
            // Check if already analyzed
            $exists = StarbaseFuelConsumption::where('starbase_id', $pos->starbase_id)
                ->where('date', $yesterday)
                ->exists();
            
            if ($exists) {
                $skipped++;
                continue;
            }
            
            if ($this->analyzePosConsumption($pos->starbase_id, $pos->corporation_id, $yesterday)) {
                $analyzed++;
            }
        }
        
        Log::info("AnalyzePosConsumption: Completed. Analyzed: {$analyzed}, Skipped: {$skipped}");
    }
    
    /**
     * Analyze consumption for a single POS for a specific date
     * 
     * @param int $starbaseId
     * @param int $corporationId
     * @param string $date Date in Y-m-d format
     * @return bool Success
     */
    private function analyzePosConsumption($starbaseId, $corporationId, $date)
    {
        try {
            $startOfDay = Carbon::parse($date)->startOfDay();
            $endOfDay = Carbon::parse($date)->endOfDay();
            
            // Get history records for this day
            $records = StarbaseFuelHistory::where('starbase_id', $starbaseId)
                ->whereBetween('created_at', [$startOfDay, $endOfDay])
                ->orderBy('created_at', 'asc')
                ->get();
            
            if ($records->count() < 2) {
                // Need at least 2 records to calculate consumption
                return false;
            }
            
            $firstRecord = $records->first();
            $lastRecord = $records->last();
            
            // Calculate fuel consumption
            $fuelConsumed = $firstRecord->fuel_blocks_quantity - $lastRecord->fuel_blocks_quantity;
            $hoursPassed = $lastRecord->created_at->diffInHours($firstRecord->created_at);
            
            $fuelDailyConsumption = $hoursPassed > 0 ? ($fuelConsumed / $hoursPassed) * 24 : 0;
            $fuelHourlyRate = $hoursPassed > 0 ? $fuelConsumed / $hoursPassed : 0;
            
            // Detect refuels (fuel increased)
            $fuelRefuelAmount = null;
            foreach ($records as $i => $record) {
                if ($i > 0) {
                    $prev = $records[$i - 1];
                    $increase = $record->fuel_blocks_quantity - $prev->fuel_blocks_quantity;
                    if ($increase > 0) {
                        $fuelRefuelAmount = ($fuelRefuelAmount ?? 0) + $increase;
                    }
                }
            }
            
            // Calculate strontium consumption (if any)
            $strontiumConsumed = $firstRecord->strontium_quantity - $lastRecord->strontium_quantity;
            $wasReinforced = $strontiumConsumed > 0;
            
            // Detect strontium refuels
            $strontiumRefuelAmount = null;
            foreach ($records as $i => $record) {
                if ($i > 0) {
                    $prev = $records[$i - 1];
                    $increase = $record->strontium_quantity - $prev->strontium_quantity;
                    if ($increase > 0) {
                        $strontiumRefuelAmount = ($strontiumRefuelAmount ?? 0) + $increase;
                    }
                }
            }
            
            // Calculate charter consumption (if required)
            $charterConsumed = $firstRecord->charter_quantity - $lastRecord->charter_quantity;
            $requiredCharters = $firstRecord->requires_charters;
            
            // Detect charter refuels
            $charterRefuelAmount = null;
            if ($requiredCharters) {
                foreach ($records as $i => $record) {
                    if ($i > 0) {
                        $prev = $records[$i - 1];
                        $increase = $record->charter_quantity - $prev->charter_quantity;
                        if ($increase > 0) {
                            $charterRefuelAmount = ($charterRefuelAmount ?? 0) + $increase;
                        }
                    }
                }
            }
            
            // Get expected consumption rate
            $towerTypeId = $firstRecord->tower_type_id;
            $systemSecurity = $firstRecord->system_security;
            $expectedRates = PosFuelCalculator::getFuelConsumptionRate($towerTypeId, $systemSecurity);
            $expectedDailyConsumption = $expectedRates['fuel_per_day'];
            
            // Detect anomalies
            $hasAnomaly = false;
            $anomalyDetails = null;
            
            // Check if POS was offline (zero consumption)
            if ($fuelConsumed == 0 && $hoursPassed >= 12) {
                $hasAnomaly = true;
                $anomalyDetails = [
                    'type' => 'offline',
                    'description' => 'No fuel consumption detected - POS may be offline',
                    'expected_consumption' => $expectedDailyConsumption,
                    'actual_consumption' => 0,
                ];
            }
            
            // Check for consumption spike (>20% deviation)
            if ($fuelDailyConsumption > 0) {
                $deviation = abs($fuelDailyConsumption - $expectedDailyConsumption);
                $deviationPercent = ($deviation / $expectedDailyConsumption) * 100;
                
                if ($deviationPercent > 20) {
                    $hasAnomaly = true;
                    $anomalyDetails = [
                        'type' => $fuelDailyConsumption > $expectedDailyConsumption ? 'consumption_spike' : 'consumption_drop',
                        'description' => "Consumption deviates by {$deviationPercent}% from expected",
                        'expected_consumption' => $expectedDailyConsumption,
                        'actual_consumption' => $fuelDailyConsumption,
                        'deviation_percent' => round($deviationPercent, 2),
                    ];
                }
            }
            
            // Check for reinforcement
            if ($wasReinforced) {
                if ($hasAnomaly) {
                    // Append to existing anomaly
                    if (is_array($anomalyDetails)) {
                        $anomalyDetails['reinforcement_detected'] = true;
                        $anomalyDetails['strontium_consumed'] = $strontiumConsumed;
                    }
                } else {
                    $hasAnomaly = true;
                    $anomalyDetails = [
                        'type' => 'reinforced',
                        'description' => 'POS was reinforced (strontium consumed)',
                        'strontium_consumed' => $strontiumConsumed,
                    ];
                }
            }
            
            // Create consumption record
            StarbaseFuelConsumption::create([
                'starbase_id' => $starbaseId,
                'corporation_id' => $corporationId,
                'date' => $date,
                
                // Fuel
                'fuel_daily_consumption' => $fuelDailyConsumption,
                'fuel_hourly_rate' => $fuelHourlyRate,
                'fuel_refuel_amount' => $fuelRefuelAmount,
                
                // Strontium
                'strontium_consumption' => $strontiumConsumed > 0 ? $strontiumConsumed : null,
                'was_reinforced' => $wasReinforced,
                'strontium_refuel_amount' => $strontiumRefuelAmount,
                
                // Charters
                'charter_consumption' => $requiredCharters ? $charterConsumed : null,
                'charter_refuel_amount' => $charterRefuelAmount,
                'required_charters' => $requiredCharters,
                
                // Anomalies
                'has_anomaly' => $hasAnomaly,
                'anomaly_details' => $anomalyDetails,
                
                'metadata' => [
                    'records_analyzed' => $records->count(),
                    'hours_tracked' => $hoursPassed,
                    'expected_daily_consumption' => $expectedDailyConsumption,
                    'tower_type_id' => $towerTypeId,
                ],
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            Log::error("AnalyzePosConsumption: Error analyzing POS {$starbaseId}: " . $e->getMessage());
            return false;
        }
    }
}
