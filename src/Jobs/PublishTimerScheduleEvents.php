<?php

namespace StructureManager\Jobs;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use StructureManager\Models\Timer;
use StructureManager\Services\TimerEventPublisher;

/**
 * Family B scheduler — emits the four time-window-driven flavors of
 * `structure_manager.timer.*`:
 *   - upcoming_24h  (eve_time within the next 24h)
 *   - upcoming_6h   (eve_time within the next 6h)
 *   - upcoming_1h   (eve_time within the next 1h)
 *   - elapsed       (eve_time has passed)
 *
 * Idempotency is enforced by the emitted_*_at columns on
 * structure_manager_timers (added in migrations 000028 + 000031). Each
 * timer emits each flavor at most once over its lifetime — even if the
 * window remains "open" across many job runs, the latch prevents re-emission.
 *
 * Cadence: scheduled every 5 minutes via ScheduleSeeder. Worst case for
 * upcoming_1h is firing ~55-60 min before eve_time (operator gets between
 * 55 and 60 min of warning instead of exactly 60). Same for upcoming_6h
 * (5h55-6h00 warning) and upcoming_24h (23h55-24h00 warning). Acceptable
 * for ops use.
 *
 * Standalone-safe: no-op when Manager Core is not installed (publisher's
 * class_exists guard). Does NOT set the latches on failed publishes —
 * if MC is later installed, the job will catch up by emitting all
 * currently-in-window timers on its next run.
 */
class PublishTimerScheduleEvents implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Bound below SeAT's queue retry_after default (960s). */
    public $timeout = 60;

    public $tries = 1;

    /**
     * The 24-hour upcoming window — fire when eve_time is within this many
     * seconds of now AND not yet emitted. We use 24h exactly (not 25h or
     * similar buffer) because the latch makes overlap harmless: if the job
     * runs at T-24h05m and skips, the next run at T-23h55m fires.
     */
    private const UPCOMING_24H_WINDOW_SECONDS = 24 * 3600;

    /**
     * The 6-hour upcoming window — added in v3.2 for pre-timer reminders.
     * Fits between 24h (planning channel) and 1h (fleet ping channel) for
     * armor / hull / sov timers that need ~6h to organize fleet response.
     */
    private const UPCOMING_6H_WINDOW_SECONDS = 6 * 3600;

    /** The 1-hour upcoming window. */
    private const UPCOMING_1H_WINDOW_SECONDS = 1 * 3600;

    public function handle(): void
    {
        // Standalone-safety: if MC isn't installed, don't bother iterating.
        // Publisher would no-op anyway; this saves the DB query cost.
        if (!class_exists('\\ManagerCore\\Services\\EventBus')) {
            Log::debug('PublishTimerScheduleEvents: Manager Core not installed; nothing to publish.');
            return;
        }

        $now = Carbon::now();
        $upcoming24hCutoff = $now->copy()->addSeconds(self::UPCOMING_24H_WINDOW_SECONDS);
        $upcoming6hCutoff  = $now->copy()->addSeconds(self::UPCOMING_6H_WINDOW_SECONDS);
        $upcoming1hCutoff  = $now->copy()->addSeconds(self::UPCOMING_1H_WINDOW_SECONDS);

        $stats = [
            'upcoming_24h' => 0,
            'upcoming_6h'  => 0,
            'upcoming_1h'  => 0,
            'elapsed'      => 0,
        ];

        // === upcoming_24h ===
        // Active, not-yet-emitted timers whose eve_time is in the next 24h.
        // (Includes timers ≤ 1h away — they trigger upcoming_24h once and
        // upcoming_6h / upcoming_1h separately.)
        $upcoming24h = Timer::query()
            ->whereNull('dismissed_at')
            ->whereNull('emitted_upcoming_24h_at')
            ->where('eve_time', '>', $now)
            ->where('eve_time', '<=', $upcoming24hCutoff)
            ->get();

        foreach ($upcoming24h as $timer) {
            if (TimerEventPublisher::publish('upcoming_24h', $timer)) {
                $timer->emitted_upcoming_24h_at = $now;
                $timer->save();
                $stats['upcoming_24h']++;
            }
        }

        // === upcoming_6h ===
        // Active, not-yet-emitted timers whose eve_time is in the next 6h.
        // The 6h window is bracketed between 24h and 1h for fleet planning:
        //   - T-24h: "tomorrow at this time, organize fleet"
        //   - T-6h:  "this evening, finalize roster + ammo"
        //   - T-1h:  "ping fleet to login, undock in 30"
        $upcoming6h = Timer::query()
            ->whereNull('dismissed_at')
            ->whereNull('emitted_upcoming_6h_at')
            ->where('eve_time', '>', $now)
            ->where('eve_time', '<=', $upcoming6hCutoff)
            ->get();

        foreach ($upcoming6h as $timer) {
            if (TimerEventPublisher::publish('upcoming_6h', $timer)) {
                $timer->emitted_upcoming_6h_at = $now;
                $timer->save();
                $stats['upcoming_6h']++;
            }
        }

        // === upcoming_1h ===
        $upcoming1h = Timer::query()
            ->whereNull('dismissed_at')
            ->whereNull('emitted_upcoming_1h_at')
            ->where('eve_time', '>', $now)
            ->where('eve_time', '<=', $upcoming1hCutoff)
            ->get();

        foreach ($upcoming1h as $timer) {
            if (TimerEventPublisher::publish('upcoming_1h', $timer)) {
                $timer->emitted_upcoming_1h_at = $now;
                $timer->save();
                $stats['upcoming_1h']++;
            }
        }

        // === elapsed ===
        // Active, not-yet-emitted timers whose eve_time has passed. Note:
        // we DON'T require dismissed_at to be null here — even dismissed
        // timers should fire elapsed once for subscribers tracking history.
        // ...actually we DO scope to active only, because subscribers care
        // about the live calendar. Dismissed timers fired their .dismissed
        // event already; an elapsed event for a dismissed row is noise.
        $elapsed = Timer::query()
            ->whereNull('dismissed_at')
            ->whereNull('emitted_elapsed_at')
            ->where('eve_time', '<=', $now)
            ->get();

        foreach ($elapsed as $timer) {
            if (TimerEventPublisher::publish('elapsed', $timer)) {
                $timer->emitted_elapsed_at = $now;
                $timer->save();
                $stats['elapsed']++;
            }
        }

        Log::info(
            'PublishTimerScheduleEvents: fired ' . $stats['upcoming_24h'] . ' upcoming_24h, ' .
            $stats['upcoming_6h']  . ' upcoming_6h, ' .
            $stats['upcoming_1h']  . ' upcoming_1h, ' .
            $stats['elapsed']      . ' elapsed event(s)'
        );
    }
}
