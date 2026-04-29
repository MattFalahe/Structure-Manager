<?php

namespace StructureManager\Helpers;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Builds a contract-conforming `structure.alert.*` event payload.
 *
 * Every `structure.alert.*` event published by Structure Manager flows through
 * this helper so subscribers see a uniform schema. Defined fields:
 *
 *   - source_plugin       string   always 'structure-manager'
 *   - schema_version      int      always 1 (bump when payload shape changes)
 *   - event_id            string   unique idempotency key ('sm-evt-{uuid}')
 *   - event_type          string   e.g. 'fuel_critical', 'shield_reinforced'
 *   - severity            string   'info' | 'warning' | 'critical'
 *   - category_group      string   'fuel' | 'tactical' | 'lifecycle'
 *   - corporation_id      ?int     null = global visibility
 *   - role_id             ?int     null = no role gate (currently always null
 *                                  for alert.* — reserved for future Family B
 *                                  timer.* events)
 *   - structure_id        ?int
 *   - structure_name      ?string
 *   - structure_type      ?string  typeName like 'Astrahus'
 *   - structure_type_id   ?int
 *   - system_id           ?int
 *   - system_name         ?string
 *   - system_security     ?float
 *   - owner_corporation_name    ?string
 *   - attacker_corporation_name ?string
 *   - eve_time            ?string  ISO 8601, when the event "happens"
 *                                  (fuel_expires for fuel_critical;
 *                                   state_timer_end for *_reinforced;
 *                                   destroyed_at for destroyed)
 *   - seconds_until       ?int     positive = in the future, negative = past
 *   - is_elapsed          ?bool    eve_time has already passed
 *   - notes               ?string
 *   - source_reference    ?string  stable dedup key (e.g. 'fuel:{structure_id}')
 *   - url                 string   deeplink to SM Structure Board
 *
 * Callers pass a `$context` array with whatever fields they have; this helper
 * fills in the contract scaffold with sensible defaults and auto-generates
 * the derived fields (event_id, eve_time/seconds_until/is_elapsed, url).
 *
 * Flavor-specific extras (timer_ends_at, attacker_summary, days_remaining,
 * fuel_expires, hourly_rate, destroyed_at, detection_source, killmail_url,
 * final_timer_result, notification_id, etc) are passed through verbatim so
 * existing subscribers (Mining Manager) keep reading what they already read.
 *
 * @see project_plugin_integration_contracts.md (memory) for the full contract
 */
final class AlertEventEnvelope
{
    public const SCHEMA_VERSION = 1;
    public const SOURCE_PLUGIN  = 'structure-manager';

    /**
     * Map event type to its category group. Subscribers can route on the group
     * without enumerating every event_type — e.g. "all fuel events" or "all
     * tactical events".
     */
    private const CATEGORY_GROUPS = [
        'fuel_critical'      => 'fuel',
        'fuel_recovered'     => 'fuel',
        'shield_reinforced'  => 'tactical',
        'armor_reinforced'   => 'tactical',
        'hull_reinforced'    => 'tactical',
        'destroyed'          => 'tactical',
    ];

    /**
     * Build a contract-conforming payload for `structure.alert.{$eventType}`.
     *
     * @param string $eventType  flavor name (without the 'structure.alert.' prefix)
     * @param array  $context    caller-supplied fields. May include any of the
     *                           contract fields plus arbitrary flavor-specific
     *                           extras (which are passed through to subscribers).
     *
     * @return array contract-conforming payload, ready to pass to
     *               EventBus::publish()
     */
    public static function build(string $eventType, array $context = []): array
    {
        // Normalize eve_time → ISO + derive seconds_until / is_elapsed
        [$eveTimeIso, $secondsUntil, $isElapsed] = self::computeTiming($context['eve_time'] ?? null);

        // Build deeplink to the Structure Board, scoped to the affected structure
        // when we have one. The board view ignores unknown query params today,
        // but the URL serves both as a permalink and as a hint for subscribers.
        $structureId = $context['structure_id'] ?? null;
        $url = self::buildBoardUrl($structureId);

        // Contract scaffold — all nullable defaults. Caller's context overlays.
        $base = [
            // contract base
            'source_plugin'     => self::SOURCE_PLUGIN,
            'schema_version'    => self::SCHEMA_VERSION,
            'event_id'          => 'sm-evt-' . (string) Str::uuid(),
            'event_type'        => $eventType,
            'severity'          => 'warning',
            'category_group'    => self::CATEGORY_GROUPS[$eventType] ?? 'tactical',

            // visibility
            'corporation_id'    => null,
            'role_id'           => null,

            // structure context
            'structure_id'      => null,
            'structure_name'    => null,
            'structure_type'    => null,
            'structure_type_id' => null,
            'system_id'         => null,
            'system_name'       => null,
            'system_security'   => null,

            // parties
            'owner_corporation_name'    => null,
            'attacker_corporation_name' => null,

            // timing (overwritten below from computed values)
            'eve_time'      => null,
            'seconds_until' => null,
            'is_elapsed'    => null,

            // admin
            'notes'            => null,
            'source_reference' => null,
            'url'              => $url,
        ];

        // Caller's keys take precedence for the value-bearing fields. This means
        // legacy keys MM already reads (timer_ends_at, attacker_summary, severity,
        // notification_id, etc.) survive verbatim.
        $merged = array_merge($base, $context);

        // Pinned fields — schema invariants we never want a caller to override:
        $merged['source_plugin']  = self::SOURCE_PLUGIN;
        $merged['schema_version'] = self::SCHEMA_VERSION;
        $merged['event_type']     = $eventType;
        $merged['category_group'] = self::CATEGORY_GROUPS[$eventType] ?? 'tactical';
        $merged['url']            = $url;

        // Auto-computed timing — override caller (caller may pass a Carbon object;
        // we always emit ISO string + derived numeric fields).
        $merged['eve_time']      = $eveTimeIso;
        $merged['seconds_until'] = $secondsUntil;
        $merged['is_elapsed']    = $isElapsed;

        // event_id is auto-generated unless the caller explicitly supplies one
        // (rare — only useful if a publisher needs to replay the same logical
        // event with the same idempotency key, e.g. from a backfill script).
        if (empty($context['event_id'])) {
            $merged['event_id'] = 'sm-evt-' . (string) Str::uuid();
        }

        // Backfill structure_type_id from the legacy `type_id` key when the
        // caller passed only the legacy form. Both keys end up in the payload
        // so neither old nor new readers break.
        if ($merged['structure_type_id'] === null && isset($context['type_id'])) {
            $merged['structure_type_id'] = (int) $context['type_id'];
        }

        return $merged;
    }

    /**
     * Compute eve_time / seconds_until / is_elapsed from a raw timestamp input.
     * Accepts a Carbon instance, an ISO string, or null.
     *
     * @return array{0:?string,1:?int,2:?bool}  [iso, seconds_until, is_elapsed]
     */
    private static function computeTiming($raw): array
    {
        if (empty($raw)) {
            return [null, null, null];
        }
        try {
            $carbon = $raw instanceof CarbonInterface ? $raw : Carbon::parse((string) $raw);
            $iso = $carbon->toIso8601String();
            // diffInSeconds(now(), false) is negative when $carbon is in the
            // future (now() is earlier). We want seconds_until positive in
            // that case, so negate.
            $secs = -1 * (int) $carbon->diffInSeconds(Carbon::now(), false);
            return [$iso, $secs, $secs <= 0];
        } catch (\Throwable $e) {
            return [null, null, null];
        }
    }

    /**
     * Build a deeplink to the Structure Board, scoped to a single structure
     * when one is given. Falls back to the index URL when no structure context.
     *
     * Uses the Laravel `url()` helper to produce an absolute URL — subscribers
     * (Discord webhooks, Pings) need full URLs they can render as links.
     */
    private static function buildBoardUrl(?int $structureId): string
    {
        try {
            $path = '/structure-manager/command-board';
            if ($structureId) {
                $path .= '?structure_id=' . $structureId;
            }
            return url($path);
        } catch (\Throwable $e) {
            return ($structureId !== null)
                ? "/structure-manager/command-board?structure_id={$structureId}"
                : '/structure-manager/command-board';
        }
    }
}
