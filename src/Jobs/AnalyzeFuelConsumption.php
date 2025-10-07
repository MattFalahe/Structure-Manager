<?php

namespace StructureManager\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use StructureManager\Services\FuelConsumptionTracker;
use Illuminate\Support\Facades\Log;

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
     */
    private function analyzeStructure($structureId)
    {
        $analysis = FuelConsumptionTracker::analyzeFuelConsumption($structureId, 30);
        
        if ($analysis['status'] === 'success') {
            // Store analysis results in consumption table
            DB::table('structure_fuel_consumption')->updateOrInsert(
                [
                    'structure_id' => $structureId,
                    'date' => now()->toDateString(),
                ],
                [
                    'actual_daily_consumption' => $analysis['consumption']['actual_daily_avg'] ?? 0,
                    'average_hourly_rate' => $analysis['consumption']['average_hourly'] ?? 0,
                    'has_anomaly' => count($analysis['anomalies'] ?? []) > 0,
                    'anomaly_details' => json_encode($analysis['anomalies'] ?? []),
                    'updated_at' => now(),
                ]
            );
            
            // Check for critical fuel levels
            $this->checkCriticalLevels($structureId, $analysis);
        }
    }
    
    /**
     * Check for critical fuel levels and trigger alerts if needed
     */
    private function checkCriticalLevels($structureId, $analysis)
    {
        $structure = DB::table('corporation_structures')
            ->where('structure_id', $structureId)
            ->first();
        
        if (!$structure || !$structure->fuel_expires) {
            return;
        }
        
        $daysRemaining = \Carbon\Carbon::parse($structure->fuel_expires)->diffInDays(now());
        
        if ($daysRemaining < 7) {
            Log::warning('Structure Manager: Critical fuel level detected', [
                'structure_id' => $structureId,
                'days_remaining' => $daysRemaining,
                'daily_consumption' => $analysis['consumption']['average_daily'] ?? 0
            ]);
            
            // Here you could dispatch notification jobs or alerts
            // dispatch(new SendFuelAlert($structureId, $daysRemaining));
        }
    }
}
