<?php

namespace StructureManager\Services;

use Illuminate\Support\Facades\Log;
use StructureManager\Helpers\TimerEventEnvelope;
use StructureManager\Models\Timer;

/**
 * Publishes Family B `structure_manager.timer.*` events through Manager
 * Core's EventBus. Standalone-safe — no-op when MC is not installed.
 *
 * Used from:
 *   - TimerObserver           — fires created / updated / dismissed on Timer
 *                               lifecycle transitions
 *   - PublishTimerScheduleEvents — fires elapsed / upcoming_24h / upcoming_1h
 *                                  on the schedule cadence
 *   - NotifyUpwellLowFuel       — fires recovered when fuel transitions back
 *                                  to good
 *
 * The publisher is the SINGLE point where SM touches the EventBus for
 * Family B events — keeps the class_exists guard in one place and gives
 * us a uniform log line for every emission.
 */
class TimerEventPublisher
{
    /**
     * Publish a `structure_manager.timer.{$action}` event for the given Timer.
     *
     * No-op when Manager Core is not installed. Errors during publish are
     * caught + logged at warning so a single failed publish doesn't poison
     * the calling job / observer.
     *
     * @param string $action  one of: created, updated, dismissed, elapsed,
     *                        upcoming_24h, upcoming_1h, recovered
     * @param Timer  $timer   the Timer row this event is about
     * @param array  $extras  flavor-specific payload fields (passed through
     *                        to TimerEventEnvelope::build)
     */
    public static function publish(string $action, Timer $timer, array $extras = []): bool
    {
        if (!class_exists('\\ManagerCore\\Services\\EventBus')) {
            return false;
        }

        $eventName = 'structure_manager.timer.' . $action;
        $payload   = TimerEventEnvelope::build($action, $timer, $extras);

        try {
            // C4: publishSanitized auto-escapes Discord-bound text fields
            // (timer notes/tags can contain operator-supplied content).
            app(\ManagerCore\Services\EventBus::class)->publishSanitized(
                $eventName,
                'structure-manager',
                $payload
            );
            Log::info(
                "TimerEventPublisher: published {$eventName} for timer {$timer->id} " .
                "(event_id={$payload['event_id']}, event_type={$timer->event_type}, severity={$timer->severity})"
            );
            return true;
        } catch (\Throwable $e) {
            Log::warning("TimerEventPublisher: failed to publish {$eventName} for timer {$timer->id}: " . $e->getMessage());
            return false;
        }
    }
}
