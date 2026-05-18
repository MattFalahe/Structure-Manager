<?php

namespace StructureManager\Console\Commands;

use Illuminate\Console\Command;
use StructureManager\Jobs\PublishTimerScheduleEvents;

/**
 * Family B scheduler entry point. Dispatches the job that emits the three
 * time-window-driven `structure_manager.timer.*` flavors:
 *   - upcoming_24h
 *   - upcoming_1h
 *   - elapsed
 *
 * Wired to run every 5 minutes via ScheduleSeeder. Manual invocation
 * (artisan structure-manager:publish-timer-schedule-events) is fine for
 * testing or for catching up after a long maintenance window.
 */
class PublishTimerScheduleEventsCommand extends Command
{
    protected $signature = 'structure-manager:publish-timer-schedule-events';

    protected $description = 'Emit structure_manager.timer.* upcoming_24h / upcoming_1h / elapsed events for active timers. Family B of the cross-plugin event surface. No-op when Manager Core is not installed.';

    public function handle(): int
    {
        $this->info('Dispatching PublishTimerScheduleEvents job…');
        PublishTimerScheduleEvents::dispatch();
        $this->info('Done. Job queued — watch logs for emission counts.');
        return self::SUCCESS;
    }
}
