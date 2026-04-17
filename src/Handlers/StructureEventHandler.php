<?php

namespace StructureManager\Handlers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use StructureManager\Models\StructureManagerSettings;
use StructureManager\Models\WebhookConfiguration;
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
     */
    private function dispatch($notification): void
    {
        // Respect per-category opt-outs the admin configured in Structure Manager settings.
        $category = $this->getCategory($notification->type);
        if (!$this->isCategoryEnabled($category)) {
            Log::debug("StructureEventHandler: Category {$category} disabled in settings; skipping type {$notification->type}");
            return;
        }

        $webhooks = WebhookConfiguration::getForCorporation($notification->corporation_id);

        if ($webhooks->isEmpty()) {
            Log::debug("StructureEventHandler: No webhooks configured for corp {$notification->corporation_id}");
            return;
        }

        $payload = $this->buildPayload($notification);

        if ($payload === null) {
            return;
        }

        foreach ($webhooks as $webhook) {
            if (!WebhookConfiguration::isValidWebhookUrl($webhook->webhook_url)) {
                continue;
            }

            $roleMention = $this->resolveRoleMention($webhook, $category);
            $finalPayload = $this->injectMention($payload, $roleMention, $category);

            try {
                Http::connectTimeout(5)->timeout(10)->post($webhook->webhook_url, $finalPayload);
            } catch (\Throwable $e) {
                Log::error("StructureEventHandler: Webhook failed for notification type {$notification->type}: " . $e->getMessage());
            }
        }
    }

    private function isCategoryEnabled(string $category): bool
    {
        switch ($category) {
            case 'attack':
                return (bool) StructureManagerSettings::get('notify_structure_attack', true);
            case 'lifecycle':
                return (bool) StructureManagerSettings::get('notify_structure_lifecycle', true);
            case 'fuel':
                return (bool) StructureManagerSettings::get('notify_structure_fuel_events', true);
            default:
                return false;
        }
    }

    private function resolveRoleMention($webhook, string $category): string
    {
        if ($category === 'attack') {
            return StructureManagerSettings::get('esi_attack_role_mention', '') ?: ($webhook->role_mention ?? '');
        }
        return $webhook->role_mention ?? '';
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
