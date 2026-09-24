<?php

namespace StructureManager\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use StructureManager\Services\StructureDeploymentTracker;

/**
 * Keeps the Structure Board's deployment stages in step with
 * corporation_structures, and sends any Quantum Core reminders that have come
 * due. Needs nothing beyond SeAT's own structure data, so it runs with or
 * without Manager Core.
 */
class TrackStructureDeployments implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $timeout = 120;

    public $tries = 2;

    public $backoff = [60];

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('structure-manager:track-deployments'))
                ->dontRelease()
                ->expireAfter($this->timeout + 60),
        ];
    }

    public function handle(): void
    {
        $stats = StructureDeploymentTracker::syncFromState();
        $reminders = StructureDeploymentTracker::sendDueReminders();

        Log::info(sprintf(
            'TrackStructureDeployments: %d structure(s) deploying, %d deployment(s) finished, %d core reminder(s) sent',
            $stats['deploying'],
            $stats['finished'],
            $reminders
        ));
    }
}
