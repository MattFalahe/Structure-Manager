<?php

namespace StructureManager\Console\Commands;

use Illuminate\Console\Command;
use StructureManager\Jobs\AnalyzeFuelConsumption;

class AnalyzeConsumptionCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'structure-manager:analyze-consumption 
                            {--structure= : Specific structure ID to analyze}
                            {--corporation= : Analyze all structures for a corporation}';
    
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Analyze fuel consumption patterns and generate reports';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $structureId = $this->option('structure');
        $corporationId = $this->option('corporation');
        
        if ($structureId) {
            $this->info("Analyzing consumption for structure {$structureId}...");
            dispatch(new AnalyzeFuelConsumption($structureId));
        } elseif ($corporationId) {
            $this->info("Analyzing consumption for corporation {$corporationId}...");
            dispatch(new AnalyzeFuelConsumption(null, $corporationId));
        } else {
            $this->info('Analyzing consumption for all structures...');
            dispatch(new AnalyzeFuelConsumption());
        }
        
        $this->info('Consumption analysis job dispatched.');
        
        return 0;
    }
}
