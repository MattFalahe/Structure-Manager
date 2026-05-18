<?php

namespace StructureManager\Console\Commands;

use Illuminate\Console\Command;
use StructureManager\Jobs\TrackStructurePresence;

class TrackStructurePresenceCommand extends Command
{
    protected $signature = 'structure-manager:track-structure-presence';

    protected $description = 'Track Upwell structure presence in corporation_structures and detect disappearances. Drives the MEDIUM-confidence path of structure.alert.destroyed events.';

    public function handle(): int
    {
        $this->info('Dispatching TrackStructurePresence job…');
        TrackStructurePresence::dispatch();
        $this->info('Done. Job queued — watch logs for sync results.');
        return self::SUCCESS;
    }
}
