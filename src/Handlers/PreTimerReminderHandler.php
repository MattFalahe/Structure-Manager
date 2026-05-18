<?php

namespace StructureManager\Handlers;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use StructureManager\Models\StructureManagerSettings;
use StructureManager\Models\WebhookConfiguration;
use StructureManager\Services\WebhookDispatcher;

/**
 * Pre-timer reminder dispatcher.
 *
 * Subscribes to Manager Core's EventBus for `structure_manager.timer.upcoming_*`
 * events and translates them into Discord reminder pings ~24h / 6h / 1h before
 * a structure timer expires. Lets fleet leadership organize doctrine + ammo
 * + roster on a sane schedule instead of scrambling at the last minute.
 *
 * REQUIRES Manager Core — without MC's EventBus, scheduled timer.* events
 * never fire and this handler is never reached. SM's standalone path
 * (SeAT native sweep) covers under-attack alerts but not the scheduled
 * reminders.
 *
 * PER-EVENT-TYPE ROUTING:
 *   Each supported event_type maps to a DISTINCT notification category, so
 *   admins can route armor reminders → #defense-fleet @StructureFC, sov
 *   reminders → #sov-fleet @SovFC, hull reminders → #all-hands @everyone,
 *   etc. — all via the existing Notifications panel infrastructure.
 *
 *   Map:
 *     reinforce_armor       → events.pre_timer_armor
 *     reinforce_hull        → events.pre_timer_hull
 *     sov_reinforced        → events.pre_timer_sov
 *     command_node_spawned  → events.pre_timer_nodes
 *     hostile_op            → events.pre_timer_hostile
 *     defense_op            → events.pre_timer_defense
 *
 *   Each category has its own master toggle (set on the Notifications
 *   panel), its own default role mention, its own webhook bindings, and
 *   per-binding role-mention overrides — same machinery as the alert-side
 *   categories (structure_attack, sovereignty, etc.).
 *
 * Excluded event types (intentional — no category map entry):
 *   - reinforce_shield     — already fires immediately via under-attack alert
 *   - destroyed            — post-event, reminder makes no sense
 *   - entosis_in_progress  — happening NOW (40-min window), too short for plan
 *   - fuel_*               — separate routing (fuel category), different audience
 *   - anchor_* / unanchor_* — lifecycle events, not combat
 *   - ownership_transferred — admin event, not combat
 *
 * Subscription pattern (registered from boot via ManagerCoreIntegration):
 *   EventBus::subscribeHandler(
 *       'structure-manager',
 *       'structure_manager.timer.upcoming_24h',
 *       PreTimerReminderHandler::class,
 *       'handle'
 *   )
 *   (and same for upcoming_6h, upcoming_1h)
 *
 * Handler contract: handle(string $eventName, string $publisher, array $payload).
 * Payload shape comes from TimerEventEnvelope::build — see that class for the
 * field-by-field contract.
 */
class PreTimerReminderHandler
{
    /**
     * Maps a Timer event_type to its dedicated notification category_key
     * (under the 'events' namespace). The keys here are the source-of-truth
     * for what event types CAN fire reminders. Anything else falls through
     * the dispatcher's "skip cleanly" branch.
     *
     * Combat events ship with their categories enabled=true by default
     * (migration 000031); manual op events ship enabled=false (opt-in).
     */
    public const EVENT_TYPE_CATEGORY_MAP = [
        'reinforce_armor'      => 'pre_timer_armor',
        'reinforce_hull'       => 'pre_timer_hull',
        'sov_reinforced'       => 'pre_timer_sov',
        'command_node_spawned' => 'pre_timer_nodes',
        'hostile_op'           => 'pre_timer_hostile',
        'defense_op'           => 'pre_timer_defense',
    ];

    /**
     * EventBus subscription endpoint. Called for each fired
     * `structure_manager.timer.upcoming_24h|6h|1h` event the subscriber
     * is bound to.
     *
     * @param string $eventName  e.g. 'structure_manager.timer.upcoming_6h'
     * @param string $publisher  always 'structure-manager' (we publish our own events)
     * @param array  $payload    TimerEventEnvelope-built payload
     */
    public static function handle(string $eventName, string $publisher, array $payload): void
    {
        $instance = new self();
        $instance->dispatch($eventName, $payload);
    }

    /**
     * Internal dispatch — gated by master toggle + event-type allowlist + bindings.
     */
    private function dispatch(string $eventName, array $payload): void
    {
        // Master toggle. Operators who don't want any pre-timer reminders
        // can set this to false in SM Settings without unsubscribing or
        // dropping the bindings. Default true: out of the box, MC-equipped
        // installs get reminders for combat timers.
        $enabled = StructureManagerSettings::get('pre_timer_reminders_enabled', true);
        if (!filter_var($enabled, FILTER_VALIDATE_BOOLEAN)) {
            Log::debug("PreTimerReminderHandler: master toggle OFF, skipping {$eventName}");
            return;
        }

        // Defensive: the lifecycle_action field tells us which window
        // (upcoming_24h / upcoming_6h / upcoming_1h). Fall back to deriving
        // from the event name suffix if the payload didn't carry it.
        $window = $payload['lifecycle_action'] ?? null;
        if (!$window && preg_match('/\.(upcoming_\w+)$/', $eventName, $m)) {
            $window = $m[1];
        }
        if (!in_array($window, ['upcoming_24h', 'upcoming_6h', 'upcoming_1h'], true)) {
            // Not one of our three target windows. Other timer events
            // (created/updated/dismissed/elapsed/recovered) shouldn't reach
            // this handler given our subscription patterns — but defensive
            // bail keeps a future mis-subscription from spamming Discord.
            return;
        }

        // Map event_type → category_key. Anything not in the map is silently
        // skipped (fuel_warning, anchor_start, etc. fire timer.upcoming_* events
        // too, but no reminder pipeline exists for them).
        $eventType = (string) ($payload['event_type'] ?? '');
        $categoryKey = self::EVENT_TYPE_CATEGORY_MAP[$eventType] ?? null;
        if ($categoryKey === null) {
            return;
        }

        // Visibility — resolve through the existing WebhookDispatcher so the
        // role-mention precedence chain (pivot override → category default →
        // webhook legacy) matches the rest of SM exactly. If the category is
        // disabled (operator switched it off on Notifications panel), the
        // dispatcher returns an empty array and we exit cleanly.
        $corporationId = isset($payload['corporation_id']) && $payload['corporation_id']
            ? (int) $payload['corporation_id']
            : null;

        $bindings = WebhookDispatcher::resolveBindings(
            'events',
            $categoryKey,
            $corporationId
        );

        if (empty($bindings)) {
            Log::debug(sprintf(
                'PreTimerReminderHandler: no bindings for events.%s / corp %s — skipping %s',
                $categoryKey,
                $corporationId ?? 'global',
                $window
            ));
            return;
        }

        $embed = $this->buildEmbed($window, $payload);
        if ($embed === null) {
            return;
        }

        foreach ($bindings as $binding) {
            if (!WebhookConfiguration::isValidWebhookUrl($binding['webhook_url'])) {
                continue;
            }

            $finalPayload = $this->injectMention($embed, $binding['role_mention'] ?? '');

            // v2.0.0 — route through WebhookDeliveryService for telemetry
            \StructureManager\Services\WebhookDeliveryService::sendByUrl(
                $binding['webhook_url'],
                $finalPayload,
                'events.pre_timer_' . $window,
                "Pre-timer reminder ({$window}): {$eventType} — timer #" . ($payload['timer_id'] ?? 0)
            );
        }

        Log::info(sprintf(
            'PreTimerReminderHandler: dispatched %s reminder for timer #%d (%s @ %s) via category %s to %d webhook(s)',
            $window,
            $payload['timer_id'] ?? 0,
            $eventType,
            $payload['structure_name'] ?? 'unknown structure',
            $categoryKey,
            count($bindings)
        ));
    }

    /**
     * Build the Discord embed for this reminder window. Returns a full
     * webhook payload array ({content, embeds, allowed_mentions}) ready
     * to POST. Returns null if the payload is incomplete to a degree that
     * we'd rather skip than send a useless embed.
     */
    private function buildEmbed(string $window, array $payload): ?array
    {
        // Window-specific copy + color.
        // Color choices match the visual ramp the rest of SM uses:
        //   T-24h amber       — heads-up / planning
        //   T-6h  orange      — preparation
        //   T-1h  red         — pre-fleet ping
        [$windowLabel, $color, $emoji] = match ($window) {
            'upcoming_24h' => ['24 hours', 0xf59e0b, "\u{1F4C5}"], // calendar
            'upcoming_6h'  => ['6 hours',  0xea580c, "\u{23F3}"],  // hourglass
            'upcoming_1h'  => ['1 hour',   0xdc2626, "\u{1F6A8}"], // siren
            default        => ['soon',     0x6b7280, "\u{1F514}"], // bell
        };

        $eventType   = (string) ($payload['event_type'] ?? '');
        $eventLabel  = $this->eventTypeLabel($eventType);
        $structName  = $payload['structure_name'] ?? 'Unknown Structure';
        $systemName  = $payload['system_name'] ?? 'Unknown System';
        $ownerName   = $payload['owner_corporation_name'] ?? null;
        $attackerName = $payload['attacker_corporation_name'] ?? null;
        $eveTimeIso  = $payload['eve_time'] ?? null;
        $url         = $payload['url'] ?? null;
        $typeId      = $payload['structure_type_id'] ?? null;

        // Final-timer detection — when the structure type has no hull
        // reinforce break (medium Upwell, FLEX, Metenox, Skyhook), the
        // armor cycle IS the final defense window. FCs reading the
        // reminder need to know "this is your only shot" vs "there's a
        // hull timer if armor falls". Resolved via the same helper the
        // primary alert uses, so messaging stays consistent.
        $finalTimerBadge = \StructureManager\Helpers\StructureTimerMechanics::finalTimerBadge(
            $typeId !== null ? (int) $typeId : null,
            $eventType
        );
        $finalTimerMessage = \StructureManager\Helpers\StructureTimerMechanics::finalTimerMessage(
            $typeId !== null ? (int) $typeId : null,
            $eventType
        );

        // Compute a human "in 5h 58m" relative-time string from eve_time so
        // the embed reads naturally regardless of timezone — Discord will
        // also auto-format absolute timestamps but that requires the user
        // to have set their locale. Belt + suspenders.
        $eveTime = null;
        $absoluteText = null;
        if ($eveTimeIso) {
            try {
                $eveTime = Carbon::parse($eveTimeIso);
                // Discord <t:UNIX:F> = full date+time in viewer's locale,
                // <t:UNIX:R> = relative ("in 6 hours"). Use both so admins
                // in any TZ can read the timer.
                $absoluteText = '<t:' . $eveTime->timestamp . ':F> (<t:' . $eveTime->timestamp . ':R>)';
            } catch (\Throwable $e) {
                // ignore — render without timer line
            }
        }

        // Severity / threat hint based on event_type. Pulled from Timer
        // model's severity field if available; otherwise inferred.
        $severity = $payload['severity'] ?? 'warning';

        // Resolve structure type name for display. We have it on the payload
        // as structure_type (denormalized at timer-create time); fall back
        // to a TypeIdRegistry lookup if only the type_id is present.
        $structureTypeName = $payload['structure_type'] ?? null;
        if (!$structureTypeName && $typeId !== null) {
            $upwellMeta = \StructureManager\Helpers\TypeIdRegistry::UPWELL_TYPE_IDS[(int) $typeId] ?? null;
            if ($upwellMeta) {
                $structureTypeName = $upwellMeta['name'];
            } else {
                $posMeta = \StructureManager\Helpers\TypeIdRegistry::posTower((int) $typeId);
                if ($posMeta) {
                    $structureTypeName = $posMeta['name'];
                }
            }
        }

        $fields = [];
        $fields[] = ['name' => "\u{1F4CD} Location", 'value' => $systemName, 'inline' => true];
        $fields[] = ['name' => 'Event Type',         'value' => $eventLabel,  'inline' => true];
        // Target type — Keepstar / Fortizar / Athanor / etc. — so fleet
        // leadership knows fitting + composition at a glance without
        // hunting through the structure name field.
        if ($structureTypeName) {
            $fields[] = ['name' => "\u{1F3F0} Target Type", 'value' => $structureTypeName, 'inline' => true];
        }

        // FINAL TIMER warning — placed high in the embed (right after the
        // basic context) so it's seen even when the embed is truncated on
        // mobile. Full-width to match urgency.
        if ($finalTimerMessage !== null) {
            $fields[] = [
                'name'   => "\u{1F6A8} FINAL TIMER",
                'value'  => $finalTimerMessage,
                'inline' => false,
            ];
        }

        if ($absoluteText) {
            $fields[] = ['name' => "\u{23F0} Timer Ends", 'value' => $absoluteText, 'inline' => false];
        }
        if ($ownerName) {
            $fields[] = ['name' => 'Owner', 'value' => $ownerName, 'inline' => true];
        }
        if ($attackerName) {
            $secondaryLabel = $this->secondaryPartyLabel($eventType);
            $fields[] = ['name' => $secondaryLabel, 'value' => $attackerName, 'inline' => true];
        }

        // Severity badge (small visual cue).
        $sevLabel = match ($severity) {
            'critical' => "\u{1F534} Critical",
            'warning'  => "\u{1F7E1} Warning",
            default    => "\u{1F535} Info",
        };
        $fields[] = ['name' => 'Severity', 'value' => $sevLabel, 'inline' => true];

        // Tags as inline list (operators can tag timers like 'doctrine-armor',
        // 'high-priority', etc. — show them so the fleet knows which fitting).
        if (!empty($payload['tags']) && is_array($payload['tags'])) {
            $tagLine = implode(' ', array_map(fn ($t) => "`{$t}`", $payload['tags']));
            $fields[] = ['name' => 'Tags', 'value' => $tagLine, 'inline' => false];
        }

        // Notes block — operators sometimes add fleet-meta to timers ("bring
        // capitals", "no logi"). Pass through verbatim when present.
        if (!empty($payload['notes'])) {
            $notes = mb_substr((string) $payload['notes'], 0, 600); // cap to keep embed under Discord's 6000 total
            $fields[] = ['name' => "\u{1F4DD} Notes", 'value' => $notes, 'inline' => false];
        }

        // Add FINAL TIMER suffix to the title when applicable, so even the
        // Discord notification preview (which shows the embed title for
        // some clients) carries the stakes.
        $titleFinalSuffix = $finalTimerBadge !== null ? " \u{2014} \u{26A0} {$finalTimerBadge}" : '';
        $title = sprintf(
            '%s %s reminder — %s%s',
            $emoji,
            strtoupper($windowLabel),
            $structName,
            $titleFinalSuffix
        );

        $embed = [
            'title'  => $title,
            'color'  => $color,
            'fields' => $fields,
            'footer' => [
                'text' => sprintf(
                    'Structure Manager pre-timer reminder | %s window | timer #%d | events.%s',
                    $windowLabel,
                    $payload['timer_id'] ?? 0,
                    self::EVENT_TYPE_CATEGORY_MAP[$eventType] ?? 'unknown'
                ),
            ],
            'timestamp' => Carbon::now()->toIso8601String(),
        ];

        if ($typeId) {
            $embed['thumbnail'] = ['url' => "https://images.evetech.net/types/{$typeId}/render?size=64"];
        }

        if ($url) {
            $embed['url'] = $url;
        }

        // Compose the outer payload. content stays empty here — the role
        // mention is injected in injectMention() before POST. allowed_mentions
        // is set conservatively so we never accidentally @everyone.
        return [
            'content' => '',
            'embeds'  => [$embed],
            'allowed_mentions' => [
                'parse' => [],
                'users' => [],
                'roles' => [],
            ],
        ];
    }

    /**
     * Inject the role mention into the webhook content (with allowed_mentions
     * scoped to that specific role). Delegates parsing to WebhookDispatcher
     * so the precedence + format-validation matches the rest of SM exactly.
     */
    private function injectMention(array $payload, ?string $mention): array
    {
        [$prefix, $allowedMentions] = WebhookDispatcher::formatMention($mention);
        if ($prefix !== '') {
            $payload['content'] = trim($prefix . ($payload['content'] ?? ''));
        }
        $payload['allowed_mentions'] = $allowedMentions;
        return $payload;
    }

    /**
     * Human label for a board event_type. Keeps reminders readable instead
     * of dumping the raw underscore-key. Falls back to a title-cased version
     * for unknown types so future event_types still render sensibly.
     */
    private function eventTypeLabel(string $eventType): string
    {
        return match ($eventType) {
            'reinforce_armor'      => 'Armor Reinforced',
            'reinforce_hull'       => 'Hull Reinforced',
            'sov_reinforced'       => 'Sovereignty Reinforced',
            'command_node_spawned' => 'Command Nodes Spawning',
            'hostile_op'           => 'Hostile Op',
            'defense_op'           => 'Defense Op',
            default                => ucwords(str_replace('_', ' ', $eventType)),
        };
    }

    /**
     * What does the "attacker_corporation_name" field mean for this event_type?
     * Picks a sensible label so the embed reads naturally. (Same logic as
     * Timer::getSecondaryPartyLabelAttribute() but kept here so the handler
     * works from a flat payload without needing the model.)
     */
    private function secondaryPartyLabel(string $eventType): string
    {
        return match (true) {
            $eventType === 'hostile_op' || $eventType === 'defense_op' => 'Opponent',
            str_starts_with($eventType, 'reinforce_'),
            $eventType === 'sov_reinforced',
            $eventType === 'command_node_spawned'    => 'Attacker',
            default                                  => 'Other Party',
        };
    }
}
