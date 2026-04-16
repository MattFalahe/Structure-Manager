<?php

namespace StructureManager\Console\Commands;

use Illuminate\Console\Command;
use StructureManager\Jobs\SweepSeatNotifications;

class SweepSeatNotificationsCommand extends Command
{
    protected $signature = 'structure-manager:sweep-seat-notifications';

    protected $description = 'Sweep SeAT character_notifications table for structure events missed by fast-poll (fallback)';

    public function handle()
    {
        $this->info('Sweeping SeAT notification table for missed structure events...');
        dispatch(new SweepSeatNotifications());
        $this->info('Sweep job dispatched.');

        return 0;
    }
}
