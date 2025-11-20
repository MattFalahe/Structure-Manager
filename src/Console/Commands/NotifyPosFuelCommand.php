<?php

namespace StructureManager\Console\Commands;

use Illuminate\Console\Command;
use StructureManager\Jobs\NotifyPosLowFuel;

class NotifyPosFuelCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'structure-manager:notify-pos-fuel';
    
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check all POSes for low fuel levels and send Discord/Slack notifications';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Checking POSes for low fuel levels...');
        
        // Dispatch the notification job
        dispatch(new NotifyPosLowFuel());
        
        $this->info('POS notification job dispatched.');
        
        return 0;
    }
}
