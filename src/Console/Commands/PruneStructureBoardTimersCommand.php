<?php

namespace StructureManager\Console\Commands;

use Illuminate\Console\Command;
use StructureManager\Jobs\PruneStructureBoardTimers;

/**
 * Daily Structure Board cleanup. Reads:
 *   - command_board_autodismiss_elapsed_hours (default 4)
 *   - command_board_retention_days            (default 30)
 *
 * Auto-dismisses timers whose eve_time has passed by more than N hours,
 * then permanently deletes timers that have been dismissed for more than
 * M days.
 */
class PruneStructureBoardTimersCommand extends Command
{
    protected $signature = 'structure-manager:prune-structure-board-timers';

    protected $description = 'Auto-dismiss long-elapsed timers and permanently delete dismissed timers older than the configured retention window. Reads command_board_autodismiss_elapsed_hours and command_board_retention_days settings.';

    public function handle(): int
    {
        $this->info('Dispatching PruneStructureBoardTimers job…');
        PruneStructureBoardTimers::dispatch();
        $this->info('Done. Job queued — watch logs for prune counts.');
        return self::SUCCESS;
    }
}
