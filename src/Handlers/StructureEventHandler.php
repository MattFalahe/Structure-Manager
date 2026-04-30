<?php

namespace StructureManager\Handlers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use StructureManager\Helpers\AlertEventEnvelope;
use StructureManager\Jobs\EnrichKillmailJob;
use StructureManager\Models\Timer;
use StructureManager\Models\WebhookConfiguration;
use StructureManager\Services\WebhookDispatcher;
use Carbon\Carbon;

/**
 * Structure Manager's shared notification handler.
 *
 * This is the single place where Structure Manager translates ESI notifications
 * into Discord webhook payloads. It's called from two paths:
 *
 * 1. When Manager Core is installed: MC's EsiNotificationRegistry dispatches
 *    to this handler after its fast-poll inserts a new notification.
 * 2. When Manager Core is NOT installed: Structure Manager's own fallback job
 *    (ProcessStructureNotifications) reads from SeAT's character_notifications
 *    table and calls this handler directly.
 *
 * Contract: static handle($notification): void
 *
 * The $notification parameter is duck-typed — any object with these fields:
 *   - type (string)
 *   - corporation_id (int)
 *   - timestamp (Carbon|string)
 *   - text (string)
 *   - parsed_data (array|null)
 *   - source (string) — 'fast_poll' | 'seat_fallback' | 'seat_native'
 *
 * Both ManagerCore\Models\ESI\EsiNotification and
 * StructureManager\Models\EsiNotification satisfy this contract.
 */
class StructureEventHandler
{
    const ATTACK_TYPES = [
        'StructureUnderAttack',
        'StructureLostShields',
        'StructureLostArmor',
        'StructureDestroyed',
        'SkyhookUnderAttack',
        'SkyhookLostShields',
        'SkyhookDestroyed',
    ];

    const LIFECYCLE_TYPES = [
        'StructureAnchoring',
        'AllAnchoringMsg',
        'StructureUnanchoring',
        'OwnershipTransferred',
        'SkyhookDeployed',
    ];

    const FUEL_EVENT_TYPES = [
        'StructureWentLowPower',
        'StructureWentHighPower',
        'StructureFuelAlert',
        'StructureLowReagentsAlert',
        'StructureNoReagentsAlert',
        'SkyhookOnline',
    ];

    // Services-offline events get their own category (events.services_offline)
    // for routing to industry-team channels. Migration 000025 seeds the
    // category and backfills bindings from events.structure_fuel_events.
    const SERVICES_OFFLINE_TYPES = [
        'StructureServicesOffline',
    ];

    // Sovereignty events get their own category (events.sovereignty) for
    // routing to sov-ops channels. Migration 000025 seeds + backfills.
    const SOVEREIGNTY_TYPES = [
        'EntosisCaptureStarted',
        'SovStructureReinforced',
        'SovStructureDestroyed',
        'SovCommandNodeEventStarted',
    ];

    /**
     * Entry point called by MC's registry OR SM's fallback job.
     */
    public static function handle($notification): void
    {
        $instance = new self();
        $instance->dispatch($notification);
    }

    /**
     * Return all types this handler responds to (used when registering with MC).
     */
    public static function registeredTypes(): array
    {
        return array_merge(
            self::ATTACK_TYPES,
            self::LIFECYCLE_TYPES,
            self::FUEL_EVENT_TYPES,
            self::SERVICES_OFFLINE_TYPES,
            self::SOVEREIGNTY_TYPES
        );
    }

    /**
     * Build the embed and send to configured webhooks for the notification's corporation.
     *
     * Uses WebhookDispatcher to resolve (category, corporation) → bindings with
     * role-mention precedence: pivot override → category default → webhook legacy.
     */
    private function dispatch($notification): void
    {
        $category = $this->getCategory($notification->type);
        $categoryKey = $this->categoryToKey($category);

        if ($categoryKey === null) {
            return;
        }

        // Upsert a Structure Board timer row regardless of webhook bindings —
        // the board should show the event even if no webhook is configured.
        $this->upsertBoardTimer($notification, $category);

        // Publish structure.alert.* event on Manager Core's EventBus for
        // cross-plugin subscribers (Mining Manager subscribes to the wildcard
        // and fires extraction_at_risk for moon-mining refineries). No-op if
        // MC is not installed or this notification type doesn't map to an
        // alert flavor.
        $this->publishStructureAlertEvent($notification);

        $bindings = WebhookDispatcher::resolveBindings('events', $categoryKey, (int) $notification->corporation_id);

        if (empty($bindings)) {
            Log::debug("StructureEventHandler: No bindings for events.{$categoryKey} / corp {$notification->corporation_id}");
            return;
        }

        $payload = $this->buildPayload($notification);

        if ($payload === null) {
            return;
        }

        foreach ($bindings as $binding) {
            if (!WebhookConfiguration::isValidWebhookUrl($binding['webhook_url'])) {
                continue;
            }

            $finalPayload = $this->injectMention($payload, $binding['role_mention'] ?? '', $category);

            try {
                Http::connectTimeout(5)->timeout(10)->post($binding['webhook_url'], $finalPayload);
            } catch (\Throwable $e) {
                Log::error("StructureEventHandler: Webhook failed for notification type {$notification->type}: " . $e->getMessage());
            }
        }
    }

    /**
     * Upsert a Structure Board timer row for this ESI notification.
     *
     * Maps CCP notification types to board event_types:
     *   StructureUnderAttack  → reinforce_shield (timer = notification.timestamp, i.e. now)
     *   StructureLostShields  → reinforce_armor  (timer = state_timer_end from corporation_structures)
     *   StructureLostArmor    → reinforce_hull   (timer = state_timer_end)
     *   StructureDestroyed    → reinforce_hull   (timer = now, severity=critical)
     *   StructureAnchoring    → anchor_start     (timer = now + 24h approx)
     *   StructureUnanchoring  → unanchor_start   (timer = now + 7d approx)
     *   OwnershipTransferred  → ownership_transferred (timer = now)
     *
     * source_reference is keyed on notification_id so each CCP notification
     * produces exactly one row (and re-dispatch is idempotent).
     */
    private function upsertBoardTimer($notification, string $internalCategory): void
    {
        $eventType = $this->notificationTypeToBoardEvent($notification->type);
        if ($eventType === null) {
            return;
        }

        $data = $notification->parsed_data ?? [];
        $meta = $this->resolveStructureMeta($data);

        // Resolve the actual reinforce timer from the corporation_structures
        // row if present. Falls back to the notification timestamp for
        // non-reinforce events or when the structure isn't in our DB.
        $eveTime = $this->resolveEveTimeForEvent($notification, $eventType, $data);

        $severity = match (true) {
            str_starts_with($eventType, 'reinforce_') => 'critical',
            $eventType === 'anchor_start' || $eventType === 'unanchor_start' => 'warning',
            default => 'info',
        };

        // Identify structure_type_id for image rendering
        $structureTypeId = $data['structureShowInfoData'][1] ?? null;

        // Owner corp = our corp for attack events, same for anchor/unanchor
        $ownerName = DB::table('corporation_infos')
            ->where('corporation_id', $notification->corporation_id)
            ->value('name');

        // Attacker corp name — only present on reinforce events
        $attackerName = $data['corpName'] ?? null;

        try {
            Timer::upsertAuto([
                'source'                    => $this->sourceForNotificationType($notification->type),
                'event_type'                => $eventType,
                'severity'                  => $severity,
                'structure_id'              => $data['structureID'] ?? null,
                'structure_name'            => $meta['name'],
                'structure_type'            => $meta['type'],
                'structure_type_id'         => $structureTypeId,
                'system_id'                 => $data['solarsystemID'] ?? null,
                'system_name'               => $meta['system'],
                'corporation_id'            => $notification->corporation_id,
                'owner_corporation_name'    => $ownerName,
                'attacker_corporation_name' => $attackerName,
                'eve_time'                  => $eveTime,
                'source_reference'          => "esi-notif:{$notification->notification_id}",
                'dismissed_at'              => null,
            ]);
        } catch (\Throwable $e) {
            Log::warning("StructureEventHandler: Failed to upsert board timer for notification #{$notification->notification_id}: " . $e->getMessage());
        }
    }

    private function notificationTypeToBoardEvent(string $type): ?string
    {
        return match ($type) {
            'StructureUnderAttack', 'SkyhookUnderAttack'  => 'reinforce_shield',
            'StructureLostShields', 'SkyhookLostShields'  => 'reinforce_armor',
            'StructureLostArmor'                          => 'reinforce_hull',
            'StructureDestroyed', 'SkyhookDestroyed'      => 'reinforce_hull',
            'StructureAnchoring', 'AllAnchoringMsg', 'SkyhookDeployed' => 'anchor_start',
            'StructureUnanchoring'                        => 'unanchor_start',
            'OwnershipTransferred'                        => 'ownership_transferred',
            default                                       => null,
        };
    }

    private function sourceForNotificationType(string $type): string
    {
        return match (true) {
            str_contains($type, 'UnderAttack') || str_contains($type, 'LostShields') || str_contains($type, 'LostArmor') || str_contains($type, 'Destroyed')
                => 'auto_reinforce',
            str_contains($type, 'Anchoring') || $type === 'SkyhookDeployed'
                => 'auto_anchor',
            str_contains($type, 'Unanchoring')
                => 'auto_unanchor',
            default => 'auto_reinforce',
        };
    }

    /**
     * Publish a structure.alert.* event on Manager Core's EventBus when the
     * incoming notification represents an actionable threat. Cross-plugin
     * subscribers (Mining Manager fires extraction_at_risk; future plugins
     * can subscribe similarly) react to these.
     *
     * Mapping (matches the contract in
     * project_structure_manager_destruction_detection.md and the existing
     * NotifyUpwellLowFuel::publishFuelCriticalEvent for fuel_critical):
     *
     *   StructureLostShields / SkyhookLostShields → structure.alert.shield_reinforced
     *   StructureLostArmor                        → structure.alert.armor_reinforced
     *
     * Other reinforce flavors (hull_reinforced, destroyed) ship in later SM
     * work — see project_structure_manager_destruction_detection.md.
     *
     * No-op if Manager Core is not installed (class_exists guard). No filter
     * by structure type — SM publishes broadly so future subscribers can use
     * the signal for their own purposes; subscribers do their own filtering.
     */
    private function publishStructureAlertEvent($notification): void
    {
        $alertFlavor = match ($notification->type) {
            'StructureLostShields', 'SkyhookLostShields' => 'shield_reinforced',
            'StructureLostArmor'                         => 'armor_reinforced',
            'StructureDestroyed', 'SkyhookDestroyed'     => 'destroyed',
            default                                       => null,
        };

        if ($alertFlavor === null) {
            return;
        }

        if (!class_exists('\\ManagerCore\\Services\\EventBus')) {
            return;
        }

        $data = $notification->parsed_data ?? [];
        $meta = $this->resolveStructureMeta($data);

        $structureId = isset($data['structureID']) ? (int) $data['structureID'] : null;
        if ($structureId === null) {
            // Without a structure ID downstream subscribers can't act — drop
            // rather than publish a malformed event
            return;
        }

        // For 'destroyed': latch the disappearance-tracking row to prevent the
        // grace-period job from also firing a duplicate event ~30 minutes later.
        // The HIGH-confidence (notification) path always wins over the MEDIUM
        // (grace-period) path.
        if ($alertFlavor === 'destroyed') {
            try {
                \StructureManager\Models\StructureDisappearanceTracking::where('structure_id', $structureId)
                    ->update([
                        'status'           => 'destroyed',
                        'detection_source' => 'notification',
                        'resolved_at'      => Carbon::now(),
                    ]);
            } catch (\Throwable $e) {
                Log::warning("StructureEventHandler: failed to latch disappearance-tracking for {$structureId}: " . $e->getMessage());
                // Non-fatal — proceed with the publish
            }
        }

        // Resolve the reinforcement timer end from corporation_structures.
        // For LostShields the next timer is armor_reinforce; for LostArmor it
        // is hull_reinforce. Either way state_timer_end on the structure row
        // is the authoritative end time.
        $timerEndsAt = null;
        $typeId = null;
        $structureRow = DB::table('corporation_structures')
            ->where('structure_id', $structureId)
            ->select('state_timer_end', 'type_id')
            ->first();
        if ($structureRow) {
            $timerEndsAt = $structureRow->state_timer_end
                ? Carbon::parse($structureRow->state_timer_end)->toIso8601String()
                : null;
            $typeId = (int) $structureRow->type_id;
        }
        // Fallback: type_id from notification YAML if structure row missing
        if ($typeId === null && isset($data['structureShowInfoData'][1])) {
            $typeId = (int) $data['structureShowInfoData'][1];
        }

        // Strip security to a float (resolveStructureMeta formats it for display)
        $systemSecurity = null;
        if (isset($data['solarsystemID'])) {
            $sec = DB::table('mapDenormalize')
                ->where('itemID', $data['solarsystemID'])
                ->value('security');
            if ($sec !== null) {
                $systemSecurity = (float) $sec;
            }
        }

        // Resolve attacker context from the notification YAML + character_infos
        // cache. We lift everything CCP gave us into discrete fields and compute
        // a single attacker_resolution_status so subscribers can label "(name
        // pending)" / "Aggressor pending verification" instead of rendering
        // "Unknown" or omitting the data.
        $attackerCharacterId   = isset($data['charID'])     ? (int) $data['charID']     : null;
        $attackerCorporationId = isset($data['corpID'])     ? (int) $data['corpID']     : null;
        $attackerAllianceId    = isset($data['allianceID']) ? (int) $data['allianceID'] : null;
        $attackerCorporation   = $data['corpName']     ?? null;
        $attackerAlliance      = $data['allianceName'] ?? null;

        // Try to resolve the character name from SeAT's local cache
        // (character_infos is populated when SeAT does any character-info
        // ESI pull). Cache miss → leave name null; subscribers render the
        // ID-only form. No blocking ESI call here — speed matters more than
        // completeness on this initial alert.
        $attackerCharacterName = null;
        if ($attackerCharacterId) {
            try {
                $attackerCharacterName = DB::table('character_infos')
                    ->where('character_id', $attackerCharacterId)
                    ->value('name');
            } catch (\Throwable $e) {
                // character_infos table missing or query blew up — non-fatal,
                // fall through with null name and let resolution_status reflect it
            }
        }

        // Resolution status:
        //   resolved   = full identity (charID + name)
        //   partial    = some IDs/names but no full character resolution
        //   unresolved = nothing useful
        if ($attackerCharacterId && $attackerCharacterName) {
            $attackerResolutionStatus = 'resolved';
        } elseif ($attackerCharacterId || $attackerCorporationId || $attackerCorporation || $attackerAlliance) {
            $attackerResolutionStatus = 'partial';
        } else {
            $attackerResolutionStatus = 'unresolved';
        }

        $attackerSummary = $attackerCorporation
            ? trim($attackerCorporation . ($attackerAlliance ? " ({$attackerAlliance})" : ''))
            : null;

        // Assemble the publisher's view of the event, then route through
        // AlertEventEnvelope so subscribers see the full contract scaffold
        // (source_plugin / schema_version / event_id / category_group /
        // eve_time / seconds_until / is_elapsed / url) without each publish
        // site needing to remember those base fields.
        $context = [
            'structure_id'              => $structureId,
            'corporation_id'            => (int) $notification->corporation_id,
            'type_id'                   => $typeId,                          // legacy key (MM reads this)
            'structure_type_id'         => $typeId,                          // contract key
            'structure_name'            => $meta['name'],
            'system_id'                 => isset($data['solarsystemID']) ? (int) $data['solarsystemID'] : null,
            'system_name'               => $meta['system'] ? preg_replace('/\s*\([^)]*\)\s*$/', '', $meta['system']) : null,
            'system_security'           => $systemSecurity,
            'severity'                  => 'critical',
            'source_reference'          => isset($notification->notification_id)
                ? 'esi-notif:' . $notification->notification_id
                : null,

            // Discrete attacker fields (contract base — see AlertEventEnvelope)
            'attacker_resolution_status' => $attackerResolutionStatus,
            'attacker_character_id'      => $attackerCharacterId,
            'attacker_character_name'    => $attackerCharacterName,
            'attacker_corporation_id'    => $attackerCorporationId,
            'attacker_corporation_name'  => $attackerCorporation,
            'attacker_alliance_id'       => $attackerAllianceId,
            'attacker_alliance_name'     => $attackerAlliance,

            // Legacy/flavor-specific keys MM already reads — preserved verbatim
            'timer_ends_at'     => $timerEndsAt,
            'attacker_summary'  => $attackerSummary,
            'attacker_corp'     => $attackerCorporation,
            'attacker_alliance' => $attackerAlliance,
            'notification_id'   => $notification->notification_id ?? null,
            'notification_type' => $notification->type,

            // For shield/armor: eve_time = when the reinforce timer ends.
            // For destroyed: overridden below to destroyed_at.
            'eve_time' => $timerEndsAt,
        ];

        // For 'destroyed' alerts, add the destruction-specific fields the
        // contract requires (see project_structure_manager_destruction_detection.md
        // payload contract). HIGH-confidence detection from a CCP notification
        // is the gold standard — destroyed_at is the notification timestamp.
        if ($alertFlavor === 'destroyed') {
            $destroyedAtIso = isset($notification->timestamp)
                ? Carbon::parse($notification->timestamp)->toIso8601String()
                : Carbon::now()->toIso8601String();
            $context['destroyed_at']       = $destroyedAtIso;
            $context['detection_source']   = 'notification';
            $context['killmail_url']       = null; // Could be enriched via zKillboard later (LOW-confidence cross-ref)
            $context['final_timer_result'] = 'destroyed_via_notification';
            $context['eve_time']           = $destroyedAtIso;                // event "happened" at destruction
        }

        $payload = AlertEventEnvelope::build($alertFlavor, $context);

        try {
            app(\ManagerCore\Services\EventBus::class)->publish(
                'structure.alert.' . $alertFlavor,
                'structure-manager',
                $payload
            );
            Log::info("StructureEventHandler: published structure.alert.{$alertFlavor} for structure {$structureId} (notification #{$notification->notification_id}, event_id={$payload['event_id']}, attacker={$attackerResolutionStatus})");
        } catch (\Throwable $e) {
            Log::warning("StructureEventHandler: failed to publish structure.alert.{$alertFlavor} for structure {$structureId}: " . $e->getMessage());
        }

        // Tier C Stage 2: for destroyed events, dispatch async zKB enrichment.
        // Initial 30s delay gives zKB a head start on ingesting the killmail.
        // The job retries on backoff, finalizes as either 'enriched' (zKB hit)
        // or 'not_found' (retry budget exhausted), and publishes a follow-up
        // structure.alert.destroyed_confirmed event correlated by event_id.
        if ($alertFlavor === 'destroyed' && $typeId !== null && isset($context['system_id']) && $context['system_id'] !== null) {
            try {
                EnrichKillmailJob::dispatch(
                    $structureId,
                    (int) $notification->corporation_id,
                    (int) $typeId,
                    (int) $context['system_id'],
                    $context['destroyed_at'],
                    $payload['event_id']
                )->delay(now()->addSeconds(30));
                Log::info("StructureEventHandler: dispatched EnrichKillmailJob for destroyed structure {$structureId}");
            } catch (\Throwable $e) {
                // Non-fatal — operators got stage 1 already; stage 2 is enrichment only.
                Log::warning("StructureEventHandler: failed to dispatch EnrichKillmailJob for {$structureId}: " . $e->getMessage());
            }
        }
    }

    /**
     * Pick the best eve_time for the board row given the event type + data.
     *
     * Reinforce progression timers live on corporation_structures.state_timer_end.
     * Anchor/unanchor lack a reliable duration field in the notification YAML;
     * we use now() as a placeholder so the event appears on the board
     * immediately. (A future pass can enrich from ESI if needed.)
     */
    private function resolveEveTimeForEvent($notification, string $eventType, array $data): Carbon
    {
        // For reinforce progression, prefer the state_timer_end on the structure
        if (in_array($eventType, ['reinforce_armor', 'reinforce_hull'], true) && !empty($data['structureID'])) {
            $timerEnd = DB::table('corporation_structures')
                ->where('structure_id', $data['structureID'])
                ->value('state_timer_end');
            if ($timerEnd) {
                return Carbon::parse($timerEnd);
            }
        }

        return Carbon::parse($notification->timestamp);
    }

    /**
     * Map internal category name (attack / lifecycle / fuel) to the
     * notification_categories.category_key value used in the DB.
     */
    private function categoryToKey(string $internalCategory): ?string
    {
        return match ($internalCategory) {
            'attack'           => 'structure_attack',
            'lifecycle'        => 'structure_lifecycle',
            'fuel'             => 'structure_fuel_events',
            'services_offline' => 'services_offline',
            'sovereignty'      => 'sovereignty',
            default            => null,
        };
    }

    private function buildPayload($notification): ?array
    {
        $data = $notification->parsed_data ?? [];
        $type = $notification->type;
        $category = $this->getCategory($type);

        $meta = $this->resolveStructureMeta($data);

        switch ($category) {
            case 'attack':
                return $this->buildAttackPayload($notification, $data, $meta);
            case 'lifecycle':
                return $this->buildLifecyclePayload($notification, $data, $meta);
            case 'fuel':
                return $this->buildFuelEventPayload($notification, $data, $meta);
            case 'services_offline':
                return $this->buildServicesOfflinePayload($notification, $data, $meta);
            case 'sovereignty':
                return $this->buildSovereigntyPayload($notification, $data, $meta);
            default:
                return null;
        }
    }

    private function buildAttackPayload($notification, array $data, array $meta): array
    {
        $type = $notification->type;

        $color = in_array($type, ['StructureDestroyed', 'SkyhookDestroyed', 'StructureUnderAttack', 'SkyhookUnderAttack'])
            ? 10038562  // dark red
            : 15158332; // red

        $titles = [
            'StructureUnderAttack' => 'UNDER ATTACK',
            'StructureLostShields' => 'SHIELDS DOWN',
            'StructureLostArmor' => 'ARMOR DOWN',
            'StructureDestroyed' => 'DESTROYED',
            'SkyhookUnderAttack' => 'SKYHOOK UNDER ATTACK',
            'SkyhookLostShields' => 'SKYHOOK SHIELDS DOWN',
            'SkyhookDestroyed' => 'SKYHOOK DESTROYED',
        ];
        $titleSuffix = $titles[$type] ?? strtoupper(str_replace('Structure', '', $type));

        $fields = [];
        $fields[] = ['name' => "\u{1F4CD} Location", 'value' => $meta['system'] ?? 'Unknown', 'inline' => true];
        $fields[] = ['name' => 'Structure Type', 'value' => $meta['type'] ?? 'Unknown', 'inline' => true];
        $fields[] = ['name' => "\u{23F0} Last Update", 'value' => Carbon::parse($notification->timestamp)->diffForHumans(), 'inline' => true];

        if (isset($data['shieldPercentage'])) {
            $fields[] = ['name' => 'Shield', 'value' => number_format($data['shieldPercentage'], 1) . '%', 'inline' => true];
            $fields[] = ['name' => 'Armor', 'value' => number_format($data['armorPercentage'] ?? 100, 1) . '%', 'inline' => true];
            $fields[] = ['name' => 'Hull', 'value' => number_format($data['hullPercentage'] ?? 100, 1) . '%', 'inline' => true];
        }

        // Reinforce timer end — CCP notification YAML carries `timeLeft` (ticks)
        // for LostShields/LostArmor; for LostShields, prefer corporation_structures.
        // state_timer_end which is the authoritative armor-reinforce-ends time.
        $reinforceTimerField = $this->resolveReinforceTimerField($notification, $data);
        if ($reinforceTimerField !== null) {
            $fields[] = $reinforceTimerField;
        }

        // Attacker pilot enrichment — CCP YAML carries `charID` for the attacking
        // character. Look up the cached name from SeAT's character_infos table
        // (populated whenever SeAT pulls public character info). If not cached,
        // show the ID as a fallback rather than skip the field — operators can
        // verify externally and the field is the cue that an attacker existed.
        if (!empty($data['charID']) && is_numeric($data['charID'])) {
            $charId = (int) $data['charID'];
            $pilotName = null;
            try {
                $pilotName = DB::table('character_infos')
                    ->where('character_id', $charId)
                    ->value('name');
            } catch (\Throwable $e) {
                // character_infos may not exist on bare-bones SeAT installs
            }
            $fields[] = [
                'name'   => "\u{1F464} Attacker Pilot",
                'value'  => $pilotName
                    ? "[{$pilotName}](https://zkillboard.com/character/{$charId}/)"
                    : "Pilot ID #{$charId} *(name not cached)*",
                'inline' => true,
            ];
        }

        if (isset($data['corpName'])) {
            $fields[] = ['name' => 'Attacker Corp', 'value' => $data['corpName'] ?? 'Unknown', 'inline' => true];
        }
        if (!empty($data['allianceName'])) {
            $fields[] = ['name' => 'Attacker Alliance', 'value' => $data['allianceName'], 'inline' => true];
        }

        // Dotlan map link for regional intel (recent kills, capital activity)
        if (!empty($meta['dotlan_url'])) {
            $fields[] = [
                'name'  => "\u{1F5FA} Map",
                'value' => "[{$meta['system_name']} ({$meta['region_name']})]({$meta['dotlan_url']})",
                'inline' => true,
            ];
        }

        $fields[] = ['name' => 'Detection', 'value' => 'via ' . ($notification->source ?? 'unknown') . ' (' . Carbon::parse($notification->timestamp)->diffForHumans() . ')', 'inline' => true];

        $embed = [
            'title' => ($meta['name'] ?? 'Unknown Structure') . " \u{2014} " . $titleSuffix,
            'color' => $color,
            'fields' => $fields,
            'footer' => ['text' => $this->buildFooterText($notification)],
            'timestamp' => Carbon::parse($notification->timestamp)->toIso8601String(),
        ];

        $contentTitles = [
            'StructureUnderAttack' => '**STRUCTURE UNDER ATTACK!** - Immediate response required',
            'StructureLostShields' => '**STRUCTURE LOST SHIELDS!** - Timer started',
            'StructureLostArmor' => '**STRUCTURE LOST ARMOR!** - Hull vulnerable',
            'StructureDestroyed' => '**STRUCTURE DESTROYED!**',
            'SkyhookUnderAttack' => '**SKYHOOK UNDER ATTACK!** - Immediate response required',
            'SkyhookLostShields' => '**SKYHOOK LOST SHIELDS!**',
            'SkyhookDestroyed' => '**SKYHOOK DESTROYED!**',
        ];

        return [
            'content' => $contentTitles[$type] ?? "**{$titleSuffix}**",
            'embeds' => [$embed],
            'username' => 'SeAT Structure Manager',
            'allowed_mentions' => ['parse' => [], 'users' => [], 'roles' => []],
        ];
    }

    private function buildLifecyclePayload($notification, array $data, array $meta): array
    {
        $type = $notification->type;

        $colorMap = [
            'StructureAnchoring' => 3447003,
            'AllAnchoringMsg' => 3447003,
            'SkyhookDeployed' => 3447003,
            'StructureUnanchoring' => 16776960,
            'OwnershipTransferred' => 3447003,
        ];
        $color = $colorMap[$type] ?? 3447003;

        $titleMap = [
            'StructureAnchoring' => 'Anchoring Started',
            'AllAnchoringMsg' => 'Structure Anchoring Detected',
            'StructureUnanchoring' => 'Unanchoring Started',
            'OwnershipTransferred' => 'Ownership Transferred',
            'SkyhookDeployed' => 'Skyhook Deployed',
        ];

        $fields = [];
        $fields[] = ['name' => "\u{1F4CD} Location", 'value' => $meta['system'] ?? 'Unknown', 'inline' => true];
        $fields[] = ['name' => 'Structure Type', 'value' => $meta['type'] ?? 'Unknown', 'inline' => true];
        $fields[] = ['name' => "\u{23F0} Last Update", 'value' => Carbon::parse($notification->timestamp)->diffForHumans(), 'inline' => true];

        // Anchoring / Unanchoring: humanize CCP's nanosecond timeLeft into a
        // proper completion timestamp + remaining countdown. Operators care
        // exactly when these complete (anchoring deadline, unanchor abort window).
        if (in_array($type, ['StructureAnchoring', 'StructureUnanchoring', 'AllAnchoringMsg', 'SkyhookDeployed'], true)
            && !empty($data['timeLeft'])
            && is_numeric($data['timeLeft'])
            && (int) $data['timeLeft'] > 0
        ) {
            $base = Carbon::parse($notification->timestamp);
            $fmt  = $this->formatCcpDuration((int) $data['timeLeft'], $base);

            $completionLabel = match ($type) {
                'StructureAnchoring', 'AllAnchoringMsg', 'SkyhookDeployed' => 'Anchoring Completes',
                'StructureUnanchoring'                                    => 'Unanchoring Completes',
                default                                                    => 'Completes',
            };

            $fields[] = [
                'name'   => "\u{23F1} {$completionLabel}",
                'value'  => $fmt['iso'] . "\n*({$fmt['remaining']})*",
                'inline' => true,
            ];
        }

        // Anchoring also carries `vulnerableTime` — the duration of the post-
        // anchoring vulnerability window. CCP cites this in nanoseconds the
        // same way as timeLeft.
        if (in_array($type, ['StructureAnchoring', 'AllAnchoringMsg', 'SkyhookDeployed'], true)
            && !empty($data['vulnerableTime'])
            && is_numeric($data['vulnerableTime'])
            && (int) $data['vulnerableTime'] > 0
        ) {
            // Vulnerable time is a duration, not an absolute time — the human
            // representation alone is what the operator needs.
            $vfmt = $this->formatCcpDuration((int) $data['vulnerableTime'], Carbon::now());
            $fields[] = [
                'name'   => "\u{1F6E1}\u{FE0F} Vulnerability Window",
                'value'  => $vfmt['human'],
                'inline' => true,
            ];
        }

        if (isset($data['corpName'])) {
            $fields[] = ['name' => 'Corporation', 'value' => $data['corpName'], 'inline' => true];
        }

        // Dotlan map link
        if (!empty($meta['dotlan_url'])) {
            $fields[] = [
                'name'   => "\u{1F5FA} Map",
                'value'  => "[{$meta['system_name']} ({$meta['region_name']})]({$meta['dotlan_url']})",
                'inline' => true,
            ];
        }

        $embed = [
            'title' => ($meta['name'] ?? $meta['type'] ?? 'Structure') . ' — ' . ($titleMap[$type] ?? $type),
            'color' => $color,
            'fields' => $fields,
            'footer' => ['text' => $this->buildFooterText($notification)],
            'timestamp' => Carbon::parse($notification->timestamp)->toIso8601String(),
        ];

        return [
            'content' => '**' . ($titleMap[$type] ?? $type) . '**',
            'embeds' => [$embed],
            'username' => 'SeAT Structure Manager',
            'allowed_mentions' => ['parse' => [], 'users' => [], 'roles' => []],
        ];
    }

    private function buildFuelEventPayload($notification, array $data, array $meta): array
    {
        $type = $notification->type;

        $colorMap = [
            'StructureWentLowPower' => 16776960,
            'StructureWentHighPower' => 3066993,
            'StructureServicesOffline' => 16776960,
            'StructureFuelAlert' => 16776960,
            'StructureLowReagentsAlert' => 16776960,
            'StructureNoReagentsAlert' => 15158332,
            'SkyhookOnline' => 3066993,
        ];
        $color = $colorMap[$type] ?? 16776960;

        $titleMap = [
            'StructureWentLowPower' => 'LOW POWER',
            'StructureWentHighPower' => 'High Power Restored',
            'StructureServicesOffline' => 'Services Offline',
            'StructureFuelAlert' => 'Fuel Alert (CCP)',
            'StructureLowReagentsAlert' => 'Low Reagents',
            'StructureNoReagentsAlert' => 'No Reagents — Services Offline',
            'SkyhookOnline' => 'Skyhook Online',
        ];

        $fields = [];
        $fields[] = ['name' => "\u{1F4CD} Location", 'value' => $meta['system'] ?? 'Unknown', 'inline' => true];
        $fields[] = ['name' => 'Structure Type', 'value' => $meta['type'] ?? 'Unknown', 'inline' => true];
        $fields[] = ['name' => "\u{23F0} Last Update", 'value' => Carbon::parse($notification->timestamp)->diffForHumans(), 'inline' => true];

        // For StructureWentLowPower, surface a prominent "going offline in" /
        // "fuel exhausted" field by reading corporation_structures.fuel_expires.
        // CCP doesn't carry this in the YAML — we have to look it up. Adds
        // urgency cue that's missing from SeAT core's bare-bones notification.
        if ($type === 'StructureWentLowPower' && !empty($data['structureID'])) {
            $fuelExpires = DB::table('corporation_structures')
                ->where('structure_id', $data['structureID'])
                ->value('fuel_expires');

            if ($fuelExpires) {
                $exp = Carbon::parse($fuelExpires);
                if ($exp->isPast()) {
                    $fields[] = [
                        'name'   => "\u{26A0}\u{FE0F} Fuel Status",
                        'value'  => '**Fuel exhausted** ' . $exp->diffForHumans() . ' — refuel ASAP to recover services.',
                        'inline' => false,
                    ];
                } else {
                    $remaining = $exp->diffForHumans(Carbon::now(), [
                        'parts' => 2,
                        'syntax' => \Carbon\CarbonInterface::DIFF_RELATIVE_TO_NOW,
                    ]);
                    $fields[] = [
                        'name'   => "\u{1F525} Going Offline In",
                        'value'  => '**' . $remaining . '**' . "\n" . $exp->format('Y-m-d H:i') . ' UTC',
                        'inline' => false,
                    ];
                }
            } else {
                $fields[] = [
                    'name'   => "\u{26A0}\u{FE0F} Fuel Status",
                    'value'  => '**No fuel data** — structure may already be in low-power state with empty fuel bay.',
                    'inline' => false,
                ];
            }
        }

        if (isset($data['listOfTypesAndQty']) && is_array($data['listOfTypesAndQty'])) {
            foreach ($data['listOfTypesAndQty'] as $item) {
                $typeName = $this->resolveTypeName($item[1] ?? 0);
                $qty = $item[0] ?? 0;
                $fields[] = ['name' => $typeName, 'value' => number_format($qty) . ' remaining', 'inline' => true];
            }
        }

        // Dotlan map link — useful for any fuel/power event for regional intel
        if (!empty($meta['dotlan_url'])) {
            $fields[] = [
                'name'   => "\u{1F5FA} Map",
                'value'  => "[{$meta['system_name']} ({$meta['region_name']})]({$meta['dotlan_url']})",
                'inline' => true,
            ];
        }

        $embed = [
            'title' => ($meta['name'] ?? $meta['type'] ?? 'Structure') . ' — ' . ($titleMap[$type] ?? $type),
            'color' => $color,
            'fields' => $fields,
            'footer' => ['text' => $this->buildFooterText($notification)],
            'timestamp' => Carbon::parse($notification->timestamp)->toIso8601String(),
        ];

        return [
            'content' => '**' . ($titleMap[$type] ?? $type) . '**',
            'embeds' => [$embed],
            'username' => 'SeAT Structure Manager',
            'allowed_mentions' => ['parse' => [], 'users' => [], 'roles' => []],
        ];
    }

    /**
     * StructureServicesOffline embed — enriched over SeAT core's bare module list.
     *
     * CCP YAML for this notification carries `listOfServiceModuleIDs` (array of
     * service module typeIDs). SeAT core renders these as raw type names with
     * no impact context. SM resolves each module name and categorizes it
     * (Industry / Market / Cloning / Research / Mining / Logistics / Other),
     * then highlights the highest-impact category — operators reading the
     * embed know whether this is "manufacturing went down" (route to industry)
     * vs "market went down" (route to traders) etc.
     *
     * Severity reflects the highest-impact category lost:
     *   industry / market = critical
     *   cloning / mining  = high
     *   science / logistics = medium
     *   other             = low
     */
    private function buildServicesOfflinePayload($notification, array $data, array $meta): array
    {
        $moduleIds = $data['listOfServiceModuleIDs'] ?? [];
        if (!is_array($moduleIds)) {
            $moduleIds = [];
        }

        // Resolve and categorize each module
        $modules = [];                          // [['name' => ..., 'category' => ..., 'impact' => ...]]
        $highestImpact = 'low';
        $impactRank = ['low' => 0, 'medium' => 1, 'high' => 2, 'critical' => 3];

        foreach ($moduleIds as $typeId) {
            if (!is_numeric($typeId) || (int) $typeId <= 0) {
                continue;
            }
            $info = $this->resolveServiceModuleImpact((int) $typeId);
            $modules[] = $info;
            if ($impactRank[$info['impact']] > $impactRank[$highestImpact]) {
                $highestImpact = $info['impact'];
            }
        }

        // Color by highest impact
        $color = match ($highestImpact) {
            'critical' => 15158332, // red
            'high'     => 16753920, // orange
            'medium'   => 16776960, // yellow
            default    => 9807270,  // grey
        };

        $fields = [];
        $fields[] = ['name' => "\u{1F4CD} Location",   'value' => $meta['system'] ?? 'Unknown',                          'inline' => true];
        $fields[] = ['name' => 'Structure Type',       'value' => $meta['type'] ?? 'Unknown',                            'inline' => true];
        $fields[] = ['name' => "\u{23F0} Last Update", 'value' => Carbon::parse($notification->timestamp)->diffForHumans(), 'inline' => true];

        if (empty($modules)) {
            $fields[] = [
                'name'   => "\u{26A0}\u{FE0F} Services Offline",
                'value'  => '*(no module list in notification — refuel + services should auto-recover)*',
                'inline' => false,
            ];
        } else {
            // Group by category for cleaner display
            $byCategory = [];
            foreach ($modules as $m) {
                $byCategory[$m['category']][] = $m['name'];
            }

            $categoryLabels = [
                'industry'   => "\u{1F3ED} Industry",
                'market'     => "\u{1F4B0} Market",
                'cloning'    => "\u{1F9EC} Cloning",
                'science'    => "\u{1F52C} Research",
                'mining'     => "\u{26CF}\u{FE0F} Mining",
                'logistics'  => "\u{1F310} Logistics",
                'other'      => "\u{2699}\u{FE0F} Other",
            ];

            foreach ($byCategory as $cat => $names) {
                $label = $categoryLabels[$cat] ?? ucfirst($cat);
                $fields[] = [
                    'name'   => $label,
                    'value'  => implode(', ', array_slice($names, 0, 8))
                              . (count($names) > 8 ? ' +' . (count($names) - 8) . ' more' : ''),
                    'inline' => false,
                ];
            }

            $fields[] = [
                'name'   => "\u{1F525} Impact",
                'value'  => '**' . strtoupper($highestImpact) . '** — ' . count($modules) . ' service module(s) offline',
                'inline' => true,
            ];
        }

        if (!empty($meta['dotlan_url'])) {
            $fields[] = [
                'name'   => "\u{1F5FA} Map",
                'value'  => "[{$meta['system_name']} ({$meta['region_name']})]({$meta['dotlan_url']})",
                'inline' => true,
            ];
        }

        $embed = [
            'title'     => ($meta['name'] ?? $meta['type'] ?? 'Structure') . ' — Services Offline',
            'color'     => $color,
            'fields'    => $fields,
            'footer'    => ['text' => $this->buildFooterText($notification)],
            'timestamp' => Carbon::parse($notification->timestamp)->toIso8601String(),
        ];

        return [
            'content'         => '**Services Offline** — refuel to recover',
            'embeds'          => [$embed],
            'username'        => 'SeAT Structure Manager',
            'allowed_mentions' => ['parse' => [], 'users' => [], 'roles' => []],
        ];
    }

    /**
     * Map a service module typeID to a {name, category, impact} descriptor.
     *
     * Categorization is name-based (regex on typeName) rather than ID-hardcoded
     * so the function adapts when CCP adds new service modules without
     * requiring a code change. Pattern:
     *   /Manufacturing|Reprocessing|Refinery|Composite|Reactor|Component/ → industry
     *   /Market/                                                       → market
     *   /Cloning/                                                      → cloning
     *   /Research|Lab|Invention/                                       → science
     *   /Drilling|Moon Drill/                                          → mining
     *   /Cyno|Jump Gate/                                               → logistics
     *   else                                                           → other
     */
    private function resolveServiceModuleImpact(int $typeId): array
    {
        $name = $this->resolveTypeName($typeId);

        $lname = strtolower($name);
        if (preg_match('/manufactur|reprocess|refin|composite|reactor|component/i', $lname)) {
            return ['name' => $name, 'category' => 'industry', 'impact' => 'critical'];
        }
        if (preg_match('/market/i', $lname)) {
            return ['name' => $name, 'category' => 'market', 'impact' => 'critical'];
        }
        if (preg_match('/cloning/i', $lname)) {
            return ['name' => $name, 'category' => 'cloning', 'impact' => 'high'];
        }
        if (preg_match('/research|\blab\b|invention/i', $lname)) {
            return ['name' => $name, 'category' => 'science', 'impact' => 'medium'];
        }
        if (preg_match('/drilling|moon drill/i', $lname)) {
            return ['name' => $name, 'category' => 'mining', 'impact' => 'high'];
        }
        if (preg_match('/cyno|jump gate|beacon|jammer/i', $lname)) {
            return ['name' => $name, 'category' => 'logistics', 'impact' => 'medium'];
        }
        return ['name' => $name, 'category' => 'other', 'impact' => 'low'];
    }

    /**
     * Sovereignty event embed — handles EntosisCaptureStarted, SovStructureReinforced,
     * SovStructureDestroyed, SovCommandNodeEventStarted.
     *
     * CCP's sov YAML is different from Upwell — fields use camelCase (`solarSystemID`
     * vs `solarsystemID`), `decloakTime` for reinforce node decloaks, etc. The embed
     * surfaces what's actionable for sov ops: timer end, attacker corp/alliance,
     * type of structure (TCU/IHUB), region for dotlan.
     */
    private function buildSovereigntyPayload($notification, array $data, array $meta): array
    {
        $type = $notification->type;

        // Sov YAML uses different field names than Upwell — normalize
        $solarSystemId = $data['solarSystemID']
                      ?? $data['solarsystemID']
                      ?? null;
        $structureTypeId = $data['structureTypeID'] ?? null;
        $structureTypeName = $structureTypeId ? $this->resolveTypeName((int) $structureTypeId) : null;

        // Re-resolve meta if sov YAML's solarSystemID didn't match our default lookup
        if ($solarSystemId !== null && empty($meta['system'])) {
            $sys = DB::table('mapDenormalize')
                ->where('itemID', $solarSystemId)
                ->select('itemName', 'security', 'regionID')
                ->first();
            if ($sys) {
                $meta['system_name'] = $sys->itemName;
                $meta['system']      = $sys->itemName . ' (' . number_format($sys->security, 2) . ')';
                if ($sys->regionID) {
                    $regionName = DB::table('mapDenormalize')->where('itemID', $sys->regionID)->value('itemName');
                    if ($regionName) {
                        $meta['region_name'] = $regionName;
                        $meta['dotlan_url']  = 'https://evemaps.dotlan.net/map/'
                            . str_replace(' ', '_', $regionName) . '/'
                            . str_replace(' ', '_', $sys->itemName);
                    }
                }
            }
        }

        $titleMap = [
            'EntosisCaptureStarted'      => 'ENTOSIS CAPTURE STARTED',
            'SovStructureReinforced'     => 'SOV STRUCTURE REINFORCED',
            'SovStructureDestroyed'      => 'SOV STRUCTURE DESTROYED',
            'SovCommandNodeEventStarted' => 'COMMAND NODE EVENT STARTED',
        ];
        $colorMap = [
            'EntosisCaptureStarted'      => 16753920, // orange
            'SovStructureReinforced'     => 15158332, // red
            'SovStructureDestroyed'      => 10038562, // dark red
            'SovCommandNodeEventStarted' => 16776960, // yellow
        ];

        $fields = [];
        $fields[] = ['name' => "\u{1F4CD} Location",   'value' => $meta['system'] ?? 'Unknown', 'inline' => true];

        if ($structureTypeName) {
            $fields[] = ['name' => 'Structure Type', 'value' => $structureTypeName, 'inline' => true];
        }

        $fields[] = ['name' => "\u{23F0} Last Update", 'value' => Carbon::parse($notification->timestamp)->diffForHumans(), 'inline' => true];

        // Decloak / reinforce timer for SovStructureReinforced
        if ($type === 'SovStructureReinforced' && !empty($data['decloakTime']) && is_numeric($data['decloakTime'])) {
            $base = Carbon::parse($notification->timestamp);
            $fmt  = $this->formatCcpDuration((int) $data['decloakTime'], $base);
            $fields[] = [
                'name'   => "\u{23F1} Node Decloak",
                'value'  => $fmt['iso'] . "\n*({$fmt['remaining']})*",
                'inline' => true,
            ];
        }

        // Campaign event type (Command Node spawn details)
        if ($type === 'SovCommandNodeEventStarted' && !empty($data['campaignEventType'])) {
            $fields[] = [
                'name'   => 'Campaign',
                'value'  => match ((int) $data['campaignEventType']) {
                    1       => 'TCU defense',
                    2       => 'IHUB defense',
                    3       => 'Station freeport',
                    default => 'Type ' . $data['campaignEventType'],
                },
                'inline' => true,
            ];
        }

        if (!empty($meta['dotlan_url'])) {
            $fields[] = [
                'name'   => "\u{1F5FA} Map",
                'value'  => "[{$meta['system_name']} ({$meta['region_name']})]({$meta['dotlan_url']})",
                'inline' => true,
            ];
        }

        $titleSuffix = $titleMap[$type] ?? strtoupper($type);
        $color       = $colorMap[$type] ?? 15158332;

        $embed = [
            'title'     => ($structureTypeName ?? 'Sovereignty Structure') . " \u{2014} " . $titleSuffix,
            'color'     => $color,
            'fields'    => $fields,
            'footer'    => ['text' => $this->buildFooterText($notification)],
            'timestamp' => Carbon::parse($notification->timestamp)->toIso8601String(),
        ];

        $contentMap = [
            'EntosisCaptureStarted'      => '**ENTOSIS CAPTURE STARTED** — sov structure under hostile capture',
            'SovStructureReinforced'     => '**SOV STRUCTURE REINFORCED** — defenders required at decloak',
            'SovStructureDestroyed'      => '**SOV STRUCTURE DESTROYED**',
            'SovCommandNodeEventStarted' => '**COMMAND NODE SPAWNED**',
        ];

        return [
            'content'          => $contentMap[$type] ?? "**{$titleSuffix}**",
            'embeds'           => [$embed],
            'username'         => 'SeAT Structure Manager',
            'allowed_mentions' => ['parse' => [], 'users' => [], 'roles' => []],
        ];
    }

    private function buildFooterText($notification): string
    {
        $source = $notification->source ?? 'unknown';
        $label = match ($source) {
            'fast_poll' => 'Fast Poll (Manager Core)',
            'seat_fallback' => 'SeAT Sweep (Manager Core)',
            'seat_native' => 'SeAT Native',
            default => $source,
        };
        return 'SeAT Structure Manager | ' . $label;
    }

    private function resolveStructureMeta(array $data): array
    {
        $meta = [
            'name'          => null,
            'type'          => null,
            'system'        => null, // "Name (sec)" — kept for backward-compat with existing fields
            'system_name'   => null, // raw system name (no security suffix)
            'region_name'   => null, // for dotlan URL
            'dotlan_url'    => null, // built dotlan map link
        ];

        if (isset($data['solarsystemID'])) {
            $system = DB::table('mapDenormalize')
                ->where('itemID', $data['solarsystemID'])
                ->select('itemName', 'security', 'regionID')
                ->first();
            if ($system) {
                $meta['system_name'] = $system->itemName;
                $meta['system']      = $system->itemName . ' (' . number_format($system->security, 2) . ')';

                if ($system->regionID) {
                    $regionName = DB::table('mapDenormalize')
                        ->where('itemID', $system->regionID)
                        ->value('itemName');
                    if ($regionName) {
                        $meta['region_name'] = $regionName;
                        // Dotlan uses underscores for spaces; system + region must both be encoded.
                        $meta['dotlan_url'] = 'https://evemaps.dotlan.net/map/'
                            . str_replace(' ', '_', $regionName) . '/'
                            . str_replace(' ', '_', $system->itemName);
                    }
                }
            }
        }

        if (isset($data['structureID'])) {
            $structure = DB::table('universe_structures')
                ->where('structure_id', $data['structureID'])
                ->value('name');
            $meta['name'] = $structure;
        }

        if (isset($data['structureShowInfoData'][1])) {
            $typeId = $data['structureShowInfoData'][1];
            $typeName = DB::table('invTypes')
                ->where('typeID', $typeId)
                ->value('typeName');
            $meta['type'] = $typeName ?? 'Unknown';
        }

        return $meta;
    }

    /**
     * Build a "Reinforce Timer Ends" field for attack-style notifications.
     *
     * Resolution order:
     *   1. corporation_structures.state_timer_end — most authoritative (refreshed
     *      from ESI every poll cycle, accurate even minutes after the notification)
     *   2. notification timestamp + timeLeft from CCP YAML — fallback when the
     *      structure isn't in our local DB
     *
     * Returns null if neither path yields a timer (e.g. StructureUnderAttack
     * which doesn't carry timeLeft and structure isn't reinforced yet).
     */
    private function resolveReinforceTimerField($notification, array $data): ?array
    {
        // Skip for events that don't have a forward-looking reinforce timer
        if (in_array($notification->type, ['StructureUnderAttack', 'SkyhookUnderAttack', 'StructureDestroyed', 'SkyhookDestroyed'], true)) {
            return null;
        }

        // Prefer the authoritative state_timer_end on the structure row
        if (!empty($data['structureID'])) {
            $stateTimerEnd = DB::table('corporation_structures')
                ->where('structure_id', $data['structureID'])
                ->value('state_timer_end');
            if ($stateTimerEnd) {
                $absolute  = Carbon::parse($stateTimerEnd);
                $now       = Carbon::now();
                $remaining = $absolute->isPast()
                    ? 'expired ' . $absolute->diffForHumans()
                    : $absolute->diffForHumans($now, [
                        'parts' => 2,
                        'short' => false,
                        'syntax' => \Carbon\CarbonInterface::DIFF_RELATIVE_TO_NOW,
                    ]);

                $label = match ($notification->type) {
                    'StructureLostShields', 'SkyhookLostShields' => 'Armor Reinforce Ends',
                    'StructureLostArmor'                         => 'Hull Reinforce Ends',
                    default                                       => 'Reinforce Timer Ends',
                };

                return [
                    'name'   => "\u{23F1} {$label}",
                    'value'  => $absolute->format('Y-m-d H:i') . " UTC\n*({$remaining})*",
                    'inline' => true,
                ];
            }
        }

        // Fallback: notification timestamp + timeLeft from CCP YAML
        if (!empty($data['timeLeft']) && is_numeric($data['timeLeft']) && (int) $data['timeLeft'] > 0) {
            $base = Carbon::parse($notification->timestamp);
            $fmt  = $this->formatCcpDuration((int) $data['timeLeft'], $base);

            $label = match ($notification->type) {
                'StructureLostShields', 'SkyhookLostShields' => 'Armor Reinforce Ends',
                'StructureLostArmor'                         => 'Hull Reinforce Ends',
                default                                       => 'Reinforce Timer Ends',
            };

            return [
                'name'   => "\u{23F1} {$label}",
                'value'  => $fmt['iso'] . "\n*({$fmt['remaining']})*",
                'inline' => true,
            ];
        }

        return null;
    }

    /**
     * Format a CCP-encoded nanosecond duration into:
     *   ['absolute' => Carbon, 'human' => 'Xd Yh Zm', 'iso' => '2026-05-01 08:17 UTC']
     *
     * CCP delivers durations in `timeLeft` / `vulnerableTime` fields as
     * 100-nanosecond ticks (Microsoft .NET TimeSpan ticks). 1 second = 10 million ticks.
     * The duration is RELATIVE TO THE NOTIFICATION TIMESTAMP — so completion time
     * is notification.timestamp + (timeLeft / 10_000_000) seconds.
     *
     * @param int $nanoseconds raw value from notification YAML
     * @param Carbon $base notification timestamp; absolute = base + duration
     * @return array{seconds:int, absolute:Carbon, human:string, iso:string, remaining:string}
     */
    private function formatCcpDuration(int $nanoseconds, Carbon $base): array
    {
        $seconds  = (int) max(0, $nanoseconds / 10_000_000);
        $absolute = $base->copy()->addSeconds($seconds);

        $days  = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $mins  = intdiv($seconds % 3600, 60);

        $parts = [];
        if ($days > 0)  { $parts[] = "{$days}d"; }
        if ($hours > 0) { $parts[] = "{$hours}h"; }
        if ($mins > 0 || empty($parts)) { $parts[] = "{$mins}m"; }
        $human = implode(' ', $parts);

        // "Xd Yh remaining" — relative to NOW, not to the base timestamp
        $now            = Carbon::now();
        $remainingSecs  = max(0, $absolute->diffInSeconds($now, false));
        $remD = intdiv($remainingSecs, 86400);
        $remH = intdiv($remainingSecs % 86400, 3600);
        $remM = intdiv($remainingSecs % 3600, 60);
        $remParts = [];
        if ($remD > 0) { $remParts[] = "{$remD}d"; }
        if ($remH > 0) { $remParts[] = "{$remH}h"; }
        if ($remM > 0 || empty($remParts)) { $remParts[] = "{$remM}m"; }
        $remaining = $absolute->isPast()
            ? 'expired ' . $absolute->diffForHumans()
            : implode(' ', $remParts) . ' remaining';

        return [
            'seconds'   => $seconds,
            'absolute'  => $absolute,
            'human'     => $human,
            'iso'       => $absolute->format('Y-m-d H:i') . ' UTC',
            'remaining' => $remaining,
        ];
    }

    private function resolveTypeName(int $typeId): string
    {
        if ($typeId <= 0) {
            return 'Unknown';
        }

        return DB::table('invTypes')
            ->where('typeID', $typeId)
            ->value('typeName') ?? "Type #{$typeId}";
    }

    private function getCategory(string $type): string
    {
        if (in_array($type, self::ATTACK_TYPES)) {
            return 'attack';
        }
        if (in_array($type, self::LIFECYCLE_TYPES)) {
            return 'lifecycle';
        }
        if (in_array($type, self::FUEL_EVENT_TYPES)) {
            return 'fuel';
        }
        if (in_array($type, self::SERVICES_OFFLINE_TYPES)) {
            return 'services_offline';
        }
        if (in_array($type, self::SOVEREIGNTY_TYPES)) {
            return 'sovereignty';
        }
        return 'unknown';
    }

    private function injectMention(array $payload, string $roleMention, string $category): array
    {
        if (empty($roleMention)) {
            return $payload;
        }

        if ($category !== 'attack' && $category !== 'fuel') {
            return $payload;
        }

        $mention = trim($roleMention);
        $mentionPrefix = '';

        if (preg_match('/^<@&(\d+)>$/', $mention, $m)) {
            $mentionPrefix = "<@&{$m[1]}> ";
            $payload['allowed_mentions']['roles'][] = $m[1];
        } elseif (preg_match('/^<@!?(\d+)>$/', $mention, $m)) {
            $mentionPrefix = "<@{$m[1]}> ";
            $payload['allowed_mentions']['users'][] = $m[1];
        } elseif (preg_match('/^\d+$/', $mention)) {
            $mentionPrefix = "<@&{$mention}> ";
            $payload['allowed_mentions']['roles'][] = $mention;
        }

        $payload['content'] = $mentionPrefix . ($payload['content'] ?? '');

        return $payload;
    }
}
