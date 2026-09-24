<?php

namespace StructureManager\Console\Commands;

use Illuminate\Console\Command;
use StructureManager\Jobs\TrackStructureDeployments;

class TrackStructureDeploymentsCommand extends Command
{
    protected $signature = 'structure-manager:track-deployments';

    protected $description = 'Follow newly deployed Upwell structures through anchoring and the Quantum Core stage on the Structure Board, and send core reminders.';

    public function handle(): int
    {
        $this->info('Dispatching TrackStructureDeployments job…');
        TrackStructureDeployments::dispatch();
        $this->info('Done. Job queued — watch logs for results.');

        return self::SUCCESS;
    }
}
