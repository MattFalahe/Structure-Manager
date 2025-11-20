<?php

namespace StructureManager\Console\Commands;

use Illuminate\Console\Command;
use StructureManager\Jobs\TrackPosesFuel;

class TrackPosesFuelCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'structure-manager:track-poses-fuel';
    
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Track fuel consumption for all corporation POSes (Player Owned Starbases)';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Starting POS fuel consumption tracking...');
        
        // Dispatch the job
        dispatch(new TrackPosesFuel());
        
        $this->info('POS fuel tracking job dispatched.');
        
        return 0;
    }
}
