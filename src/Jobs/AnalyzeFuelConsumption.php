<?php

namespace StructureManager\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use StructureManager\Services\FuelConsumptionTracker;
use StructureManager\Helpers\FuelCalculator;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class AnalyzeFuelConsumption implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    protected $structureId;
    protected $corporationId;

    /**
     * Create a new job instance.
     */
    public function __construct($structureId = null, $corporationId = null)
    {
        $this->structureId = $structureId;
        $this->corporationId = $corporationId;
    }

    /**
     * Execute the job.
     */
    public function handle()
    {
        try {
            if ($this->structureId) {
                // Analyze specific structure
                $this->analyzeStructure($this->structureId);
            } elseif ($this->corporationId) {
                // Analyze all structures for a corporation
                $structures = DB::table('corporation_structures')
                    ->where('corporation_id', $this->corporationId)
                    ->whereNotNull('fuel_expires')
                    ->pluck('structure_id');
                
                foreach ($structures as $structureId) {
                    $this->analyzeStructure($structureId);
                }
            } else {
                // Analyze all structures
                $structures = DB::table('corporation_structures')
                    ->whereNotNull('fuel_expires')
                    ->pluck('structure_id');
                
                foreach ($structures as $structureId) {
                    $this->analyzeStructure($structureId);
                }
            }
            
            Log::info('Structure Manager: Fuel consumption analysis completed');
        } catch (\Exception $e) {
            Log::error('Structure Manager: Failed to analyze fuel consumption', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw $e; // Re-throw to mark job as failed
        }
    }
    
    /**
     * Analyze a single structure's consumption
     * Focuses on EVENT DETECTION (refuels, anomalies, alerts)
     * Display consumption comes from FuelCalculator service-based rates
     */
    private function analyzeStructure($structureId)
    {
        $analysis = FuelConsumptionTracker::analyzeFuelConsumption($structureId, 30);
        
        if ($analysis['status'] === 'success') {
            // Store analysis results in consumption table (for historical reference)
            DB::table('structure_fuel_consumption')->updateOrInsert(
                [
                    'structure_id' => $structureId,
                    'date' => now()->toDateString(),
                ],
                [
                    'actual_daily_consumption' => $analysis['consumption']['average_daily'] ?? 0,
                    'average_hourly_rate' => $analysis['consumption']['average_hourly'] ?? 0,
                    'has_anomaly' => count($analysis['anomalies'] ?? []) > 0,
                    'anomaly_details' => json_encode($analysis['anomalies'] ?? []),
                    'refuel_amount' => $this->getLatestRefuelAmount($structureId),
                    'updated_at' => now(),
                ]
            );
            
            // Check for critical fuel levels and anomalies
            $this->checkCriticalLevels($structureId, $analysis);
            
            // Log refuel events
            $this->logRefuelEvents($structureId, $analysis);
            
            // Detect and log consumption anomalies (service changes)
            $this->detectAnomalies($structureId, $analysis);
            
            Log::info("Structure Manager: Analyzed structure {$structureId}", [
                'daily_consumption' => $analysis['consumption']['average_daily'] ?? 0,
                'hourly_consumption' => $analysis['consumption']['average_hourly'] ?? 0,
                'anomalies' => count($analysis['anomalies'] ?? []),
                'refuels' => count($analysis['refuel_events'] ?? []),
                'fuel_bay_records' => $analysis['analysis_period']['fuel_bay_records'] ?? 0,
                'fallback_records' => $analysis['analysis_period']['fallback_records'] ?? 0,
            ]);
        } else {
            Log::warning("Structure Manager: Insufficient data for structure {$structureId}", [
                'data_points' => $analysis['data_points'] ?? 0,
            ]);
        }
    }
    
    /**
     * Get the most recent refuel amount for a structure
     */
    private function getLatestRefuelAmount($structureId)
    {
        $latestRefuel = DB::table('structure_fuel_history')
            ->where('structure_id', $structureId)
            ->where('fuel_blocks_used', '<', 0) // Negative means fuel added
            ->orderBy('created_at', 'desc')
            ->first();
        
        return $latestRefuel ? abs($latestRefuel->fuel_blocks_used) : null;
    }
    
    /**
     * Check for critical fuel levels and trigger alerts
     */
    private function checkCriticalLevels($structureId, $analysis)
    {
        $structure = DB::table('corporation_structures')
            ->where('structure_id', $structureId)
            ->first();
        
        if (!$structure || !$structure->fuel_expires) {
            return;
        }
        
        $daysRemaining = Carbon::parse($structure->fuel_expires)->diffInDays(now());
        
        // Get current fuel status from analysis
        $fuelStatus = $analysis['current_status'] ?? null;
        
        // Get expected consumption from current services
        $expectedDaily = FuelCalculator::getFuelRequirement($structure->type_id, $structureId, 'daily');
        
        // Critical: Less than 7 days in fuel bay
        if ($fuelStatus && isset($fuelStatus['bay_days_supply']) && $fuelStatus['bay_days_supply'] < 7) {
            Log::warning('Structure Manager: CRITICAL fuel bay level', [
                'structure_id' => $structureId,
                'bay_days_supply' => $fuelStatus['bay_days_supply'],
                'fuel_bay_blocks' => $fuelStatus['fuel_bay_blocks'] ?? 0,
                'reserve_blocks' => $fuelStatus['reserve_blocks'] ?? 0,
                'expected_daily_consumption' => $expectedDaily,
                'tracked_daily_consumption' => $analysis['consumption']['average_daily'] ?? 0,
            ]);
            
            // TODO: Dispatch notification jobs or alerts
            // dispatch(new SendFuelAlert($structureId, $fuelStatus['bay_days_supply'], 'critical'));
        } 
        // Warning: Less than 14 days in fuel bay
        elseif ($fuelStatus && isset($fuelStatus['bay_days_supply']) && $fuelStatus['bay_days_supply'] < 14) {
            Log::info('Structure Manager: Warning fuel bay level', [
                'structure_id' => $structureId,
                'bay_days_supply' => $fuelStatus['bay_days_supply'],
                'fuel_bay_blocks' => $fuelStatus['fuel_bay_blocks'] ?? 0,
                'expected_daily_consumption' => $expectedDaily,
            ]);
            
            // TODO: Dispatch notification jobs or alerts
            // dispatch(new SendFuelAlert($structureId, $fuelStatus['bay_days_supply'], 'warning'));
        }
        
        // Check for overall fuel status (bay + reserves)
        if ($daysRemaining < 7) {
            Log::warning('Structure Manager: CRITICAL overall fuel level', [
                'structure_id' => $structureId,
                'days_remaining' => $daysRemaining,
                'expected_daily_consumption' => $expectedDaily,
            ]);
        }
        
        // Check if reserve fuel needs to be moved to bay
        if ($fuelStatus && 
            isset($fuelStatus['reserve_blocks']) && 
            $fuelStatus['reserve_blocks'] > 0 && 
            isset($fuelStatus['bay_days_supply']) && 
            $fuelStatus['bay_days_supply'] < 10) {
            
            Log::info('Structure Manager: Recommendation to move reserve fuel', [
                'structure_id' => $structureId,
                'bay_days_supply' => $fuelStatus['bay_days_supply'],
                'reserve_blocks' => $fuelStatus['reserve_blocks'],
                'message' => 'Consider moving reserve fuel from corporate hangar to fuel bay',
            ]);
        }
    }
    
    /**
     * Log refuel events
     */
    private function logRefuelEvents($structureId, $analysis)
    {
        if (!isset($analysis['refuel_events']) || empty($analysis['refuel_events'])) {
            return;
        }
        
        foreach ($analysis['refuel_events'] as $refuel) {
            Log::info('Structure Manager: Refuel event detected', [
                'structure_id' => $structureId,
                'timestamp' => $refuel['timestamp'],
                'blocks_added' => $refuel['blocks_added'] ?? $refuel['estimated_blocks_added'] ?? 'unknown',
                'method' => $refuel['method'],
            ]);
        }
    }
    
    /**
     * Detect and log consumption anomalies (service activation/deactivation)
     * Compares tracked consumption vs expected service-based rate
     */
    private function detectAnomalies($structureId, $analysis)
    {
        if (!isset($analysis['anomalies']) || empty($analysis['anomalies'])) {
            return;
        }
        
        // Get current expected consumption from services
        $structure = DB::table('corporation_structures')
            ->where('structure_id', $structureId)
            ->first();
        
        if (!$structure) {
            return;
        }
        
        $expectedDaily = FuelCalculator::getFuelRequirement($structure->type_id, $structureId, 'daily');
        $trackedDaily = $analysis['consumption']['average_daily'] ?? 0;
        
        // Calculate variance between expected and tracked
        $variance = 0;
        if ($expectedDaily > 0 && $trackedDaily > 0) {
            $variance = (($trackedDaily - $expectedDaily) / $expectedDaily) * 100;
        }
        
        // Log anomalies with context
        foreach ($analysis['anomalies'] as $anomaly) {
            Log::info('Structure Manager: Consumption anomaly detected', [
                'structure_id' => $structureId,
                'timestamp' => $anomaly['timestamp'],
                'anomaly_details' => $anomaly,
                'current_service_based_rate' => $expectedDaily,
                'tracked_average_rate' => $trackedDaily,
                'variance_percent' => round($variance, 1),
                'possible_cause' => $anomaly['possible_cause'] ?? 'unknown',
            ]);
            
            // TODO: If variance is significant, dispatch alert
            // if (abs($variance) > 20) {
            //     dispatch(new SendAnomalyAlert($structureId, $anomaly, $variance));
            // }
        }
        
        // Log significant variance even if no anomalies detected
        if (abs($variance) > 15 && empty($analysis['anomalies'])) {
            Log::info('Structure Manager: Consumption variance detected', [
                'structure_id' => $structureId,
                'expected_daily' => $expectedDaily,
                'tracked_daily' => $trackedDaily,
                'variance_percent' => round($variance, 1),
                'note' => 'Tracked consumption differs from current service configuration. Services may have changed recently.',
            ]);
        }
    }
}
