<?php

namespace StructureManager\Helpers;

use Carbon\Carbon;
use Illuminate\Support\Str;
use StructureManager\Models\Timer;

/**
 * Builds a contract-conforming `structure_manager.timer.*` event payload.
 *
 * Family B of the cross-plugin event surface (`structure.alert.*` is family A).
 * Where alert events describe THREATS happening now (someone is shooting your
 * citadel), timer events describe SCHEDULED future things (this fuel runs out
 * in 4 days, this anchor finishes in 6h). They feed Pings' aggregate Command
 * Board and any subscriber that wants a calendar-style view.
 *
 * EventBus event names (one of these is what the publisher uses):
 *   - structure_manager.timer.created
 *   - structure_manager.timer.updated
 *   - structure_manager.timer.dismissed
 *   - structure_manager.timer.elapsed
 *   - structure_manager.timer.upcoming_24h
 *   - structure_manager.timer.upcoming_1h
 *   - structure_manager.timer.recovered
 *
 * Payload shape:
 *   - source_plugin       string  always 'structure-manager'
 *   - schema_version      int     always 1
 *   - event_id            string  unique idempotency key ('sm-evt-{uuid}')
 *   - lifecycle_action    string  the action that fired this event (created /
 *                                 updated / dismissed / elapsed / upcoming_24h
 *                                 / upcoming_1h / recovered)
 *   - timer_id            int     Timer row's PK — subscribers can correlate
 *                                 multiple events about the same timer
 *   - event_type          string  the Timer's event_type (fuel_warning,
 *                                 reinforce_hull, anchor_complete, etc.)
 *                                 — NOT the lifecycle_action
 *   - severity            string  'info' | 'warning' | 'critical'
 *   - category_group      string  'fuel' | 'tactical' | 'lifecycle'
 *   - corporation_id      ?int    null = global visibility
 *   - role_id             ?int    null = no role gate
 *   - structure_id        ?int
 *   - structure_name      ?string
 *   - structure_type      ?string typeName like 'Astrahus'
 *   - structure_type_id   ?int
 *   - system_id           ?int
 *   - system_name         ?string
 *   - system_security     ?float
 *   - owner_corporation_name    ?string
 *   - attacker_corporation_name ?string
 *   - eve_time            ?string ISO 8601, when the timer's underlying event
 *                                 happens (fuel_expires, reinforce_ends, anchor
 *                                 completes, etc.)
 *   - seconds_until       ?int    positive = in future, negative = past
 *   - is_elapsed          ?bool
 *   - notes               ?string
 *   - source_reference    ?string stable dedup key (e.g. 'fuel:{structure_id}')
 *   - dismissed_at        ?string ISO 8601 if timer is dismissed; null otherwise
 *   - is_manual           bool    true if source starts with 'manual_'
 *   - url                 string  deeplink to Structure Board scoped to this timer
 *
 * Additional flavor-specific fields can be passed via $extras and survive
 * verbatim alongside the contract scaffold.
 *
 * @see project_plugin_integration_contracts.md (memory) for full Family B contract
 */
final class TimerEventEnvelope
{
    public const SCHEMA_VERSION = 1;
    public const SOURCE_PLUGIN  = 'structure-manager';

    /**
     * Build a contract-conforming payload for a `structure_manager.timer.*`
     * event.
     *
     * @param string $lifecycleAction one of: created, updated, dismissed,
     *                                elapsed, upcoming_24h, upcoming_1h, recovered
     * @param Timer  $timer           the Timer row this event is about
     * @param array  $extras          flavor-specific fields to include
     *                                (e.g. 'previous_severity' for an updated
     *                                event, 'dismissal_reason' for a dismissed
     *                                event). Pass-through verbatim.
     *
     * @return array contract-conforming payload, ready to pass to EventBus::publish()
     */
    public static function build(string $lifecycleAction, Timer $timer, array $extras = []): array
    {
        // Compute timing fields from the Timer's eve_time
        $eveTimeIso   = null;
        $secondsUntil = null;
        $isElapsed    = null;
        if ($timer->eve_time !== null) {
            try {
                $eveTimeIso   = $timer->eve_time->toIso8601String();
                // diffInSeconds(now(), false) is negative when eve_time is in
                // the future; we want seconds_until positive in that case.
                $secondsUntil = -1 * (int) $timer->eve_time->diffInSeconds(Carbon::now(), false);
                $isElapsed    = $secondsUntil <= 0;
            } catch (\Throwable $e) {
                // leave as nulls
            }
        }

        // Deeplink to the Structure Board, scoped to this timer's id when possible
        $url = self::buildBoardUrl($timer->id);

        // Severity / category fall back from accessors / EVENT_GROUPS map
        $categoryGroup = $timer->event_type !== null
            ? (Timer::EVENT_GROUPS[$timer->event_type] ?? 'lifecycle')
            : 'lifecycle';

        $isManual = is_string($timer->source) && str_starts_with($timer->source, 'manual_');

        $base = [
            // contract base
            'source_plugin'     => self::SOURCE_PLUGIN,
            'schema_version'    => self::SCHEMA_VERSION,
            'event_id'          => 'sm-evt-' . (string) Str::uuid(),
            'lifecycle_action'  => $lifecycleAction,

            'timer_id'          => $timer->id,
            'event_type'        => $timer->event_type,        // Timer's own type, NOT lifecycle_action
            'severity'          => $timer->severity ?? 'info',
            'category_group'    => $categoryGroup,

            // visibility
            'corporation_id'    => $timer->corporation_id,
            'role_id'           => $timer->role_id,

            // structure context
            'structure_id'      => $timer->structure_id,
            'structure_name'    => $timer->structure_name,
            'structure_type'    => $timer->structure_type,
            'structure_type_id' => $timer->structure_type_id,
            'system_id'         => $timer->system_id,
            'system_name'       => $timer->system_name,
            'system_security'   => $timer->system_security !== null ? (float) $timer->system_security : null,

            // parties
            'owner_corporation_name'    => $timer->owner_corporation_name,
            'attacker_corporation_name' => $timer->attacker_corporation_name,

            // timing
            'eve_time'      => $eveTimeIso,
            'seconds_until' => $secondsUntil,
            'is_elapsed'    => $isElapsed,

            // admin
            'notes'            => $timer->notes,
            'source_reference' => $timer->source_reference,
            'dismissed_at'     => $timer->dismissed_at?->toIso8601String(),
            'is_manual'        => $isManual,
            'url'              => $url,

            // Free-form labels (Polish item — see migration 000029). Subscribers
            // can route on these for custom workflows ("ping the doctrine
            // channel for any timer tagged 'doctrine-armor'", etc.).
            'tags'             => self::extractTags($timer),
        ];

        // Caller's $extras overlay — flavor-specific fields like
        // 'previous_severity' (for updated), 'dismissal_reason' (dismissed),
        // 'recovered_to_status' (recovered), etc. survive verbatim.
        $merged = array_merge($base, $extras);

        // Pinned fields — schema invariants we never want a caller to override
        $merged['source_plugin']    = self::SOURCE_PLUGIN;
        $merged['schema_version']   = self::SCHEMA_VERSION;
        $merged['lifecycle_action'] = $lifecycleAction;
        $merged['timer_id']         = $timer->id;

        // event_id auto-generated unless explicitly supplied (rare — replays only)
        if (empty($extras['event_id'])) {
            $merged['event_id'] = 'sm-evt-' . (string) Str::uuid();
        }

        return $merged;
    }

    /**
     * Extract the timer's tags as a flat string array. Defensively handles
     * the case where the tags relationship hasn't been eager-loaded — falls
     * back to an empty array rather than triggering an N+1 lazy-load on
     * every event publish.
     *
     * @return array<int, string>
     */
    private static function extractTags(Timer $timer): array
    {
        if (!$timer->relationLoaded('tags')) {
            // Caller didn't eager-load tags. Trying to publish without tags
            // is acceptable — they're optional payload metadata. Skip the
            // lazy-load to keep observers fast.
            return [];
        }
        return $timer->tags
            ->pluck('tag')
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Build a deeplink to the Structure Board scoped to a timer when possible.
     */
    private static function buildBoardUrl(?int $timerId): string
    {
        try {
            $path = '/structure-manager/command-board';
            if ($timerId) {
                $path .= '?timer_id=' . $timerId;
            }
            return url($path);
        } catch (\Throwable $e) {
            return $timerId !== null
                ? "/structure-manager/command-board?timer_id={$timerId}"
                : '/structure-manager/command-board';
        }
    }
}
