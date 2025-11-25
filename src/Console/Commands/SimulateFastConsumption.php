<?php

namespace StructureManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Simulate fast fuel consumption for testing
 * 
 * This command simulates 20-minute consumption cycles instead of hourly
 * Run it manually or schedule it every 20 minutes during testing
 * 
 * Usage:
 * php artisan structure-manager:simulate-consumption
 * php artisan structure-manager:simulate-consumption --cycles=3
 */
class SimulateFastConsumption extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'structure-manager:simulate-consumption
                            {--cycles=1 : Number of 20-minute cycles to simulate}
                            {--test-only : Only process test POSes (IDs >= 1000000000)}';

    /**
     * The console command description.
     */
    protected $description = 'Simulate fast fuel consumption for testing (20-minute cycles)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $cycles = (int) $this->option('cycles');
        $testOnly = $this->option('test-only');
        
        $this->info("Simulating {$cycles} consumption cycle(s) at 20-minute intervals...");
        if ($testOnly) {
            $this->info("Processing TEST POSes only (IDs >= 1000000000)");
        }
        $this->newLine();

        for ($i = 1; $i <= $cycles; $i++) {
            $this->info("Cycle {$i}/{$cycles}:");
            $this->processCycle($testOnly);
            $this->newLine();
            
            if ($i < $cycles) {
                $this->line("Waiting 1 second before next cycle...");
                sleep(1);
            }
        }

        $this->info("✓ Simulation complete!");
        $this->info("Check notifications and fuel levels");
    }

    /**
     * Process one consumption cycle
     */
    private function processCycle($testOnly)
    {
        // Get all online POSes
        $query = DB::table('starbase_fuel_history as sfh')
            ->select('sfh.*')
            ->whereIn('sfh.id', function($subquery) {
                $subquery->select(DB::raw('MAX(id)'))
                    ->from('starbase_fuel_history')
                    ->groupBy('starbase_id');
            })
            ->where('sfh.state', 4); // Online only
        
        if ($testOnly) {
            $query->where('sfh.starbase_id', '>=', 1000000000);
        }
        
        $poses = $query->get();
        
        if ($poses->isEmpty()) {
            $this->warn("No online POSes found");
            return;
        }

        $this->line("Processing {$poses->count()} online POS(es)...");
        
        $consumedCount = 0;
        $lowFuelCount = 0;
        $criticalCount = 0;
        
        foreach ($poses as $pos) {
            $result = $this->consumeFuel($pos);
            
            if ($result['consumed']) {
                $consumedCount++;
            }
            
            if ($result['status'] === 'low') {
                $lowFuelCount++;
                $this->line("  ⚠️  {$pos->starbase_name}: {$result['fuel_remaining']}d fuel remaining");
            } elseif ($result['status'] === 'critical') {
                $criticalCount++;
                $this->warn("  🔴 {$pos->starbase_name}: {$result['fuel_remaining']}d fuel remaining (CRITICAL!)");
            }
            
            // Check strontium
            if ($result['strontium_hours'] < 6) {
                $this->warn("  🛡️  {$pos->starbase_name}: {$result['strontium_hours']}h strontium (CRITICAL!)");
            }
        }
        
        $this->info("  ✓ Consumed fuel for {$consumedCount} POS(es)");
        if ($lowFuelCount > 0) {
            $this->warn("  ⚠️  {$lowFuelCount} POS(es) with low fuel");
        }
        if ($criticalCount > 0) {
            $this->error("  🔴 {$criticalCount} POS(es) with critical fuel!");
        }
    }

    /**
     * Consume fuel for a POS (20-minute cycle)
     */
    private function consumeFuel($pos)
    {
        $metadata = json_decode($pos->metadata, true) ?? [];
        $fuelPerHour = $metadata['fuel_per_hour'] ?? 40;
        
        // Calculate 20-minute consumption (1/3 of hourly rate)
        $fuelConsumed = $fuelPerHour / 3;
        $strontiumConsumed = 0; // Strontium only consumed when reinforced
        
        // Consume fuel blocks
        $newFuelQuantity = max(0, $pos->fuel_blocks_quantity - $fuelConsumed);
        $newFuelDays = $newFuelQuantity / ($fuelPerHour * 24);
        
        // Calculate new values
        $newStrontiumHours = $pos->strontium_hours_available; // Unchanged for online POS
        
        // Determine status
        $status = 'good';
        if ($newFuelDays < 7) {
            $status = 'critical';
        } elseif ($newFuelDays < 14) {
            $status = 'low';
        }
        
        // Update fuel history
        DB::table('starbase_fuel_history')->insert([
            'starbase_id' => $pos->starbase_id,
            'corporation_id' => $pos->corporation_id,
            'tower_type_id' => $pos->tower_type_id,
            'starbase_name' => $pos->starbase_name,
            'system_id' => $pos->system_id,
            'state' => $pos->state,
            'fuel_blocks_quantity' => $newFuelQuantity,
            'fuel_days_remaining' => $newFuelDays,
            'fuel_blocks_used' => $fuelConsumed,
            'fuel_hourly_consumption' => $fuelPerHour,
            'strontium_quantity' => $pos->strontium_quantity,
            'strontium_hours_available' => $newStrontiumHours,
            'strontium_status' => $pos->strontium_status,
            'charter_quantity' => $pos->charter_quantity,
            'charter_days_remaining' => $pos->charter_days_remaining,
            'requires_charters' => $pos->requires_charters,
            'actual_days_remaining' => $newFuelDays,
            'limiting_factor' => $pos->limiting_factor,
            'estimated_fuel_expiry' => Carbon::now()->addDays($newFuelDays),
            'system_security' => $pos->system_security,
            'space_type' => $pos->space_type,
            'metadata' => $pos->metadata,
            'last_fuel_notification_status' => $pos->last_fuel_notification_status,
            'last_fuel_notification_at' => $pos->last_fuel_notification_at,
            'fuel_final_alert_sent' => $pos->fuel_final_alert_sent,
            'last_strontium_notification_status' => $pos->last_strontium_notification_status,
            'last_strontium_notification_at' => $pos->last_strontium_notification_at,
            'strontium_final_alert_sent' => $pos->strontium_final_alert_sent,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
        
        return [
            'consumed' => $fuelConsumed > 0,
            'fuel_remaining' => round($newFuelDays, 1),
            'strontium_hours' => $newStrontiumHours,
            'status' => $status,
        ];
    }
}
