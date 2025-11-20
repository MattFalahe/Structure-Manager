<?php

namespace StructureManager\Console\Commands;

use Illuminate\Console\Command;
use StructureManager\Jobs\AnalyzePosConsumption;

class AnalyzePosConsumptionCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'structure-manager:analyze-pos-consumption';
    
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Analyze daily fuel consumption patterns for POSes (Player Owned Starbases)';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Starting POS consumption analysis...');
        
        // Dispatch the job
        dispatch(new AnalyzePosConsumption());
        
        $this->info('POS consumption analysis job dispatched.');
        
        return 0;
    }
}
