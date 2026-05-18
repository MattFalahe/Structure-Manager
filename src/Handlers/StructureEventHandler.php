<?php

namespace StructureManager\Handlers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use StructureManager\Helpers\AlertEventEnvelope;
use StructureManager\Jobs\EnrichKillmailJob;
use StructureManager\Models\StructureManagerSettings;
use StructureManager\Models\Timer;
use StructureManager\Models\WebhookConfiguration;
use StructureManager\Services\IdResolver;
use StructureManager\Services\TestDataGenerator;
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
     * Timer-only entry point for the backfill command.
     *
     * Walks a single notification through the same upsertBoardTimer logic
     * dispatch() uses, but skips Discord webhook dispatch and EventBus
     * publish. Idempotent — re-running on a notification whose timer row
     * already exists is a no-op (upsertAuto dedups on source_reference).
     *
     * Used by structure-manager:backfill-board-timers to retro-populate
     * the Structure Board after a code change adds new event_types (e.g.
     * the 2026-05-13 sov_reinforced / entosis_in_progress / command_node_spawned
     * additions). Operators can run it without re-firing webhooks that
     * already fired the first time.
     */
    public static function backfillTimerOnly($notification): void
    {
        $instance = new self();
        $category = $instance->getCategory($notification->type);
        if ($category === 'unknown') {
            return;
        }
        $instance->upsertBoardTimer($notification, $category);
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

        // Attacker threat intel — fire-and-forget async zKB enrichment.
        // INDEPENDENT of publishStructureAlertEvent: that one only fires
        // for notification types with a mapped alertFlavor (reinforce
        // states, sov, entosis). This one fires for ALL attack-type
        // notifications including StructureUnderAttack which has no
        // alertFlavor mapping but still carries attacker char_id worth
        // enriching. Job gates on master toggle + category bindings;
        // if disabled or not bound, it returns silently (cheap no-op).
        $this->dispatchAttackerThreatIntelIfApplicable($notification);

        // Test-notification routing: if this notification was injected by
        // the Test Notification Lab (notification_id in TestDataGenerator's
        // safe range), route to the configured test webhook URL ONLY —
        // never the production binding-resolved webhooks. Skip cleanly if
        // no test webhook is configured (board timer + EventBus already fired
        // above, which is enough to verify the rest of the pipeline).
        $isTestNotif = TestDataGenerator::isTestNotification(
            (int) ($notification->notification_id ?? 0)
        );
        if ($isTestNotif) {
            $this->dispatchTestNotification($notification, $category);
            return;
        }

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

            // v2.0.0 — route through WebhookDeliveryService for telemetry
            \StructureManager\Services\WebhookDeliveryService::sendByUrl(
                $binding['webhook_url'],
                $finalPayload,
                $category,
                "{$notification->type} (notification #{$notification->notification_id})"
            );
        }
    }

    /**
     * Dispatch a fake/test notification to the configured test webhook URL only.
     *
     * Called from `dispatch()` when the notification_id is in TestDataGenerator's
     * safe range (8e18+). Skips the production binding lookup entirely so test
     * traffic cannot reach real webhooks.
     *
     * Adds a "[TEST INJECTION]" banner to the embed so anyone receiving the
     * webhook can immediately tell this is not a real attack alert.
     */
    private function dispatchTestNotification($notification, string $category): void
    {
        $testUrl = StructureManagerSettings::get('test_webhook_url', '');
        if (empty($testUrl) || !WebhookConfiguration::isValidWebhookUrl($testUrl)) {
            Log::info(sprintf(
                'StructureEventHandler: test notification %s (%s) — no test_webhook_url configured; board+EventBus already fired',
                $notification->notification_id ?? '?',
                $notification->type
            ));
            return;
        }

        $payload = $this->buildPayload($notification);
        if ($payload === null) {
            return;
        }

        $payload = $this->addTestBanner($payload);

        try {
            Http::connectTimeout(5)->timeout(10)->post($testUrl, $payload);
            Log::info(sprintf(
                'StructureEventHandler: dispatched test notification %s (%s) to test webhook',
                $notification->notification_id ?? '?',
                $notification->type
            ));
        } catch (\Throwable $e) {
            Log::error('StructureEventHandler: test webhook failed: ' . $e->getMessage());
        }
    }

    /**
     * Stamp a clear "[TEST INJECTION]" indicator on the embed so test traffic
     * is unmistakable when it arrives in Discord.
     */
    private function addTestBanner(array $payload): array
    {
        // Prepend to top-level content
        $existingContent = $payload['content'] ?? '';
        $payload['content'] = "\u{1F9EA} **[TEST INJECTION]** " . $existingContent;

        // And to the first embed's title + footer (in case the content gets stripped by relays)
        if (isset($payload['embeds'][0])) {
            $title = $payload['embeds'][0]['title'] ?? '';
            $payload['embeds'][0]['title'] = '[TEST] ' . $title;

            $footer = $payload['embeds'][0]['footer']['text'] ?? '';
            $payload['embeds'][0]['footer'] = [
                'text' => 'Structure Manager test injection — ignore for real intel | ' . $footer,
            ];
        }

        return $payload;
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

        // ESCALATION CLEANUP: if this notification represents an actual state
        // transition (reinforce_armor / reinforce_hull / destroyed), the
        // structure has progressed BEYOND just "being shot" — any active
        // under_attack row for the same structure is now obsolete. Dismiss
        // it proactively so the operator sees a single authoritative row
        // (the reinforce_armor with the real state_timer_end) instead of
        // two competing entries.
        //
        // Mirrors what PruneStructureBoardTimers eventually does on the
        // 15-min timeline, but fires immediately on escalation so the
        // board reflects in-game truth without waiting for the next prune
        // tick. This is the "did escalate" branch of Matt's spec — the
        // "didn't escalate" branch is the standard prune Stage 1.
        if (in_array($eventType, ['reinforce_armor', 'reinforce_hull', 'destroyed'], true)) {
            $entityId = $this->readEntityId($notification->parsed_data ?? []);
            if ($entityId !== null) {
                try {
                    $dismissedCount = Timer::query()
                        ->whereNull('dismissed_at')
                        ->where('event_type', 'under_attack')
                        ->where('structure_id', $entityId)
                        ->get()
                        ->each(function ($timer) {
                            // Eloquent save() so TimerObserver fires the
                            // structure_manager.timer.dismissed event for
                            // any cross-plugin subscribers.
                            $timer->dismissed_at = now();
                            $timer->save();
                        })
                        ->count();
                    if ($dismissedCount > 0) {
                        Log::info(sprintf(
                            'StructureEventHandler: escalation to %s for structure %d dismissed %d active under_attack row(s)',
                            $eventType,
                            $entityId,
                            $dismissedCount
                        ));
                    }
                } catch (\Throwable $e) {
                    Log::warning("StructureEventHandler: escalation cleanup for structure {$entityId} failed: " . $e->getMessage());
                    // Non-fatal — proceed with creating the new reinforce row
                }
            }
        }

        $data = $notification->parsed_data ?? [];
        $meta = $this->resolveStructureMeta($data);

        // Resolve the actual reinforce timer from the corporation_structures
        // row if present. Falls back to the notification timestamp for
        // non-reinforce events or when the structure isn't in our DB.
        $eveTime = $this->resolveEveTimeForEvent($notification, $eventType, $data);

        $severity = match (true) {
            str_starts_with($eventType, 'reinforce_') => 'critical',
            $eventType === 'destroyed' => 'critical',
            // Live sov combat — entosis happening now, command nodes spawned.
            // Operator wants the same urgency level as a reinforce timer
            // because the timeline to react is short (40min entosis window,
            // command-node phase ~4h).
            $eventType === 'entosis_in_progress' || $eventType === 'command_node_spawned' => 'critical',
            // Sov reinforce is a planning event — typically 24-48h until
            // decloak. Warning severity matches the anchor_start treatment.
            $eventType === 'sov_reinforced' => 'warning',
            // Under-attack: structure is being shot but may not escalate to
            // reinforce. Warning (not critical) — operator wants visibility
            // but treating every potshot as critical would desensitize them.
            $eventType === 'under_attack' => 'warning',
            $eventType === 'anchor_start' || $eventType === 'unanchor_start' => 'warning',
            default => 'info',
        };

        // Use centralized readers so AllAnchoringMsg (capital S sysID, flat
        // typeID), OwnershipTransferred (capital S sysID), and Skyhook* events
        // (skyhookID instead of structureID) all populate the board row
        // correctly. Pre-audit, all three types ended up with NULL structure_id,
        // structure_type_id and/or system_id on the board.
        $structureTypeId = $this->readStructureTypeId($data);
        $entityId        = $this->readEntityId($data);
        $systemId        = $this->readSystemId($data);

        // Sov fallback. SovStructureReinforced + SovCommandNodeEventStarted
        // don't carry structureTypeID in their YAML — they use
        // campaignEventType (1=TCU, 2=IHub) as the type discriminator.
        // Without this branch, sov board rows render as "Unknown Structure"
        // with the Astrahus default image because resolveStructureMeta
        // can't find a typeName to populate $meta['type'].
        if (in_array($eventType, ['sov_reinforced', 'command_node_spawned'], true)) {
            if ($structureTypeId === null || empty($meta['type'])) {
                $sovFallback = $this->resolveSovStructureTypeFallback($data);
                if ($structureTypeId === null && $sovFallback['typeId'] !== null) {
                    $structureTypeId = $sovFallback['typeId'];
                }
                if (empty($meta['type']) && $sovFallback['typeName'] !== null) {
                    $meta['type'] = $sovFallback['typeName'];
                }
            }
        }

        // Owner corp = our corp for attack events, same for anchor/unanchor
        $ownerName = DB::table('corporation_infos')
            ->where('corporation_id', $notification->corporation_id)
            ->value('name');

        // Sov OWNER override. Sov structures (IHubs / Sovereignty Hubs / TCUs)
        // belong to ALLIANCES in EVE, not to individual corps. The notification
        // arrives because someone in our corp is a director with the
        // structure_markets / sov scopes, but the receiver corp isn't the
        // entity that actually holds the sov. For sov events we want the
        // board to show "Owner: <Alliance Name>" so operators reading the
        // board immediately see whose sov is in danger.
        //
        // We look up the receiver corp's alliance_id and resolve via
        // IdResolver (3-tier: local DB -> universe_names -> public ESI), so
        // even alliances SeAT has never seen before get a name eventually.
        // Falls through to the corp name silently when the corp isn't in an
        // alliance (highsec corps or just an unfortunate config).
        if (in_array($eventType, ['sov_reinforced', 'command_node_spawned', 'entosis_in_progress'], true)) {
            $allianceId = DB::table('corporation_infos')
                ->where('corporation_id', $notification->corporation_id)
                ->value('alliance_id');
            if ($allianceId) {
                $allianceName = IdResolver::allianceName((int) $allianceId);
                if ($allianceName) {
                    $ownerName = $allianceName;
                }
            }
        }

        // Secondary party — meaning depends on event_type:
        //   reinforce_*, destroyed, sov_*, entosis_*, command_node_spawned
        //     -> attacker corp (CCP's corpName field, when present)
        //   ownership_transferred
        //     -> the NEW owner corp. CCP carries newOwnerCorpID + charID;
        //        resolve via IdResolver (cached, falls back to public ESI)
        //        so the board card shows "Transferred to: X Corp" instead
        //        of leaving the line blank.
        $attackerName = $data['corpName'] ?? null;
        if ($eventType === 'ownership_transferred' && empty($attackerName)) {
            $newOwnerCorpId = $data['newOwnerCorpID'] ?? $data['newOwnerCorpId'] ?? null;
            if ($newOwnerCorpId && is_numeric($newOwnerCorpId)) {
                $attackerName = IdResolver::corporationName((int) $newOwnerCorpId);
            }
        }

        try {
            Timer::upsertAuto([
                'source'                    => $this->sourceForNotificationType($notification->type),
                'event_type'                => $eventType,
                'severity'                  => $severity,
                'structure_id'              => $entityId,
                'structure_name'            => $meta['name'],
                'structure_type'            => $meta['type'],
                'structure_type_id'         => $structureTypeId,
                'system_id'                 => $systemId,
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
            // StructureUnderAttack / SkyhookUnderAttack = "being shot RIGHT NOW
            // but shield hasn't dropped to 0 yet". This is NOT a reinforce state
            // (CCP doesn't fire StructureLostShields until shield actually depletes).
            // Map to a distinct event_type so the badge reads "UNDER ATTACK"
            // accurately, and the row auto-dismisses after the ~15-min CCP
            // auto-repair window (handled in PruneStructureBoardTimers).
            //
            // If the attacker keeps going and shields DO drop to 0, CCP fires
            // StructureLostShields which creates a SEPARATE reinforce_armor row
            // with the real state_timer_end. The two rows coexist briefly until
            // the under_attack one auto-dismisses.
            'StructureUnderAttack', 'SkyhookUnderAttack'  => 'under_attack',
            'StructureLostShields', 'SkyhookLostShields'  => 'reinforce_armor',
            'StructureLostArmor'                          => 'reinforce_hull',
            // Destruction is the TERMINAL phase, not another reinforce cycle.
            // Previously mapped to reinforce_hull which made the board badge
            // read "REINFORCE HULL" for a killed structure. Separated so the
            // badge correctly reads "DESTROYED" and the row's eve_time =
            // notification timestamp (the moment of destruction) without
            // hunting for a state_timer_end that no longer exists.
            'StructureDestroyed', 'SkyhookDestroyed'      => 'destroyed',
            'StructureAnchoring', 'AllAnchoringMsg', 'SkyhookDeployed' => 'anchor_start',
            'StructureUnanchoring'                        => 'unanchor_start',
            'OwnershipTransferred'                        => 'ownership_transferred',
            // Sovereignty events (sov group). The EventBus alert publish covers
            // these already; this mapping also writes a Structure Board row so
            // operators see live + upcoming sov activity on the same calendar
            // they use for fuel / refit / reinforce timers.
            'SovStructureReinforced'                      => 'sov_reinforced',
            'EntosisCaptureStarted'                       => 'entosis_in_progress',
            'SovCommandNodeEventStarted'                  => 'command_node_spawned',
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
            // Sov source label keeps the board's "where did this row come from"
            // column readable when admins audit the timer table.
            str_contains($type, 'Sov') || str_contains($type, 'Entosis')
                => 'auto_sov',
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
        // 2026-05-12: extended to cover tactical-planning event types so
        // Discord Pings (and future fleet-planning consumers) can subscribe
        // to a single pattern for "things that need FC coverage". The new
        // flavors all carry a `timer_ends_at` (where applicable) computed
        // by computeTimerEndsAt() — uniform across reinforce / anchoring /
        // sov so subscribers don't have to know which CCP field encoded it.
        $alertFlavor = match ($notification->type) {
            'StructureLostShields', 'SkyhookLostShields'        => 'shield_reinforced',
            'StructureLostArmor'                                => 'armor_reinforced',
            'StructureDestroyed', 'SkyhookDestroyed'            => 'destroyed',
            'StructureAnchoring', 'AllAnchoringMsg'             => 'anchoring_started',
            'SovStructureReinforced'                            => 'sov_reinforced',
            'EntosisCaptureStarted', 'SovCommandNodeEventStarted' => 'entosis_in_progress',
            default                                              => null,
        };

        if ($alertFlavor === null) {
            return;
        }

        if (!class_exists('\\ManagerCore\\Services\\EventBus')) {
            return;
        }

        $data = $notification->parsed_data ?? [];
        $meta = $this->resolveStructureMeta($data);

        // Accept structureID (Upwell) OR skyhookID (Skyhook* family).
        // Without this, SkyhookDestroyed and SkyhookLostShields would map to
        // alertFlavor='destroyed'/'shield_reinforced' but never publish on the
        // EventBus, breaking any cross-plugin subscriber for skyhook events.
        //
        // 2026-05-12: AllAnchoringMsg is allowed through without a
        // structure_id because it's a system-wide alliance warning ("someone
        // is anchoring SOMETHING in your space, 24h to react"). Subscribers
        // can still calendar it via timer_ends_at + system_id even without
        // a per-structure target.
        $structureId = $this->readEntityId($data);
        if ($structureId === null && $notification->type !== 'AllAnchoringMsg') {
            // Without an entity ID downstream subscribers can't act — drop
            // rather than publish a malformed event. AllAnchoringMsg is the
            // documented exception (system-wide warning, no per-structure ID).
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

        // Resolve the timer-end timestamp via the unified helper. This handles
        // three CCP encodings that previously had separate code paths:
        //   1. corporation_structures.state_timer_end (refreshed every ESI poll)
        //      — most authoritative for Upwell shield/armor reinforce events
        //   2. data['decloakTime'] (Microsoft FILETIME absolute, 100-ns ticks
        //      since 1601-01-01) — used by SovStructureReinforced
        //   3. data['timeLeft'] (CCP 100-ns duration ticks, relative to the
        //      notification timestamp) — used by Anchoring / Unanchoring
        // Returns null when no timer applies (e.g. EntosisCaptureStarted is
        // a "react NOW" event with no future deadline).
        $timerEndsAt = $this->computeTimerEndsAt($notification, $data, $structureId);

        $typeId = null;
        if ($structureId !== null) {
            $structureRow = DB::table('corporation_structures')
                ->where('structure_id', $structureId)
                ->select('type_id')
                ->first();
            if ($structureRow) {
                $typeId = (int) $structureRow->type_id;
            }
        }
        // Fallback: type_id from notification YAML via the centralized reader
        // (handles structureShowInfoData[1] / structureTypeID / typeID chain).
        if ($typeId === null) {
            $typeId = $this->readStructureTypeId($data);
        }

        // Strip security to a float (resolveStructureMeta formats it for display)
        $systemId = $this->readSystemId($data);
        $systemSecurity = null;
        if ($systemId !== null) {
            $sec = DB::table('mapDenormalize')
                ->where('itemID', $systemId)
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

        // Resolve attacker identity via the unified IdResolver. Three-tier
        // chain: SeAT's character_infos / universe_names → 7-day Laravel
        // cache → public ESI fetch with 2s timeout. Cache miss on a fresh
        // attacker adds ~250ms to first-time alert dispatch which is
        // acceptable for a critical security ping. All subsequent attacks
        // by the same pilot resolve instantly from cache or local DB.
        // On total failure (ESI down + no local data) the name stays null
        // and the embed renders the "Pilot ID #N (name not cached)" form
        // exactly as it did before.
        $attackerCharacterName = $attackerCharacterId
            ? IdResolver::characterName($attackerCharacterId)
            : null;

        // Backfill attacker corp/alliance names from ESI when CCP's YAML
        // omitted them (older notification formats, sov events, etc.).
        // YAML-supplied values always win over the resolver to preserve
        // exactly what CCP said at the time of the event.
        if (!$attackerCorporation && $attackerCorporationId) {
            $attackerCorporation = IdResolver::corporationName($attackerCorporationId);
        }
        if (!$attackerAlliance && $attackerAllianceId) {
            $attackerAlliance = IdResolver::allianceName($attackerAllianceId);
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
            'system_id'                 => $systemId,
            'system_name'               => $meta['system'] ? preg_replace('/\s*\([^)]*\)\s*$/', '', $meta['system']) : null,
            'system_security'           => $systemSecurity,
            // Severity per flavor:
            //   shield/armor/destroyed/sov_reinforced/entosis = critical (live combat or last call)
            //   anchoring_started                              = warning (24h timer, planning event)
            'severity'                  => match ($alertFlavor) {
                'anchoring_started' => 'warning',
                default             => 'critical',
            },
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

            // Final-timer flag: true when this structure type has NO separate
            // hull reinforce timer (Astrahus/Raitaru/Athanor mediums, FLEX
            // structures, Metenox, Skyhook). Subscribers (Mining Manager
            // extraction_at_risk, future SeAT Broadcast calendar) can
            // prioritize accordingly. See StructureTimerMechanics for the
            // classification list.
            //
            // Computed from the BOARD event_type we'd produce for this
            // notification (e.g. StructureLostShields → reinforce_armor → may
            // be final for mediums). Defaults false when typeId is unknown,
            // which is the safer error — under-warning beats mislabeling a
            // Fortizar as final.
            'is_final_timer' => \StructureManager\Helpers\StructureTimerMechanics::isFinalTimer(
                $this->notificationTypeToBoardEvent($notification->type) ?? '',
                $typeId
            ),
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
            // C4: publishSanitized auto-escapes Discord-bound text so a hostile
            // structure name like "@everyone HQ" can't trigger mass pings in
            // any subscriber that renders payload values verbatim.
            app(\ManagerCore\Services\EventBus::class)->publishSanitized(
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

        // Attacker threat intel dispatch was previously here but moved to
        // the top-level dispatch() method (via dispatchAttackerThreatIntelIfApplicable).
        // Reason: this function early-returns for notification types that
        // have no alertFlavor (notably StructureUnderAttack which has no
        // mapped flavor) — so threat intel dispatched here would never
        // fire for the most common attack signal. The top-level dispatch
        // path runs for every notification type regardless.
    }

    /**
     * Fire-and-forget dispatch of the attacker threat intel async job.
     *
     * Runs in the main dispatch flow (NOT inside publishStructureAlertEvent)
     * because we want enrichment for ALL attack-type notifications including
     * StructureUnderAttack — which has no alertFlavor mapping and so doesn't
     * trigger an EventBus publish, but still carries an attacker char_id
     * worth profiling.
     *
     * Gating:
     *   - Notification type must be an attack flavor that includes attacker
     *     character_id from CCP (so NOT destroyed — CCP sends attacker info
     *     only via killmail, not the destroyed notification YAML).
     *   - Attacker character_id must be present and valid.
     *   - The job itself checks the master setting + category bindings and
     *     no-ops if either is off. We don't gate here so toggling the
     *     setting from off to on takes effect on the NEXT attack without
     *     needing to redeploy the plugin.
     *
     * The job is dispatched asynchronously (queued). It never blocks the
     * primary alert dispatch path.
     */
    private function dispatchAttackerThreatIntelIfApplicable($notification): void
    {
        // Attack types that include attacker char_id. Excludes Destroyed
        // (CCP doesn't include attacker in destroyed notification YAML —
        // attacker info comes from killmail enrichment in EnrichKillmailJob).
        $eligibleTypes = [
            'StructureUnderAttack',
            'StructureLostShields',
            'StructureLostArmor',
            'SkyhookUnderAttack',
            'SkyhookLostShields',
            'SovStructureReinforced',
            'EntosisCaptureStarted',
            'SovCommandNodeEventStarted',
        ];
        if (!in_array($notification->type, $eligibleTypes, true)) {
            return;
        }

        $data = $notification->parsed_data ?? [];
        $charId = isset($data['charID']) && is_numeric($data['charID'])
            ? (int) $data['charID']
            : 0;
        if ($charId <= 0) {
            // No attacker char_id in the notification YAML — nothing to look
            // up on zKB. Common for some sov events; not a bug.
            return;
        }

        // Resolve metadata for the embed (same fields the alert-side
        // publishStructureAlertEvent uses). Best-effort — missing fields
        // render as "Unknown" in the embed rather than blocking dispatch.
        $meta = $this->resolveStructureMeta($data);
        $typeId = $this->readStructureTypeId($data);

        $attackerCorpName     = $data['corpName'] ?? null;
        $attackerCorpId       = isset($data['corpID']) && is_numeric($data['corpID'])
            ? (int) $data['corpID']
            : null;
        $attackerAllianceName = $data['allianceName'] ?? null;
        $attackerAllianceId   = null;
        if (isset($data['allianceID']) && is_numeric($data['allianceID'])) {
            $attackerAllianceId = (int) $data['allianceID'];
        } elseif (isset($data['aggressorAllianceID']) && is_numeric($data['aggressorAllianceID'])) {
            $attackerAllianceId = (int) $data['aggressorAllianceID'];
        }

        // Correlation key — the notification ID is the most stable identifier
        // (matches what the primary alert footer shows). Falls back to a
        // unix-ts placeholder if for some reason notification_id is missing.
        $correlationId = 'notif:' . ($notification->notification_id ?? ('t' . time()));

        // Flavor label for the embed title — describes what kind of attack
        // the intel is enriching. Distinct from the EventBus alertFlavor
        // map because we need a human-readable string for StructureUnderAttack
        // too (which alertFlavor doesn't cover).
        $flavorLabel = match ($notification->type) {
            'StructureUnderAttack', 'SkyhookUnderAttack' => 'under_attack',
            'StructureLostShields', 'SkyhookLostShields' => 'shield_reinforced',
            'StructureLostArmor'                          => 'armor_reinforced',
            'SovStructureReinforced'                      => 'sov_reinforced',
            'EntosisCaptureStarted'                       => 'entosis_in_progress',
            'SovCommandNodeEventStarted'                  => 'entosis_in_progress',
            default                                       => 'attack',
        };

        try {
            \StructureManager\Jobs\DispatchAttackerThreatIntel::dispatch(
                $charId,
                isset($notification->corporation_id) ? (int) $notification->corporation_id : null,
                $flavorLabel,
                $correlationId,
                [
                    'structure_name'         => $meta['name']        ?? null,
                    'structure_type'         => $meta['type']        ?? null,
                    'structure_type_id'      => $typeId,
                    'system_name'            => $meta['system_name'] ?? ($meta['system'] ?? null),
                    'attacker_corp_name'     => $attackerCorpName,
                    'attacker_alliance_name' => $attackerAllianceName,
                    'attacker_corp_id'       => $attackerCorpId,
                    'attacker_alliance_id'   => $attackerAllianceId,
                ]
            );
        } catch (\Throwable $e) {
            // Non-fatal — primary alert path is unaffected. Log + move on.
            Log::warning(sprintf(
                'StructureEventHandler: failed to dispatch attacker threat intel for char %d (notif %s): %s',
                $charId,
                $notification->notification_id ?? 'unknown',
                $e->getMessage()
            ));
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
        // For reinforce progression, prefer the state_timer_end on the structure.
        // Read entity ID via the centralized helper so skyhooks (skyhookID) work
        // alongside Upwell (structureID).
        $entityId = $this->readEntityId($data);
        if (in_array($eventType, ['reinforce_armor', 'reinforce_hull'], true) && $entityId !== null) {
            $timerEnd = DB::table('corporation_structures')
                ->where('structure_id', $entityId)
                ->value('state_timer_end');
            if ($timerEnd) {
                return Carbon::parse($timerEnd);
            }
        }

        // Under-attack: CCP's StructureUnderAttack doesn't carry a future
        // deadline because the structure may auto-repair if the attacker stops.
        // The in-game auto-repair window after the last hit is ~15 minutes.
        // Use notification_timestamp + 15 min as the "deadline" — when this
        // elapses without a follow-up StructureLostShields, PruneStructureBoardTimers
        // auto-dismisses the row.
        if ($eventType === 'under_attack') {
            return Carbon::parse($notification->timestamp)->addMinutes(15);
        }

        // Entosis-in-progress: CCP's EntosisCaptureStarted is a "happening
        // right now" signal with no future deadline. A single entosis cycle
        // is 5-25 min depending on link tier; 60 min covers ~2 cycles
        // including defender interrupts. After this window, the row
        // auto-dismisses via PruneStructureBoardTimers Stage 1 (zero-grace
        // fast-dismiss). New cycles on other nodes fire fresh notifications
        // and create their own rows.
        if ($eventType === 'entosis_in_progress') {
            return Carbon::parse($notification->timestamp)->addMinutes(60);
        }

        // Sov events with explicit future deadlines carry CCP's `decloakTime`
        // field as a Microsoft FILETIME absolute (100-ns ticks since 1601-01-01
        // UTC). Convert to Unix seconds and use as eve_time so the Structure
        // Board renders a real countdown.
        //   sov_reinforced       — node decloak when the campaign begins
        //   command_node_spawned — campaign event start (= decloakTime)
        // entosis_in_progress has no future deadline (40-min capture window
        // measured from notification timestamp, no CCP-supplied end-time
        // field), so it falls through to the notification.timestamp branch.
        if (in_array($eventType, ['sov_reinforced', 'command_node_spawned'], true)
            && !empty($data['decloakTime']) && is_numeric($data['decloakTime'])) {
            $unixSeconds = (int) (((int) $data['decloakTime']) / 10_000_000) - 11_644_473_600;
            if ($unixSeconds > 0) {
                return Carbon::createFromTimestamp($unixSeconds, 'UTC');
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

        // Final-timer detection — when StructureLostShields fires on a medium /
        // FLEX / Metenox / Skyhook (no separate hull reinforce timer), this
        // is the LAST defense window. Surface that prominently in the embed
        // so FCs reading the alert immediately understand the stakes.
        // Computed via StructureTimerMechanics; null when not applicable.
        $boardEventType = $this->notificationTypeToBoardEvent($type) ?? '';
        $structureTypeId = $this->readStructureTypeId($data);
        $finalTimerBadge = \StructureManager\Helpers\StructureTimerMechanics::finalTimerBadge(
            $structureTypeId,
            $boardEventType
        );
        $finalTimerMessage = \StructureManager\Helpers\StructureTimerMechanics::finalTimerMessage(
            $structureTypeId,
            $boardEventType
        );

        // Append "[FINAL TIMER]" to the title for the structures with no
        // hull reinforce break. Keeps Discord embed scan-readable.
        if ($finalTimerBadge !== null) {
            $titleSuffix .= " \u{2014} \u{26A0} {$finalTimerBadge}";
        }

        $fields = [];
        $fields[] = ['name' => "\u{1F4CD} Location", 'value' => $meta['system'] ?? 'Unknown', 'inline' => true];
        $fields[] = ['name' => 'Structure Type', 'value' => $meta['type'] ?? 'Unknown', 'inline' => true];
        $fields[] = ['name' => "\u{23F0} Last Update", 'value' => Carbon::parse($notification->timestamp)->diffForHumans(), 'inline' => true];

        // Dedicated FINAL TIMER warning field — appears at the top of the
        // embed (after Location/Type/Last Update). Operators reading from
        // mobile or scanning many embeds need this visible without
        // clicking through. Renders as a single full-width field with
        // a clear message about the stakes.
        if ($finalTimerMessage !== null) {
            $fields[] = [
                'name'   => "\u{1F6A8} FINAL TIMER",
                'value'  => $finalTimerMessage,
                'inline' => false, // full-width — this is the most important field
            ];
        }

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

        // StructureDestroyed/SkyhookDestroyed are SEMANTICALLY DIFFERENT from
        // the attack-progression notifications: CCP doesn't include the
        // attacker (the kill already happened, attacker info goes via
        // killmail/zKB). Instead these notifications carry the LOST OWNER:
        //   ownerCorpName       — the corp that lost the structure
        //   ownerCorpLinkData[2] — owner corp ID
        // Surface that block instead of the attacker block.
        $isDestroyed = in_array($type, ['StructureDestroyed', 'SkyhookDestroyed'], true);

        if ($isDestroyed) {
            // Owner-block resolution chain (defensively ordered):
            //   1. ownerCorpName (CCP's preferred field for Destroyed)
            //   2. corpName (defensive — if a future CCP variant uses generic field)
            //   3. resolve from ownerCorpLinkData[2] / corpID via universe_names
            $ownerId = $this->readLinkDataCorpId($data['ownerCorpLinkData'] ?? null);
            if ($ownerId === null && !empty($data['corpID']) && is_numeric($data['corpID'])) {
                $ownerId = (int) $data['corpID'];
            }
            $ownerName = $data['ownerCorpName']
                      ?? $data['corpName']
                      ?? ($ownerId !== null ? $this->resolveCorporationName($ownerId) : null);

            if (!empty($ownerName) || $ownerId !== null) {
                $label = $ownerName ?: ("Corp #{$ownerId} *(name not cached)*");
                $value = $ownerId !== null
                    ? "[{$label}](https://zkillboard.com/corporation/{$ownerId}/)"
                    : $label;
                $fields[] = [
                    'name'   => "\u{1F480} Lost Owner",
                    'value'  => $value,
                    'inline' => true,
                ];
            }
        } else {
            // Attack-progression notifications (UnderAttack, LostShields, LostArmor):
            // CCP carries the attacker character + corp + alliance.
            //
            // Attacker pilot — `charID` field. Resolve via IdResolver (three
            // tiers: character_infos → universe_names → public ESI fetch with
            // 7-day cache). Falls back to ID-only rendering when ESI is also
            // unreachable, preserving the legacy "name not cached" form.
            if (!empty($data['charID']) && is_numeric($data['charID'])) {
                $charId = (int) $data['charID'];
                $pilotName = IdResolver::characterName($charId);
                $fields[] = [
                    'name'   => "\u{1F464} Attacker Pilot",
                    'value'  => $pilotName
                        ? "[{$pilotName}](https://zkillboard.com/character/{$charId}/)"
                        : "Pilot ID #{$charId} *(name not cached)*",
                    'inline' => true,
                ];
            }

            // Attacker corp. CCP carries `corpName` directly; attacker corp ID
            // can come via `corpID` OR `corpLinkData[2]` (a 3-element array
            // [showInfoType=2, 0, corpID] — same convention as
            // structureShowInfoData). Either form produces the zKB link.
            $attackerCorpName = $data['corpName'] ?? null;
            $attackerCorpId   = (!empty($data['corpID']) && is_numeric($data['corpID']))
                ? (int) $data['corpID']
                : $this->readLinkDataCorpId($data['corpLinkData'] ?? null);
            // CCP's YAML usually carries corpName directly. When it doesn't
            // (older notification formats, sov events) backfill via IdResolver
            // so the embed gets a real name instead of dropping the field
            // entirely.
            if (empty($attackerCorpName) && !empty($attackerCorpId)) {
                $attackerCorpName = IdResolver::corporationName($attackerCorpId);
            }
            if (!empty($attackerCorpName)) {
                $value = !empty($attackerCorpId)
                    ? "[{$attackerCorpName}](https://zkillboard.com/corporation/{$attackerCorpId}/)"
                    : $attackerCorpName;
                $fields[] = ['name' => 'Attacker Corp', 'value' => $value, 'inline' => true];
            }

            // Attacker alliance. SeAT gates on `aggressorAllianceID` (this is
            // the field CCP sets when the attacker IS in an alliance — its
            // mere presence indicates "this is alliance combat"). Falling back
            // to allianceName presence covers older payloads + my fake test
            // builder which uses allianceName/allianceID directly. When CCP
            // gives only the alliance ID, backfill the name via IdResolver.
            $hasAlliance = !empty($data['aggressorAllianceID'])
                        || !empty($data['allianceName'])
                        || !empty($data['allianceID']);
            if ($hasAlliance) {
                $aId = $data['allianceID'] ?? $data['aggressorAllianceID'] ?? null;
                $aName = $data['allianceName'] ?? null;
                if (empty($aName) && !empty($aId) && is_numeric($aId)) {
                    $aName = IdResolver::allianceName((int) $aId);
                }
                if (!empty($aName)) {
                    $value = !empty($aId)
                        ? "[{$aName}](https://zkillboard.com/alliance/{$aId}/)"
                        : $aName;
                    $fields[] = ['name' => 'Attacker Alliance', 'value' => $value, 'inline' => true];
                }
            }
        }

        // Skyhook* events anchor on a planet — surface the planet name
        // when CCP includes planetID. No-op for non-skyhook notifications.
        $this->addPlanetFieldIfPresent($fields, $data);

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

        $content = $contentTitles[$type] ?? "**{$titleSuffix}**";

        // For FINAL TIMER events on structures with no hull reinforce break,
        // amplify the content line so the Discord push notification preview
        // (which shows the content, not the embed) carries the stakes. Some
        // FCs only see the push popup before clicking through — they must
        // know this is the last chance to defend.
        if ($finalTimerBadge !== null) {
            $content .= " \u{1F6A8} **{$finalTimerBadge}** \u{2014} no hull reinforce follows.";
        }

        return [
            'content' => $content,
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

        // Note: OwnershipTransferred's alternate field names (solarSystemID,
        // structureTypeID, structureName) are now handled globally in
        // resolveStructureMeta() — meta arrives correctly populated.

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
        // anchoring vulnerability window. CCP cites this in 100-ns ticks the
        // same way as timeLeft. Per SeAT's templates, AllAnchoringMsg does
        // NOT carry vulnerableTime — only StructureAnchoring + SkyhookDeployed.
        if (in_array($type, ['StructureAnchoring', 'SkyhookDeployed'], true)
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

        // Owning corporation. CCP's lifecycle YAML carries different fields
        // per type — we accept any of the three conventions in priority order:
        //   1. ownerCorpName       — StructureAnchoring, StructureUnanchoring (the inline name)
        //   2. ownerCorpLinkData[2]/corpID   — Anchoring/Unanchoring (resolve via universe_names)
        //   3. corpName            — AllAnchoringMsg (system-wide warning, sov-style fields)
        //   4. corpID              — AllAnchoringMsg (resolve via universe_names if no inline name)
        // Each type only sends one or two of these — the chain picks whichever
        // is present.
        if (in_array($type, ['StructureAnchoring', 'StructureUnanchoring', 'AllAnchoringMsg'], true)) {
            $corpName = $data['ownerCorpName'] ?? $data['corpName'] ?? null;
            $corpId   = $this->readLinkDataCorpId($data['ownerCorpLinkData'] ?? null);
            if ($corpId === null && !empty($data['corpID']) && is_numeric($data['corpID'])) {
                $corpId = (int) $data['corpID'];
            }
            if (empty($corpName) && $corpId !== null) {
                $corpName = $this->resolveCorporationName($corpId);
            }
            if (!empty($corpName) || $corpId !== null) {
                $label = $corpName ?: ('Corp #' . $corpId . ' *(name not cached)*');
                $value = $corpId !== null
                    ? "[{$label}](https://zkillboard.com/corporation/{$corpId}/)"
                    : $label;
                $fields[] = ['name' => 'Owning Corporation', 'value' => $value, 'inline' => true];
            }
        }

        // AllAnchoringMsg uniquely carries `moonID` (a structure-anchoring
        // warning is system-wide; the moon tells you exactly where the new
        // structure is going up). Resolve via mapDenormalize for the moon name.
        if ($type === 'AllAnchoringMsg' && !empty($data['moonID']) && is_numeric($data['moonID'])) {
            $moonName = DB::table('mapDenormalize')
                ->where('itemID', $data['moonID'])
                ->value('itemName');
            if (!empty($moonName)) {
                $fields[] = ['name' => "\u{1F315} Moon", 'value' => $moonName, 'inline' => true];
            }
        }

        // OwnershipTransferred: surface the actual transfer. CCP YAML carries:
        //   oldOwnerCorpID  — the corp the structure used to belong to
        //   newOwnerCorpID  — the corp it now belongs to
        //   charID          — the character that initiated the transfer
        //
        // CCP does NOT include corp/character names — we resolve them locally:
        //   1. corporation_infos (set on every SeAT install for tracked corps)
        //   2. universe_names (SeAT's general entity-name cache, populated
        //      whenever any tool resolves an entity)
        //   3. fall back to "Corp #ID" so operators can still verify externally
        //
        // Same resolution path SeAT core's notifications module uses.
        if ($type === 'OwnershipTransferred') {
            // Always render the field when the ID is present, even if name
            // lookup misses — operators can verify the corp externally and
            // the ID itself is the actionable info.
            $oldId = isset($data['oldOwnerCorpID']) ? (int) $data['oldOwnerCorpID'] : 0;
            $newId = isset($data['newOwnerCorpID']) ? (int) $data['newOwnerCorpID'] : 0;
            $oldName = $oldId > 0 ? $this->resolveCorporationName($oldId) : null;
            $newName = $newId > 0 ? $this->resolveCorporationName($newId) : null;

            if ($oldId > 0) {
                $label = $oldName ?: "Corp #{$oldId} *(name not cached)*";
                $fields[] = [
                    'name'   => "\u{1F4E4} Old Corporation",
                    'value'  => "[{$label}](https://zkillboard.com/corporation/{$oldId}/)",
                    'inline' => true,
                ];
            }
            if ($newId > 0) {
                $label = $newName ?: "Corp #{$newId} *(name not cached)*";
                $fields[] = [
                    'name'   => "\u{1F4E5} New Corporation",
                    'value'  => "[{$label}](https://zkillboard.com/corporation/{$newId}/)",
                    'inline' => true,
                ];
            }

            // Transferring character — CCP gives charID; resolve via IdResolver
            // (character_infos → universe_names → ESI fetch with 7-day cache).
            if (!empty($data['charID']) && is_numeric($data['charID'])) {
                $charId = (int) $data['charID'];
                $charName = IdResolver::characterName($charId);
                $fields[] = [
                    'name'   => "\u{1F464} Initiated By",
                    'value'  => $charName
                        ? "[{$charName}](https://zkillboard.com/character/{$charId}/)"
                        : "Pilot ID #{$charId} *(name not cached)*",
                    'inline' => true,
                ];
            }
        }

        // Skyhook* deploys/onlines anchor on a planet — surface planetID.
        $this->addPlanetFieldIfPresent($fields, $data);

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

        // SkyhookOnline anchors on a planet — surface planetID when present.
        $this->addPlanetFieldIfPresent($fields, $data);

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

        // Structure Type label resolution mirrors SeAT core's sov templates:
        //
        //   EntosisCaptureStarted     -> InvType lookup via structureTypeID
        //   SovStructureDestroyed     -> InvType lookup via structureTypeID
        //   SovStructureReinforced    -> campaignEventType label (CCP doesn't carry typeID)
        //   SovCommandNodeEventStarted -> campaignEventType label (CCP doesn't carry typeID)
        //
        // Older versions of this code only checked structureTypeID, which left
        // Reinforced/CommandNode with a blank "Structure Type" field because
        // their YAML doesn't include it. Now we fall back to campaignEventType
        // matching SeAT core's NotificationTools::campaignEventType() trait.
        $structureTypeId = $data['structureTypeID'] ?? null;
        $structureTypeName = $structureTypeId ? $this->resolveTypeName((int) $structureTypeId) : null;
        if (empty($structureTypeName) && !empty($data['campaignEventType']) && is_numeric($data['campaignEventType'])) {
            $structureTypeName = match ((int) $data['campaignEventType']) {
                1       => 'Territorial Claim Unit',
                2       => 'Infrastructure Hub',
                3       => 'Outpost',
                default => 'Unknown sovereignty structure',
            };
        }

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

        // Decloak / reinforce timer for SovStructureReinforced.
        //
        // CRITICAL: CCP's `decloakTime` is a Microsoft FILETIME absolute timestamp
        // (100-ns ticks since 1601-01-01 UTC) — NOT a duration. SeAT core's
        // NotificationTools::mssqlTimestampToDate() does the same conversion:
        //   unix_seconds = filetime / 10_000_000 - 11_644_473_600
        //
        // The previous version of this code used formatCcpDuration() which
        // treats the field as a 100-ns duration relative to the notification
        // timestamp — that's correct for Upwell anchoring/unanchoring's
        // `timeLeft` but WRONG for sov `decloakTime` (which is absolute).
        // The bug rendered "0m remaining" because formatCcpDuration's
        // "remaining" calc collapsed to zero on the wrong-sized output.
        if ($type === 'SovStructureReinforced' && !empty($data['decloakTime']) && is_numeric($data['decloakTime'])) {
            $unixSeconds = (int) (((int) $data['decloakTime']) / 10_000_000) - 11_644_473_600;
            if ($unixSeconds > 0) {
                $absolute = Carbon::createFromTimestamp($unixSeconds, 'UTC');
                $now      = Carbon::now('UTC');

                // Correct sign convention: positive when $absolute is still future
                $remainingSecs = max(0, $absolute->getTimestamp() - $now->getTimestamp());
                $remD = intdiv($remainingSecs, 86400);
                $remH = intdiv($remainingSecs % 86400, 3600);
                $remM = intdiv($remainingSecs % 3600, 60);
                $remParts = [];
                if ($remD > 0) { $remParts[] = "{$remD}d"; }
                if ($remH > 0) { $remParts[] = "{$remH}h"; }
                if ($remM > 0 || empty($remParts)) { $remParts[] = "{$remM}m"; }
                $remaining = $absolute->isPast()
                    ? 'decloaked'
                    : implode(' ', $remParts) . ' remaining';

                $fields[] = [
                    'name'   => "\u{23F1} Node Decloak",
                    'value'  => $absolute->format('Y-m-d H:i') . " UTC\n*({$remaining})*",
                    'inline' => true,
                ];
            }
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

    /**
     * Read the system ID from notification YAML, accepting both CCP conventions:
     *   solarsystemID  (lowercase) — Upwell attack/lifecycle/fuel events
     *   solarSystemID  (capital S) — sov family, OwnershipTransferred, AllAnchoringMsg
     *
     * Centralizes the alternate-name handling so every code path that needs
     * the system ID (board upsert, EventBus publish, eve_time resolution,
     * resolveStructureMeta) reads it consistently.
     */
    private function readSystemId(array $data): ?int
    {
        $val = $data['solarsystemID'] ?? $data['solarSystemID'] ?? null;
        return is_numeric($val) ? (int) $val : null;
    }

    /**
     * Read the structure / entity ID from notification YAML.
     *   structureID — Upwell + sov + OwnershipTransferred
     *   skyhookID   — Skyhook* family (skyhooks aren't Upwell structures)
     *
     * Returns null for AllAnchoringMsg (no per-instance ID — system-wide warning).
     */
    private function readEntityId(array $data): ?int
    {
        $val = $data['structureID'] ?? $data['skyhookID'] ?? null;
        return is_numeric($val) ? (int) $val : null;
    }

    /**
     * Read a corp ID from a CCP-shaped linkData array.
     *
     * CCP uses 3-element arrays of the form [showInfoType, 0, entityID] for
     * fields like `corpLinkData`, `ownerCorpLinkData`. The corp ID is at
     * position [2]. We guard with is_array + is_numeric to defend against
     * malformed YAML producing a string or scalar in the slot.
     */
    private function readLinkDataCorpId($linkData): ?int
    {
        if (!is_array($linkData)) {
            return null;
        }
        $val = $linkData[2] ?? null;
        return is_numeric($val) ? (int) $val : null;
    }

    /**
     * Read the structure type ID, walking CCP's three field-name conventions
     * in priority order:
     *   1. structureShowInfoData[1] — UnderAttack, FuelAlert, WentHighPower,
     *      ServicesOffline (3-element array form)
     *   2. structureTypeID — LostShields, LostArmor, Destroyed, WentLowPower,
     *      Anchoring, Unanchoring, sov family (flat int)
     *   3. typeID — AllAnchoringMsg (flat, no "structure" prefix)
     */
    private function readStructureTypeId(array $data): ?int
    {
        $showInfo = $data['structureShowInfoData'] ?? null;
        if (is_array($showInfo) && isset($showInfo[1]) && is_numeric($showInfo[1])) {
            return (int) $showInfo[1];
        }
        $val = $data['structureTypeID'] ?? $data['typeID'] ?? null;
        return is_numeric($val) ? (int) $val : null;
    }

    /**
     * Fallback resolver for sov structures.
     *
     * SovStructureReinforced and SovCommandNodeEventStarted notifications
     * do NOT include structureTypeID in their CCP YAML. They carry
     * campaignEventType (1=TCU, 2=IHub, 3=Outpost) instead, which encodes
     * the sov-structure family. Without this fallback, upsertBoardTimer
     * stores structure_type_id=null and structure_type=null, leaving the
     * Structure Board to display "Unknown Structure" with the Astrahus
     * default image.
     *
     * The Discord embed code (buildSovereigntyPayload) already handles
     * this case inline for the type NAME; this helper also computes the
     * typeID so the board's structure-image accessor renders the correct
     * silhouette.
     *
     * Returns ['typeId' => int|null, 'typeName' => string|null]. Both null
     * when campaignEventType isn't present (e.g. EntosisCaptureStarted
     * which carries structureTypeID directly and doesn't need the fallback).
     *
     * TypeID values:
     *   32226 - Territorial Claim Unit (TCU), legacy, removed from EVE
     *           but still in the SDE; image renders as a dark cube
     *   32458 - Infrastructure Hub (IHub), the current sov structure as
     *           of pre-Equinox. Post-Equinox SDE may rename to "Sovereignty
     *           Hub" but the ID is preserved across SDE versions
     */
    private function resolveSovStructureTypeFallback(array $data): array
    {
        $eventType = $data['campaignEventType'] ?? null;
        if (!is_numeric($eventType)) {
            return ['typeId' => null, 'typeName' => null];
        }
        $typeId = match ((int) $eventType) {
            1       => 32226, // TCU (legacy, but still in the SDE)
            2       => 32458, // post-Equinox CCP renamed Infrastructure Hub
                              // to "Sovereignty Hub" in the SDE. The typeID
                              // is preserved; we resolve the name below.
            default => null,
        };
        if ($typeId === null) {
            // No SDE-mapped type — synthesize a generic label
            return [
                'typeId'   => null,
                'typeName' => match ((int) $eventType) {
                    3       => 'Outpost',
                    default => 'Sovereignty Structure',
                },
            ];
        }
        // Lookup the actual name from THIS server's SDE so the display
        // matches whatever CCP currently calls the type. Without this we'd
        // hardcode 'Infrastructure Hub' even on post-Equinox installs where
        // the SDE says 'Sovereignty Hub'.
        $typeName = DB::table('invTypes')->where('typeID', $typeId)->value('typeName');
        return [
            'typeId'   => $typeId,
            'typeName' => $typeName ?: 'Sovereignty Structure',
        ];
    }

    /**
     * Unified timer-end resolver for structure.alert.* event publishes.
     *
     * Three CCP encodings exist for "when does this event's timer end?". This
     * helper picks the right path per notification family and returns a
     * consistent ISO 8601 string (or null when no timer applies).
     *
     *   Path 1: corporation_structures.state_timer_end
     *     Most authoritative for Upwell shield/armor reinforce events.
     *     SeAT's ESI sync refreshes this field every poll cycle, so it stays
     *     accurate even minutes after the notification arrives. Only available
     *     when we have a $structureId AND the structure is in our local DB
     *     (i.e. not for AllAnchoringMsg which is system-wide).
     *
     *   Path 2: data['decloakTime'] (SovStructureReinforced)
     *     Microsoft FILETIME absolute timestamp (100-ns ticks since 1601-01-01
     *     UTC). NOT a duration. Conversion:
     *       unix_seconds = filetime / 10_000_000 - 11_644_473_600
     *     Mirrors SeAT core's NotificationTools::mssqlTimestampToDate().
     *
     *   Path 3: data['timeLeft'] (Anchoring / Unanchoring / LostShields /
     *           LostArmor fallback)
     *     CCP 100-ns duration ticks relative to the notification timestamp.
     *     Completion time = notification.timestamp + (timeLeft / 10_000_000)s.
     *     Mirrors formatCcpDuration().
     *
     * Returns null when:
     *   - The structure isn't in our DB AND the YAML carries no timer field
     *   - The event is a "react NOW" type with no future deadline (e.g.
     *     EntosisCaptureStarted — the entosis cycle is happening right now)
     *
     * Subscribers calendar the event when timer_ends_at is non-null and
     * treat it as a live "react now" alert when null.
     */
    private function computeTimerEndsAt($notification, array $data, ?int $structureId): ?string
    {
        // Path 1: structure row's state_timer_end (most authoritative)
        if ($structureId !== null) {
            try {
                $stateTimerEnd = DB::table('corporation_structures')
                    ->where('structure_id', $structureId)
                    ->value('state_timer_end');
                if ($stateTimerEnd) {
                    return Carbon::parse($stateTimerEnd)->toIso8601String();
                }
            } catch (\Throwable $e) {
                // Non-fatal — fall through to YAML extraction
            }
        }

        // Path 2: sov decloakTime (Microsoft FILETIME absolute)
        if (!empty($data['decloakTime']) && is_numeric($data['decloakTime'])) {
            $unixSeconds = (int) (((int) $data['decloakTime']) / 10_000_000) - 11_644_473_600;
            if ($unixSeconds > 0) {
                return Carbon::createFromTimestamp($unixSeconds, 'UTC')->toIso8601String();
            }
        }

        // Path 3: CCP 100-ns duration ticks relative to notification timestamp
        if (!empty($data['timeLeft']) && is_numeric($data['timeLeft']) && (int) $data['timeLeft'] > 0) {
            try {
                $base = Carbon::parse($notification->timestamp);
                $seconds = (int) max(0, ((int) $data['timeLeft']) / 10_000_000);
                return $base->copy()->addSeconds($seconds)->toIso8601String();
            } catch (\Throwable $e) {
                // Bad notification timestamp — null out and let subscribers
                // treat as a "react now" alert
                return null;
            }
        }

        return null;
    }

    /**
     * Append a Planet field to the embed when CCP's YAML carries planetID.
     *
     * Used for Skyhook* notifications. Skyhooks anchor on planets (not in
     * space at structures), so the planet is the location anchor rather
     * than a structure name. Resolves planet name via mapDenormalize.
     *
     * No-op when planetID isn't in the data — every other notification type
     * passes through cleanly.
     *
     * @param array $fields  Embed fields array (modified in place)
     * @param array $data    The notification YAML
     */
    private function addPlanetFieldIfPresent(array &$fields, array $data): void
    {
        if (empty($data['planetID']) || !is_numeric($data['planetID'])) {
            return;
        }
        $planetName = DB::table('mapDenormalize')
            ->where('itemID', (int) $data['planetID'])
            ->value('itemName');
        if (empty($planetName)) {
            return;
        }
        $fields[] = [
            'name'   => "\u{1FA90} Planet", // ringed planet emoji
            'value'  => $planetName,
            'inline' => true,
        ];
    }

    /**
     * Resolve a corporation name from an entity ID.
     *
     * Thin wrapper around IdResolver::corporationName() that handles the
     * legacy mixed-type input from old call sites (some pass strings, some
     * ints, some null). The resolver itself does the three-tier lookup:
     *   1. corporation_infos (SeAT's primary cache)
     *   2. universe_names (SeAT's secondary cache)
     *   3. Public ESI fetch with 7-day result caching
     *
     * Returns null if all tiers miss — caller renders "Corp #ID (name not
     * cached)" exactly as before.
     */
    private function resolveCorporationName($corpId): ?string
    {
        if (empty($corpId) || !is_numeric($corpId)) {
            return null;
        }
        return IdResolver::corporationName((int) $corpId);
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

        $sysId = $this->readSystemId($data);
        if ($sysId !== null) {
            $system = DB::table('mapDenormalize')
                ->where('itemID', $sysId)
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

        // Structure name: prefer inline structureName, fall back to
        // universe_structures lookup keyed on structureID OR skyhookID.
        if (!empty($data['structureName'])) {
            $meta['name'] = $data['structureName'];
        } else {
            $entityId = $this->readEntityId($data);
            if ($entityId !== null) {
                $structure = DB::table('universe_structures')
                    ->where('structure_id', $entityId)
                    ->value('name');
                $meta['name'] = $structure;
            }
        }

        $typeId = $this->readStructureTypeId($data);
        if ($typeId !== null) {
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
