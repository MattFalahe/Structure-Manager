<?php

namespace StructureManager\Handlers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
        'StructureServicesOffline',
        'StructureFuelAlert',
        'StructureLowReagentsAlert',
        'StructureNoReagentsAlert',
        'SkyhookOnline',
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
            self::FUEL_EVENT_TYPES
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
            'attack'    => 'structure_attack',
            'lifecycle' => 'structure_lifecycle',
            'fuel'      => 'structure_fuel_events',
            default     => null,
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

        if (isset($data['corpName'])) {
            $fields[] = ['name' => 'Attacker Corp', 'value' => $data['corpName'] ?? 'Unknown', 'inline' => true];
        }
        if (!empty($data['allianceName'])) {
            $fields[] = ['name' => 'Attacker Alliance', 'value' => $data['allianceName'], 'inline' => true];
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

        if (isset($data['corpName'])) {
            $fields[] = ['name' => 'Corporation', 'value' => $data['corpName'], 'inline' => true];
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

        if (isset($data['listOfTypesAndQty']) && is_array($data['listOfTypesAndQty'])) {
            foreach ($data['listOfTypesAndQty'] as $item) {
                $typeName = $this->resolveTypeName($item[1] ?? 0);
                $qty = $item[0] ?? 0;
                $fields[] = ['name' => $typeName, 'value' => number_format($qty) . ' remaining', 'inline' => true];
            }
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
            'name' => null,
            'type' => null,
            'system' => null,
        ];

        if (isset($data['solarsystemID'])) {
            $system = DB::table('mapDenormalize')
                ->where('itemID', $data['solarsystemID'])
                ->select('itemName', 'security')
                ->first();
            if ($system) {
                $meta['system'] = $system->itemName . ' (' . number_format($system->security, 2) . ')';
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
