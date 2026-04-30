<?php

namespace StructureManager\Jobs;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use StructureManager\Models\StructureManagerSettings;
use StructureManager\Models\Timer;

/**
 * Daily housekeeping for structure_manager_timers.
 *
 * Two-stage cleanup that wires the two existing settings keys nobody was
 * reading before this commit:
 *
 *   1. Auto-dismiss elapsed timers
 *      Setting: command_board_autodismiss_elapsed_hours (default 4)
 *      Effect: timers whose eve_time passed N hours ago AND haven't been
 *              dismissed yet get dismissed_at = now(). Removes them from
 *              the active board view but keeps the row for history.
 *      Why:    after a structure event resolves, operators don't want to
 *              keep manually clicking dismiss on every elapsed row.
 *
 *   2. Prune long-dismissed rows
 *      Setting: command_board_retention_days (default 30)
 *      Effect: rows where dismissed_at < (now - N days) AND eve_time has
 *              passed get permanently deleted.
 *      Why:    keep the table from growing unbounded. The Family B
 *              timer.* events are emitted at the time of the actual
 *              transitions; subscribers (Pings) maintain their own
 *              history of what happened. SM doesn't need to be the
 *              forever-archive.
 *
 * Active timers (eve_time in the future) are never touched — they stay
 * regardless of how old the row is.
 *
 * Scheduled to run daily via ScheduleSeeder. Manual entry point is
 * structure-manager:prune-structure-board-timers.
 */
class PruneStructureBoardTimers implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Bound below SeAT's queue retry_after default (960s). */
    public $timeout = 120;

    public $tries = 1;

    /** Default for command_board_autodismiss_elapsed_hours when unset. */
    private const DEFAULT_AUTODISMISS_HOURS = 4;

    /** Default for command_board_retention_days when unset. */
    private const DEFAULT_RETENTION_DAYS = 30;

    public function handle(): void
    {
        $autodismissHours = (int) StructureManagerSettings::get(
            'command_board_autodismiss_elapsed_hours',
            self::DEFAULT_AUTODISMISS_HOURS
        );
        $retentionDays = (int) StructureManagerSettings::get(
            'command_board_retention_days',
            self::DEFAULT_RETENTION_DAYS
        );

        $now = Carbon::now();

        // ============================================================
        // Stage 1: auto-dismiss elapsed timers older than N hours
        // ============================================================
        $autodismissed = 0;
        if ($autodismissHours > 0) {
            $autodismissCutoff = $now->copy()->subHours($autodismissHours);

            // Eloquent update to fire the observer (which fires
            // structure_manager.timer.dismissed events for subscribers).
            // Do this in chunks to avoid memory bloat on huge backlogs.
            Timer::query()
                ->whereNull('dismissed_at')
                ->where('eve_time', '<', $autodismissCutoff)
                ->chunkById(200, function ($timers) use ($now, &$autodismissed) {
                    foreach ($timers as $timer) {
                        $timer->dismissed_at = $now;
                        $timer->save();
                        $autodismissed++;
                    }
                });
        }

        // ============================================================
        // Stage 2: prune dismissed timers older than N days
        // ============================================================
        $pruned = 0;
        if ($retentionDays > 0) {
            $pruneCutoff = $now->copy()->subDays($retentionDays);

            // Permanently delete — these rows have been dismissed for at
            // least retentionDays AND their eve_time has passed (covered
            // by the dismissed filter; an active timer wouldn't be dismissed).
            // Direct delete (no observer events) — subscribers already saw
            // the .dismissed event when these were dismissed.
            $pruned = Timer::query()
                ->whereNotNull('dismissed_at')
                ->where('dismissed_at', '<', $pruneCutoff)
                ->where('eve_time', '<', $now)
                ->delete();
        }

        Log::info(
            "PruneStructureBoardTimers: auto-dismissed {$autodismissed} elapsed timer(s) " .
            "(>{$autodismissHours}h past eve_time), permanently deleted {$pruned} dismissed timer(s) " .
            "(>{$retentionDays}d since dismissal)"
        );
    }
}
