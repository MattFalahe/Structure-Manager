<?php

namespace StructureManager\Console\Commands;

use Illuminate\Console\Command;
use StructureManager\Models\StructureFuelHistory;
use Carbon\Carbon;

class CleanupHistoryCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'structure-manager:cleanup-history 
                            {--days=180 : Number of days to retain history}';
    
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up old fuel history records';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $retentionDays = $this->option('days');
        $this->info("Cleaning up fuel history older than {$retentionDays} days...");
        
        $cutoffDate = Carbon::now()->subDays($retentionDays);
        
        $deletedCount = StructureFuelHistory::where('created_at', '<', $cutoffDate)->delete();
        
        $this->info("Deleted {$deletedCount} old history records.");
        
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
            
        $this->info("Deleted {$deletedConsumption} old consumption records.");
    }
}
