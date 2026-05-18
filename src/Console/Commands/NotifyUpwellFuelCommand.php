<?php

namespace StructureManager\Console\Commands;

use Illuminate\Console\Command;
use StructureManager\Jobs\NotifyUpwellLowFuel;

class NotifyUpwellFuelCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'structure-manager:notify-upwell-fuel';

    /**
     * The console command description.
     */
    protected $description = 'Check Upwell structures for low fuel and send Discord/Slack notifications';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Checking Upwell structures for low fuel levels...');
        dispatch(new NotifyUpwellLowFuel());
        $this->info('Upwell notification job dispatched.');

        return 0;
    }
}
