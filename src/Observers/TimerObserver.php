<?php

namespace StructureManager\Observers;

use StructureManager\Models\Timer;
use StructureManager\Services\TimerEventPublisher;

/**
 * Eloquent observer for the Timer model — fires Family B lifecycle events
 * (`structure_manager.timer.created` / `.updated` / `.dismissed`) on the
 * appropriate row transitions.
 *
 * Why an observer instead of explicit publish calls at every site?
 *   - Timer rows are created/updated/dismissed from many places: Timer::upsertAuto
 *     (called from NotifyUpwellLowFuel, NotifyPosLowFuel, StructureEventHandler),
 *     StructureBoardController (manual op create / dismiss / undismiss / destroy),
 *     and any future code path. The observer covers all of them automatically.
 *   - Detection logic is in one place.
 *
 * Caveat: observer fires on EVERY save. We use isDirty / wasChanged to detect
 * meaningful transitions and skip no-op saves (e.g. updated_at refreshes).
 *
 * Lifecycle events that DON'T fire from this observer:
 *   - elapsed / upcoming_24h / upcoming_1h — fired from PublishTimerScheduleEvents
 *     (schedule-driven, not transition-driven)
 *   - recovered — fired from NotifyUpwellLowFuel's fuel-recovery transition
 *     (fuel-recovery is a domain concept, not a generic Timer transition; the
 *     observer fires .dismissed on the auto-soft-dismiss instead)
 *
 * @see TimerEventPublisher
 * @see project_plugin_integration_contracts.md (memory) for Family B contract
 */
class TimerObserver
{
    /**
     * Fired after a Timer row is INSERTED. Always emits timer.created unless
     * the row is being inserted in a pre-dismissed state (rare — would be a
     * factory writing historical rows; not a real lifecycle event).
     */
    public function created(Timer $timer): void
    {
        if ($timer->dismissed_at !== null) {
            // Row inserted already dismissed (unusual) — don't fire .created
            // because subscribers would immediately also see .dismissed if we
            // bothered to fire it. Treat as a silent backfill.
            return;
        }
        TimerEventPublisher::publish('created', $timer);
    }

    /**
     * Fired after a Timer row is UPDATED. Branches on what changed:
     *   - dismissed_at null → non-null   → fires timer.dismissed
     *   - dismissed_at non-null → null   → fires timer.created (re-armed; rare)
     *   - severity / event_type / eve_time changed → fires timer.updated
     *   - everything else (notes, metadata refresh, etc.) → silent
     *
     * Only one event flavor fires per save — dismissal takes precedence over
     * update because subscribers care more about "this is gone" than about
     * "this changed in some way" for the same transition.
     */
    public function updated(Timer $timer): void
    {
        // Dismissal: null → non-null
        if (
            $timer->wasChanged('dismissed_at')
            && $timer->dismissed_at !== null
            && $timer->getOriginal('dismissed_at') === null
        ) {
            TimerEventPublisher::publish('dismissed', $timer, [
                // Reason for dismissal isn't on the row — controllers / job
                // call sites set this conceptually. For now, leave it absent
                // and let subscribers infer from the timer's source/eve_time
                // (eve_time in past = expired, eve_time in future = recovered/manual).
            ]);
            return;
        }

        // Un-dismissal (rare — admin clearing a dismissal flag, or
        // upsertAuto re-arming a row when source_reference matches and
        // dismissed_at => null is in the upsert payload). Treat as a fresh
        // creation event so subscribers re-add it to their calendars.
        if (
            $timer->wasChanged('dismissed_at')
            && $timer->dismissed_at === null
            && $timer->getOriginal('dismissed_at') !== null
        ) {
            TimerEventPublisher::publish('created', $timer);
            return;
        }

        // Meaningful state transition — severity escalation (warning →
        // critical), event_type change, or eve_time shifted (e.g. structure
        // refueled, timer pushed back). Subscribers re-render the row.
        if ($timer->wasChanged(['severity', 'event_type', 'eve_time'])) {
            TimerEventPublisher::publish('updated', $timer, [
                'previous_severity'   => $timer->getOriginal('severity'),
                'previous_event_type' => $timer->getOriginal('event_type'),
                'previous_eve_time'   => $timer->getOriginal('eve_time')
                    ? \Carbon\Carbon::parse($timer->getOriginal('eve_time'))->toIso8601String()
                    : null,
            ]);
            return;
        }

        // Anything else — note edits, metadata refresh, scheduler latches
        // (emitted_upcoming_*_at columns) — is silent. Subscribers don't
        // need to know.
    }
}
