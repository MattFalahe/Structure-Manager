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
 * Housekeeping for structure_manager_timers.
 *
 * Three-stage cleanup wired to the existing settings keys plus a special
 * fast-path for under_attack rows:
 *
 *   1. Fast-dismiss elapsed transient-event rows
 *      Effect: rows with event_type in (under_attack, entosis_in_progress)
 *              AND eve_time < now get dismissed_at = now() IMMEDIATELY
 *              (no grace hours).
 *      Why:    these event types have NO real future deadline in CCP's data
 *              — they're "happening now" signals where we set a synthetic
 *              eve_time at notification_timestamp + a short window:
 *                 under_attack       = +15 min (CCP auto-repair window)
 *                 entosis_in_progress= +60 min (~2 entosis cycles)
 *              Once the window elapses, the event is over (cycle completed
 *              or failed) and the row is stale. Standard 4h grace is too
 *              slow — operator wants the board clean within minutes.
 *
 *   2. Auto-dismiss other elapsed timers
 *      Setting: command_board_autodismiss_elapsed_hours (default 4)
 *      Effect: timers whose eve_time passed N hours ago AND haven't been
 *              dismissed yet get dismissed_at = now(). Removes them from
 *              the active board view but keeps the row for history.
 *      Why:    after a structure event resolves, operators don't want to
 *              keep manually clicking dismiss on every elapsed row.
 *              Doesn't apply to under_attack (stage 1 handles those).
 *
 *   3. Prune long-dismissed rows
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
 * Scheduled every 5 minutes via ScheduleSeeder (was daily before the
 * under_attack fast-path landed; the under_attack 15-min auto-dismiss
 * requires sub-daily cadence to feel responsive). Manual entry point is
 * structure-manager:prune-structure-board-timers. Stage 3's retention
 * delete is cheap (bulk WHERE + DELETE; no-op when no rows match) so
 * running it every 5 minutes is wasteless.
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
        // Stage 1: FAST-DISMISS elapsed transient-event rows (no grace hours)
        // ============================================================
        //
        // Some event types have NO real future deadline in CCP's data — they're
        // "happening right now" signals where we set a synthetic eve_time at
        // notification time + a short window. Once that window elapses, the
        // event is over (whether the cycle completed or failed) and the row
        // is stale. Fast-dismiss them immediately on the next prune tick
        // instead of waiting for the standard auto-dismiss grace.
        //
        //   under_attack          — CCP's auto-repair window (15 min after the
        //                           last StructureUnderAttack notification)
        //   entosis_in_progress   — single-cycle entosis duration (~60 min
        //                           covers 1-2 cycles including interrupts)
        //
        // Eloquent iteration (not bulk update) so TimerObserver fires
        // structure_manager.timer.dismissed events for cross-plugin
        // subscribers — same observer semantics as the longer-grace stage 2.
        $fastDismissed = 0;
        Timer::query()
            ->whereNull('dismissed_at')
            ->whereIn('event_type', ['under_attack', 'entosis_in_progress'])
            ->where('eve_time', '<', $now)
            ->chunkById(200, function ($timers) use ($now, &$fastDismissed) {
                foreach ($timers as $timer) {
                    $timer->dismissed_at = $now;
                    $timer->save();
                    $fastDismissed++;
                }
            });
        // Maintain the legacy variable name for the log line to avoid
        // breaking grep patterns operators may have built around it.
        $underAttackDismissed = $fastDismissed;

        // ============================================================
        // Stage 2: auto-dismiss other elapsed timers older than N hours
        // ============================================================
        $autodismissed = 0;
        if ($autodismissHours > 0) {
            $autodismissCutoff = $now->copy()->subHours($autodismissHours);

            // Eloquent update to fire the observer (which fires
            // structure_manager.timer.dismissed events for subscribers).
            // Do this in chunks to avoid memory bloat on huge backlogs.
            //
            // Excludes the fast-dismiss event types (under_attack,
            // entosis_in_progress) — stage 1 above handles those on a
            // tighter (no-grace) cadence. Without this exclusion they'd
            // re-iterate here uselessly.
            Timer::query()
                ->whereNull('dismissed_at')
                ->whereNotIn('event_type', ['under_attack', 'entosis_in_progress'])
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
        // Stage 3: prune dismissed timers older than N days
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
            "PruneStructureBoardTimers: fast-dismissed {$fastDismissed} elapsed transient-event timer(s) " .
            "(under_attack / entosis_in_progress), " .
            "auto-dismissed {$autodismissed} other elapsed timer(s) (>{$autodismissHours}h past eve_time), " .
            "permanently deleted {$pruned} dismissed timer(s) (>{$retentionDays}d since dismissal)"
        );
    }
}
