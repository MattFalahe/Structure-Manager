<?php

namespace StructureManager\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use StructureManager\Models\StructureFuelHistory;

class FuelConsumptionTracker
{
    /**
     * Analyze fuel consumption patterns for a structure
     */
    public static function analyzeFuelConsumption($structureId, $days = 30)
    {
        // Get fuel history for the specified period
        $history = StructureFuelHistory::where('structure_id', $structureId)
            ->where('created_at', '>=', Carbon::now()->subDays($days))
            ->orderBy('created_at', 'asc')
            ->get();
        
        if ($history->count() < 2) {
            return [
                'status' => 'insufficient_data',
                'message' => 'Need at least 2 data points to calculate consumption'
            ];
        }
        
        $consumptionPeriods = [];
        $refuelEvents = [];
        $anomalies = [];
        
        // Analyze each consecutive pair of records
        for ($i = 1; $i < $history->count(); $i++) {
            $previous = $history[$i - 1];
            $current = $history[$i];
            
            // Skip if either record has null fuel_expires
            if (!$previous->fuel_expires || !$current->fuel_expires) {
                continue;
            }
            
            $prevExpires = Carbon::parse($previous->fuel_expires);
            $currExpires = Carbon::parse($current->fuel_expires);
            $timeDiff = $previous->created_at->diffInHours($current->created_at);
            
            if ($timeDiff == 0) continue;
            
            // Calculate the change in fuel expiry time
            $fuelExpiryDiff = $currExpires->diffInHours($prevExpires, false);
            
            if ($fuelExpiryDiff > 0) {
                // Fuel was added (expiry extended)
                $refuelEvents[] = [
                    'timestamp' => $current->created_at,
                    'hours_added' => $fuelExpiryDiff,
                    'blocks_added' => round($fuelExpiryDiff / 24 * ($previous->daily_consumption ?? 0)),
                ];
            } else {
                // Normal consumption period
                $expectedConsumption = $timeDiff; // Hours that should have been consumed
                $actualConsumption = abs($fuelExpiryDiff); // Hours actually consumed from expiry
                
                // Check if consumption rate changed (could indicate service changes)
                $consumptionRate = $actualConsumption / $timeDiff;
                
                // If consumption rate is consistent (close to 1.0), it's normal
                if ($consumptionRate >= 0.95 && $consumptionRate <= 1.05) {
                    $consumptionPeriods[] = [
                        'start' => $previous->created_at,
                        'end' => $current->created_at,
                        'hours' => $timeDiff,
                        'fuel_consumed_hours' => $actualConsumption,
                        'rate' => $consumptionRate,
                        'blocks_per_day' => $previous->daily_consumption ?? 0,
                        'type' => 'normal'
                    ];
                } else {
                    // Anomaly detected (service activation/deactivation?)
                    $anomalies[] = [
                        'timestamp' => $current->created_at,
                        'expected_rate' => 1.0,
                        'actual_rate' => $consumptionRate,
                        'difference' => ($consumptionRate - 1.0) * 100 . '%',
                        'possible_cause' => $consumptionRate > 1.05 ? 'service_activated' : 'service_deactivated'
                    ];
                }
            }
        }
        
        // Calculate average consumption
        $validPeriods = array_filter($consumptionPeriods, function($p) {
            return $p['type'] == 'normal';
        });
        
        $averageDailyBlocks = 0;
        $averageHourlyBlocks = 0;
        
        if (count($validPeriods) > 0) {
            // Calculate weighted average based on period duration
            $totalHours = array_sum(array_column($validPeriods, 'hours'));
            $totalBlocks = 0;
            
            foreach ($validPeriods as $period) {
                $totalBlocks += $period['blocks_per_day'] * ($period['hours'] / 24);
            }
            
            $averageDailyBlocks = $totalHours > 0 ? round($totalBlocks / ($totalHours / 24)) : 0;
            $averageHourlyBlocks = round($averageDailyBlocks / 24, 2);
        }
        
        // Calculate actual consumption from database
        $actualConsumption = self::calculateActualConsumption($structureId, $days);
        
        return [
            'status' => 'success',
            'structure_id' => $structureId,
            'analysis_period' => [
                'days' => $days,
                'start' => Carbon::now()->subDays($days),
                'end' => Carbon::now(),
                'data_points' => $history->count(),
            ],
            'consumption' => [
                'average_daily' => $averageDailyBlocks,
                'average_hourly' => $averageHourlyBlocks,
                'average_weekly' => $averageDailyBlocks * 7,
                'average_monthly' => $averageDailyBlocks * 30,
                'actual_total' => $actualConsumption['total_blocks'],
                'actual_daily_avg' => $actualConsumption['daily_average'],
            ],
            'refuel_events' => $refuelEvents,
            'anomalies' => $anomalies,
            'consumption_periods' => $consumptionPeriods,
            'recommendations' => self::generateRecommendations($averageDailyBlocks, $refuelEvents, $anomalies),
        ];
    }
    
    /**
     * Calculate actual consumption based on fuel expiry changes
     */
    private static function calculateActualConsumption($structureId, $days)
    {
        $startDate = Carbon::now()->subDays($days);
        
        // Get first and last records in the period
        $firstRecord = StructureFuelHistory::where('structure_id', $structureId)
            ->where('created_at', '>=', $startDate)
            ->orderBy('created_at', 'asc')
            ->first();
            
        $lastRecord = StructureFuelHistory::where('structure_id', $structureId)
            ->where('created_at', '>=', $startDate)
            ->orderBy('created_at', 'desc')
            ->first();
        
        if (!$firstRecord || !$lastRecord || !$firstRecord->fuel_expires || !$lastRecord->fuel_expires) {
            return [
                'total_blocks' => 0,
                'daily_average' => 0,
            ];
        }
        
        // Calculate total fuel consumed (accounting for refueling)
        $refuelTotal = StructureFuelHistory::where('structure_id', $structureId)
            ->where('created_at', '>=', $startDate)
            ->where('fuel_blocks_used', '<', 0) // Negative means fuel added
            ->sum('fuel_blocks_used');
        
        $firstExpiry = Carbon::parse($firstRecord->fuel_expires);
        $lastExpiry = Carbon::parse($lastRecord->fuel_expires);
        $timePeriod = $firstRecord->created_at->diffInDays($lastRecord->created_at) ?: 1;
        
        // Total consumption = (initial fuel - final fuel) + refuels
        $hoursConsumed = $firstExpiry->diffInHours($lastExpiry) - abs($refuelTotal);
        $blocksConsumed = ($hoursConsumed / 24) * ($lastRecord->daily_consumption ?? 0);
        
        return [
            'total_blocks' => round(abs($blocksConsumed)),
            'daily_average' => round(abs($blocksConsumed) / $timePeriod),
        ];
    }
    
    /**
     * Generate recommendations based on consumption patterns
     */
    private static function generateRecommendations($avgDaily, $refuelEvents, $anomalies)
    {
        $recommendations = [];
        
        // Refueling frequency
        if (count($refuelEvents) > 0) {
            $avgDaysBetweenRefuel = 30 / count($refuelEvents);
            if ($avgDaysBetweenRefuel < 7) {
                $recommendations[] = [
                    'type' => 'efficiency',
                    'message' => 'Frequent refueling detected. Consider larger fuel hauls to reduce logistics overhead.',
                    'severity' => 'medium'
                ];
            }
        }
        
        // Service optimization
        if (count($anomalies) > 2) {
            $recommendations[] = [
                'type' => 'stability',
                'message' => 'Multiple consumption rate changes detected. Review service activation patterns.',
                'severity' => 'low'
            ];
        }
        
        // Fuel planning
        if ($avgDaily > 0) {
            $monthlyRequired = $avgDaily * 30;
            $recommendations[] = [
                'type' => 'planning',
                'message' => sprintf('Plan for %s fuel blocks per month based on current average consumption.', number_format($monthlyRequired)),
                'severity' => 'info'
            ];
        }
        
        return $recommendations;
    }
    
    /**
     * Track spike in fuel usage (service activation)
     */
    public static function detectFuelSpikes($structureId, $threshold = 1.2)
    {
        $recent = StructureFuelHistory::where('structure_id', $structureId)
            ->where('created_at', '>=', Carbon::now()->subDays(7))
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();
        
        $spikes = [];
        
        for ($i = 1; $i < $recent->count(); $i++) {
            if ($recent[$i]->daily_consumption && $recent[$i-1]->daily_consumption) {
                $ratio = $recent[$i]->daily_consumption / $recent[$i-1]->daily_consumption;
                
                if ($ratio > $threshold) {
                    $spikes[] = [
                        'timestamp' => $recent[$i]->created_at,
                        'previous_consumption' => $recent[$i-1]->daily_consumption,
                        'new_consumption' => $recent[$i]->daily_consumption,
                        'increase_percent' => round(($ratio - 1) * 100, 1),
                        'likely_cause' => 'Service activation or structure came out of low power'
                    ];
                } elseif ($ratio < (1 / $threshold)) {
                    $spikes[] = [
                        'timestamp' => $recent[$i]->created_at,
                        'previous_consumption' => $recent[$i-1]->daily_consumption,
                        'new_consumption' => $recent[$i]->daily_consumption,
                        'decrease_percent' => round((1 - $ratio) * 100, 1),
                        'likely_cause' => 'Service deactivation or structure entered low power'
                    ];
                }
            }
        }
        
        return $spikes;
    }
    
    /**
     * Generate monthly fuel report with actual usage
     */
    public static function generateMonthlyReport($structureId)
    {
        $analysis = self::analyzeFuelConsumption($structureId, 30);
        $spikes = self::detectFuelSpikes($structureId);
        
        $structure = DB::table('corporation_structures as cs')
            ->join('universe_structures as us', 'cs.structure_id', '=', 'us.structure_id')
            ->where('cs.structure_id', $structureId)
            ->select('us.name', 'cs.fuel_expires')
            ->first();
        
        return [
            'structure' => $structure->name ?? 'Unknown',
            'report_date' => Carbon::now(),
            'fuel_expires' => $structure->fuel_expires,
            'days_remaining' => $structure->fuel_expires ? 
                Carbon::parse($structure->fuel_expires)->diffInDays(Carbon::now()) : null,
            'consumption_analysis' => $analysis,
            'consumption_spikes' => $spikes,
            'fuel_efficiency' => [
                'average_daily' => $analysis['consumption']['average_daily'],
                'projected_monthly' => $analysis['consumption']['average_daily'] * 30,
                'actual_last_30_days' => $analysis['consumption']['actual_total'],
                'variance' => $analysis['consumption']['actual_total'] - ($analysis['consumption']['average_daily'] * 30),
            ],
            'recommendations' => $analysis['recommendations'],
        ];
    }
}

