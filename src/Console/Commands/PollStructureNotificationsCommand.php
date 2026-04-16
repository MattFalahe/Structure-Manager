<?php

namespace StructureManager\Console\Commands;

use Illuminate\Console\Command;
use StructureManager\Jobs\PollStructureNotifications;

class PollStructureNotificationsCommand extends Command
{
    protected $signature = 'structure-manager:poll-structure-notifications';

    protected $description = 'Fast-poll ESI notifications from director key holders for structure events (attacks, anchoring, fuel, etc.)';

    public function handle()
    {
        $this->info('Polling ESI notifications from key holder pool...');
        dispatch(new PollStructureNotifications());
        $this->info('ESI polling job dispatched.');

        return 0;
    }
}
