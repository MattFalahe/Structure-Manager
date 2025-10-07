<?php

namespace StructureManager\Console\Commands;

use Illuminate\Console\Command;
use StructureManager\Jobs\TrackFuelConsumption;

class TrackFuelCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'structure-manager:track-fuel';
    
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Track fuel consumption for all corporation structures';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Starting fuel consumption tracking...');
        
        // Dispatch the job
        dispatch(new TrackFuelConsumption());
        
        $this->info('Fuel tracking job dispatched.');
        
        return 0;
    }
}
