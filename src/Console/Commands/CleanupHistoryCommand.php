<?php

namespace StructureManager\Console\Commands;

use Illuminate\Console\Command;
use StructureManager\Models\StructureFuelHistory;
use StructureManager\Models\StarbaseFuelHistory;
use Carbon\Carbon;

class CleanupHistoryCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'structure-manager:cleanup-history 
                            {--days=180 : Number of days to retain Upwell structure history}
                            {--pos-days=90 : Number of days to retain POS history}';
    
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up old fuel history records (Upwell structures and POSes)';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $retentionDays = $this->option('days');
        $posRetentionDays = $this->option('pos-days');
        
        // Clean up Upwell structures
        $this->info("Cleaning up Upwell structure history older than {$retentionDays} days...");
        $cutoffDate = Carbon::now()->subDays($retentionDays);
        $deletedCount = StructureFuelHistory::where('created_at', '<', $cutoffDate)->delete();
        $this->info("Deleted {$deletedCount} old Upwell structure history records.");
        
        // Clean up POS data
        $this->info("Cleaning up POS history older than {$posRetentionDays} days...");
        $posCutoffDate = Carbon::now()->subDays($posRetentionDays);
        $deletedPosCount = StarbaseFuelHistory::where('created_at', '<', $posCutoffDate)->delete();
        $this->info("Deleted {$deletedPosCount} old POS history records.");
        
        // Also clean up orphaned consumption records
        $this->cleanupConsumptionRecords();
        
        return 0;
    }
    
    /**
     * Clean up orphaned consumption records
     */
    private function cleanupConsumptionRecords()
    {
        $deletedConsumption = \DB::table('structure_fuel_consumption')
            ->where('created_at', '<', Carbon::now()->subMonths(6))
            ->delete();
            
        $this->info("Deleted {$deletedConsumption} old structure consumption records.");
        
        $deletedPosConsumption = \DB::table('starbase_fuel_consumption')
            ->where('created_at', '<', Carbon::now()->subMonths(3))
            ->delete();
            
        $this->info("Deleted {$deletedPosConsumption} old POS consumption records.");
    }
}
