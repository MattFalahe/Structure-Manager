<?php

namespace StructureManager\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use StructureManager\Models\StructureFuelHistory;
use StructureManager\Models\StructureFuelReserves;

class FuelConsumptionTracker
{
    /**
     * Analyze fuel consumption patterns for a structure
     * NOW USES ONLY FUEL BAY DATA (not reserves)
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
                'message' => 'Need at least 2 data points to calculate consumption',
                'data_points' => $history->count(),
            ];
        }
        
        $consumptionPeriods = [];
        $refuelEvents = [];
        $anomalies = [];
        $fuelBayRecords = 0;
        $fallbackRecords = 0;
        
        // Analyze each consecutive pair of records
        for ($i = 1; $i < $history->count(); $i++) {
            $previous = $history[$i - 1];
            $current = $history[$i];
            
            // Track which method was used
            if ($current->tracking_type === 'fuel_bay') {
                $fuelBayRecords++;
            } else {
                $fallbackRecords++;
            }
            
            // Skip if either record has null values
            if (!$previous->fuel_expires || !$current->fuel_expires) {
                continue;
            }
            
            $realHoursPassed = $previous->created_at->diffInHours($current->created_at);
            
            if ($realHoursPassed == 0) continue;
            
            // Prefer fuel_bay method if available
            if ($current->tracking_type === 'fuel_bay' && $previous->tracking_type === 'fuel_bay') {
                $prevMeta = json_decode($previous->metadata, true);
                $currMeta = json_decode($current->metadata, true);
                
                if (isset($prevMeta['fuel_blocks']) && isset($currMeta['fuel_blocks'])) {
                    $blockChange = $prevMeta['fuel_blocks'] - $currMeta['fuel_blocks'];
                    
                    if ($blockChange < 0) {
                        // Refuel detected
                        $blocksAdded = abs($blockChange);
                        $refuelEvents[] = [
                            'timestamp' => $current->created_at,
                            'blocks_added' => $blocksAdded,
                            'previous_blocks' => $prevMeta['fuel_blocks'],
                            'new_blocks' => $currMeta['fuel_blocks'],
                            'method' => 'fuel_bay',
                        ];
                    } else {
                        // Normal consumption
                        $hourlyRate = $blockChange / $realHoursPassed;
                        $dailyRate = $hourlyRate * 24;
                        
                        $consumptionPeriods[] = [
                            'start' => $previous->created_at,
                            'end' => $current->created_at,
                            'hours' => $realHoursPassed,
                            'blocks_consumed' => $blockChange,
                            'hourly_rate' => round($hourlyRate, 2),
                            'daily_rate' => round($dailyRate),
                            'method' => 'fuel_bay',
                            'type' => 'normal'
                        ];
                    }
                    continue;
                }
            }
            
            // Fallback to days_remaining method
            if ($previous->days_remaining === null || $current->days_remaining === null) {
                continue;
            }
            
            $fuelDaysChange = $previous->days_remaining - $current->days_remaining;
            
            if ($fuelDaysChange < 0) {
                // Refuel detected
                $daysAdded = abs($fuelDaysChange);
                $refuelEvents[] = [
                    'timestamp' => $current->created_at,
                    'days_added' => $daysAdded,
                    'estimated_blocks_added' => round($daysAdded * 40),
                    'method' => 'days_remaining',
                ];
            } else {
                // Normal consumption
                $realDaysPassed = $realHoursPassed / 24;
                $consumptionRate = $realDaysPassed > 0 ? $fuelDaysChange / $realDaysPassed : 0;
                $blocksPerDay = round($consumptionRate * 40);
                
                $consumptionPeriods[] = [
                    'start' => $previous->created_at,
                    'end' => $current->created_at,
                    'real_days' => round($realDaysPassed, 2),
                    'fuel_days_consumed' => $fuelDaysChange,
                    'rate' => round($consumptionRate, 2),
                    'blocks_per_day' => $blocksPerDay,
                    'method' => 'days_remaining',
                    'type' => $consumptionRate >= 0.95 && $consumptionRate <= 1.05 ? 'normal' : 'anomaly'
                ];
                
                // Detect anomalies
                if ($consumptionRate < 0.95 || $consumptionRate > 1.05) {
                    $anomalies[] = [
                        'timestamp' => $current->created_at,
                        'expected_rate' => 1.0,
                        'actual_rate' => round($consumptionRate, 2),
                        'difference' => round(($consumptionRate - 1.0) * 100, 1) . '%',
                        'possible_cause' => $consumptionRate > 1.05 ? 'service_activated' : 'service_deactivated'
                    ];
                }
            }
        }
        
        // Calculate average consumption from normal periods
        $normalPeriods = array_filter($consumptionPeriods, function($p) {
            return $p['type'] == 'normal';
        });
        
        $averageDailyBlocks = 0;
        $averageHourlyBlocks = 0;
        
        if (count($normalPeriods) > 0) {
            // Prefer fuel_bay method calculations
            $fuelBayPeriods = array_filter($normalPeriods, fn($p) => $p['method'] === 'fuel_bay');
            
            if (count($fuelBayPeriods) > 0) {
                // Use fuel bay data (most accurate)
                $totalHours = array_sum(array_column($fuelBayPeriods, 'hours'));
                $totalBlocks = array_sum(array_column($fuelBayPeriods, 'blocks_consumed'));
                
                if ($totalHours > 0) {
                    $averageHourlyBlocks = round($totalBlocks / $totalHours, 2);
                    $averageDailyBlocks = round($averageHourlyBlocks * 24);
                }
            } else {
                // Fallback to days_remaining calculation
                $totalRealDays = array_sum(array_column($normalPeriods, 'real_days'));
                $totalBlocks = array_sum(array_column($normalPeriods, 'blocks_per_day'));
                
                if ($totalRealDays > 0) {
                    $averageDailyBlocks = round($totalBlocks / count($normalPeriods));
                    $averageHourlyBlocks = round($averageDailyBlocks / 24, 2);
                }
            }
        }
        
        // Get current fuel status - FUEL BAY ONLY
        $latestRecord = $history->last();
        $currentFuelStatus = null;
        
        if ($latestRecord && $latestRecord->metadata) {
            $metadata = json_decode($latestRecord->metadata, true);
            $fuelBayBlocks = $metadata['fuel_blocks'] ?? 0;
            
            $currentFuelStatus = [
                'fuel_bay_blocks' => $fuelBayBlocks,
                'days_remaining' => $latestRecord->days_remaining,
            ];
            
            // Calculate days of fuel available IN BAY
            if ($averageDailyBlocks > 0 && $fuelBayBlocks > 0) {
                $currentFuelStatus['bay_days_supply'] = round($fuelBayBlocks / $averageDailyBlocks, 1);
            }
        }
        
        // Get reserves separately
        $reserves = StructureFuelReserves::getTotalReserves($structureId);
        if ($reserves > 0) {
            $currentFuelStatus['reserve_blocks'] = $reserves;
            $currentFuelStatus['total_blocks'] = $currentFuelStatus['fuel_bay_blocks'] + $reserves;
            
            if ($averageDailyBlocks > 0) {
                $currentFuelStatus['total_days_supply'] = round($currentFuelStatus['total_blocks'] / $averageDailyBlocks, 1);
            }
        }
        
        return [
            'status' => 'success',
            'structure_id' => $structureId,
            'analysis_period' => [
                'days' => $days,
                'start' => Carbon::now()->subDays($days),
                'end' => Carbon::now(),
                'data_points' => $history->count(),
                'fuel_bay_records' => $fuelBayRecords,
                'fallback_records' => $fallbackRecords,
            ],
            'consumption' => [
                'average_hourly' => $averageHourlyBlocks,
                'average_daily' => $averageDailyBlocks,
                'average_weekly' => $averageDailyBlocks * 7,
                'average_monthly' => $averageDailyBlocks * 30,
            ],
            'current_status' => $currentFuelStatus,
            'refuel_events' => $refuelEvents,
            'anomalies' => $anomalies,
            'consumption_periods' => $consumptionPeriods,
            'recommendations' => self::generateRecommendations($averageDailyBlocks, $refuelEvents, $anomalies, $currentFuelStatus),
        ];
    }
    
    /**
     * Generate recommendations based on consumption patterns
     * UPDATED to account for separated fuel bay and reserves
     */
    private static function generateRecommendations($avgDaily, $refuelEvents, $anomalies, $fuelStatus)
    {
        $recommendations = [];
        
        // Refueling frequency
        if (count($refuelEvents) > 0) {
            $avgDaysBetweenRefuel = 30 / count($refuelEvents);
            if ($avgDaysBetweenRefuel < 7) {
                $recommendations[] = [
                    'type' => 'efficiency',
                    'message' => 'Frequent refueling detected (' . round($avgDaysBetweenRefuel, 1) . ' days between refuels). Consider larger fuel hauls or moving more from reserves to fuel bay.',
                    'severity' => 'medium'
                ];
            }
        }
        
        // Service optimization
        if (count($anomalies) > 2) {
            $recommendations[] = [
                'type' => 'stability',
                'message' => 'Multiple consumption rate changes detected (' . count($anomalies) . ' anomalies). Review service activation patterns.',
                'severity' => 'low'
            ];
        }
        
        // Fuel bay warning
        if ($fuelStatus && isset($fuelStatus['bay_days_supply']) && $fuelStatus['bay_days_supply'] < 7) {
            $recommendations[] = [
                'type' => 'urgent',
                'message' => sprintf('Fuel bay critically low! Only %.1f days supply remaining. Move fuel from reserves immediately.', $fuelStatus['bay_days_supply']),
                'severity' => 'critical'
            ];
        } elseif ($fuelStatus && isset($fuelStatus['bay_days_supply']) && $fuelStatus['bay_days_supply'] < 14) {
            $recommendations[] = [
                'type' => 'warning',
                'message' => sprintf('Fuel bay running low. %.1f days supply remaining. Plan to refuel soon.', $fuelStatus['bay_days_supply']),
                'severity' => 'warning'
            ];
        }
        
        // Reserve fuel check
        if ($fuelStatus && isset($fuelStatus['reserve_blocks']) && $fuelStatus['reserve_blocks'] > 0 && isset($fuelStatus['bay_days_supply']) && $avgDaily > 0) {
            $reserveDays = ($fuelStatus['reserve_blocks'] / $avgDaily);
            $recommendations[] = [
                'type' => 'info',
                'message' => sprintf('Reserve fuel available: %s blocks (%.1f days supply). Can be moved to fuel bay when needed.', 
                    number_format($fuelStatus['reserve_blocks']), 
                    $reserveDays),
                'severity' => 'info'
            ];
        } elseif ($fuelStatus && (!isset($fuelStatus['reserve_blocks']) || $fuelStatus['reserve_blocks'] == 0)) {
            $recommendations[] = [
                'type' => 'warning',
                'message' => 'No reserve fuel detected in CorpSAG hangars. Consider staging backup fuel.',
                'severity' => 'medium'
            ];
        }
        
        // Fuel planning
        if ($avgDaily > 0) {
            $monthlyRequired = $avgDaily * 30;
            $recommendations[] = [
                'type' => 'planning',
                'message' => sprintf('Monthly fuel requirement: %s blocks (%s m³). Plan logistics accordingly.', 
                    number_format($monthlyRequired),
                    number_format($monthlyRequired * 5)),
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
                        'previous_consumption' => round($recent[$i-1]->daily_consumption, 2),
                        'new_consumption' => round($recent[$i]->daily_consumption, 2),
                        'increase_percent' => round(($ratio - 1) * 100, 1),
                        'likely_cause' => 'Service activation or structure came out of low power'
                    ];
                } elseif ($ratio < (1 / $threshold)) {
                    $spikes[] = [
                        'timestamp' => $recent[$i]->created_at,
                        'previous_consumption' => round($recent[$i-1]->daily_consumption, 2),
                        'new_consumption' => round($recent[$i]->daily_consumption, 2),
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
        
        // Get reserve information
        $reserves = StructureFuelReserves::getCurrentReserves($structureId);
        $totalReserves = $reserves->sum('reserve_quantity');
        
        return [
            'structure' => $structure->name ?? 'Unknown',
            'report_date' => Carbon::now(),
            'fuel_expires' => $structure->fuel_expires,
            'days_remaining' => $structure->fuel_expires ? 
                Carbon::parse($structure->fuel_expires)->diffInDays(Carbon::now()) : null,
            'consumption_analysis' => $analysis,
            'consumption_spikes' => $spikes,
            'fuel_status' => $analysis['current_status'] ?? [],
            'reserves' => [
                'total_blocks' => $totalReserves,
                'by_location' => $reserves,
                'recent_movements' => StructureFuelReserves::getRefuelEvents($structureId, 30),
            ],
            'recommendations' => $analysis['recommendations'] ?? [],
        ];
    }
}
