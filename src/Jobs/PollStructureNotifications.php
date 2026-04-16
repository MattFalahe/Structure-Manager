<?php

namespace StructureManager\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use StructureManager\Models\EsiNotification;
use StructureManager\Models\EsiKeyHolder;
use StructureManager\Models\StructureManagerSettings;
use StructureManager\Models\WebhookConfiguration;
use Seat\Services\Contracts\EsiClient;
use Seat\Eveapi\Models\RefreshToken;
use Carbon\Carbon;
use Symfony\Component\Yaml\Yaml;

/**
 * Fast ESI polling for structure event notifications.
 *
 * Bypasses SeAT's bucket system by polling the ESI notifications endpoint
 * directly from director characters in a round-robin pattern. With 10
 * directors at 2-minute intervals, detection time drops from 20-30 min
 * (SeAT default) to ~2 minutes.
 *
 * Flow:
 * 1. Pick next director(s) from round-robin queue (least-recently-polled)
 * 2. Call ESI GET /characters/{id}/notifications/ via Eseye
 * 3. Filter for structure-related types
 * 4. Deduplicate by CCP's notification_id against our table
 * 5. For new notifications: build Discord embed, dispatch to webhooks
 * 6. Mark processed
 */
class PollStructureNotifications implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    // Fast retry for time-critical alerts
    public $timeout = 120;
    public $tries = 2;
    public $backoff = [30, 60];

    /**
     * Structure-related notification types we handle, grouped by category.
     */
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
     * All handled types combined.
     */
    private function getAllHandledTypes(): array
    {
        $types = [];

        if (StructureManagerSettings::get('notify_structure_attack', true)) {
            $types = array_merge($types, self::ATTACK_TYPES);
        }
        if (StructureManagerSettings::get('notify_structure_lifecycle', true)) {
            $types = array_merge($types, self::LIFECYCLE_TYPES);
        }
        if (StructureManagerSettings::get('notify_structure_fuel_events', true)) {
            $types = array_merge($types, self::FUEL_EVENT_TYPES);
        }

        return $types;
    }

    /**
     * Execute the job.
     */
    public function handle()
    {
        // Check if ESI polling is enabled
        if (!StructureManagerSettings::get('esi_polling_enabled', true)) {
            Log::debug('PollStructureNotifications: ESI polling is disabled');
            return;
        }

        $handledTypes = $this->getAllHandledTypes();
        if (empty($handledTypes)) {
            Log::debug('PollStructureNotifications: All notification categories disabled');
            return;
        }

        // Get next key holders from the admin-assigned pool (round-robin)
        $keyHolders = EsiKeyHolder::getNextInRotation(2);

        if ($keyHolders->isEmpty()) {
            Log::debug('PollStructureNotifications: No enabled key holders in pool (assign directors in Settings > Structure Events)');
            return;
        }

        Log::info('PollStructureNotifications: Polling ' . $keyHolders->count() . ' key holder(s)');

        $newNotifications = 0;
        $processed = 0;

        foreach ($keyHolders as $keyHolder) {
            try {
                $new = $this->pollKeyHolder($keyHolder, $handledTypes);
                $newNotifications += $new;
            } catch (\Throwable $e) {
                $keyHolder->recordFailure('failed', $e->getMessage());
                Log::warning("PollStructureNotifications: Failed to poll key holder {$keyHolder->character_id} ({$keyHolder->character_name}): " . $e->getMessage());
            }
        }

        // Process any unprocessed notifications (including from previous failed runs)
        $processed = $this->processUnprocessedNotifications();

        Log::info("PollStructureNotifications: Done. New: {$newNotifications}, Processed: {$processed}");
    }

    /**
     * Poll a single key holder's notifications from ESI.
     *
     * Uses the admin-assigned key pool. Records success/failure on the
     * EsiKeyHolder model so the health dashboard stays current.
     *
     * @return int Number of new notifications found
     */
    private function pollKeyHolder(EsiKeyHolder $keyHolder, array $handledTypes): int
    {
        $characterId = $keyHolder->character_id;
        $corporationId = $keyHolder->corporation_id;

        // Get the refresh token
        $token = RefreshToken::find($characterId);
        if (!$token) {
            $keyHolder->recordFailure('token_expired', 'No refresh token found in SeAT');
            return 0;
        }

        // Check scope
        $scopes = $token->scopes ?? [];
        if (is_string($scopes)) {
            $scopes = json_decode($scopes, true) ?? [];
        }
        if (!in_array('esi-characters.read_notifications.v1', $scopes)) {
            $keyHolder->recordFailure('scope_missing', 'Missing esi-characters.read_notifications.v1 scope');
            return 0;
        }

        // Call ESI via Eseye
        $esi = app()->make(EsiClient::class);
        $esi->setAuthentication($token);

        $response = $esi->invoke('get', '/characters/{character_id}/notifications/', [
            'character_id' => $characterId,
        ]);

        // Update refresh token after ESI call (Eseye may have refreshed it)
        try {
            $updatedAuth = $esi->getAuthentication();
            $token->token = $updatedAuth->getAccessToken();
            $token->refresh_token = $updatedAuth->getRefreshToken();
            $token->expires_on = $updatedAuth->getExpiresOn();
            $token->save();
        } catch (\Throwable $e) {
            // Non-fatal — token still works for now
            Log::debug("PollStructureNotifications: Could not update token for {$characterId}: " . $e->getMessage());
        }

        $notifications = $response->getBody();

        if (!is_array($notifications) && !($notifications instanceof \Traversable)) {
            $notifications = [];
        }

        $newCount = 0;

        foreach ($notifications as $notification) {
            $type = $notification->type ?? null;
            $notificationId = $notification->notification_id ?? null;

            if (!$type || !$notificationId) {
                continue;
            }

            // Filter: only structure types we handle
            if (!in_array($type, $handledTypes)) {
                continue;
            }

            // Skip if older than 2 hours (stale)
            $timestamp = Carbon::parse($notification->timestamp ?? 'now');
            if ($timestamp->lt(Carbon::now()->subHours(2))) {
                continue;
            }

            // Deduplicate
            if (EsiNotification::where('notification_id', $notificationId)->exists()) {
                continue;
            }

            // Parse the YAML text field
            $parsedData = null;
            $rawText = $notification->text ?? '';
            try {
                $parsedData = Yaml::parse($rawText);
            } catch (\Throwable $e) {
                Log::debug("PollStructureNotifications: YAML parse failed for notification {$notificationId}: " . $e->getMessage());
                $parsedData = ['raw' => $rawText];
            }

            // Insert new notification
            EsiNotification::create([
                'notification_id' => $notificationId,
                'character_id' => $characterId,
                'corporation_id' => $corporationId,
                'type' => $type,
                'sender_id' => $notification->sender_id ?? null,
                'sender_type' => $notification->sender_type ?? null,
                'timestamp' => $timestamp,
                'text' => $rawText,
                'parsed_data' => $parsedData,
                'source' => 'fast_poll',
                'processed' => false,
            ]);

            $newCount++;
            Log::info("PollStructureNotifications: New {$type} notification #{$notificationId} from key holder {$keyHolder->character_name} ({$characterId})");
        }

        // Record success on the key holder
        $keyHolder->recordSuccess($newCount);

        return $newCount;
    }

    /**
     * Process all unprocessed notifications: build embeds and send to webhooks.
     *
     * @return int Number processed
     */
    private function processUnprocessedNotifications(): int
    {
        $unprocessed = EsiNotification::where('processed', false)
            ->orderBy('timestamp', 'asc')
            ->limit(50)
            ->get();

        if ($unprocessed->isEmpty()) {
            return 0;
        }

        $processed = 0;

        foreach ($unprocessed as $notification) {
            try {
                $this->dispatchNotification($notification);
                $notification->markProcessed();
                $processed++;
            } catch (\Throwable $e) {
                Log::error("PollStructureNotifications: Failed to process notification #{$notification->notification_id}: " . $e->getMessage());
            }
        }

        return $processed;
    }

    /**
     * Build and send a Discord webhook for a single notification.
     */
    private function dispatchNotification(EsiNotification $notification): void
    {
        $webhooks = WebhookConfiguration::getForCorporation($notification->corporation_id);

        if ($webhooks->isEmpty()) {
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

            // Determine which role mention to use
            $roleMention = '';
            $category = $this->getCategory($notification->type);
            if ($category === 'attack') {
                // Attack alerts use their own role mention if configured, otherwise fall back to webhook's
                $roleMention = StructureManagerSettings::get('esi_attack_role_mention', '') ?: ($webhook->role_mention ?? '');
            } else {
                $roleMention = $webhook->role_mention ?? '';
            }

            // Inject role mention into payload
            $finalPayload = $this->injectMention($payload, $roleMention, $category);

            try {
                Http::connectTimeout(5)->timeout(10)->post($webhook->webhook_url, $finalPayload);
            } catch (\Throwable $e) {
                Log::error("PollStructureNotifications: Webhook failed for notification #{$notification->notification_id}: " . $e->getMessage());
            }
        }
    }

    /**
     * Build the Discord webhook payload based on notification type.
     */
    private function buildPayload(EsiNotification $notification): ?array
    {
        $data = $notification->parsed_data ?? [];
        $type = $notification->type;
        $category = $this->getCategory($type);

        // Resolve structure metadata
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

    /**
     * Build attack notification payload (StructureUnderAttack, LostShields, LostArmor, Destroyed).
     */
    private function buildAttackPayload(EsiNotification $notification, array $data, array $meta): array
    {
        $type = $notification->type;

        // Color: dark red for destroyed/under-attack, red for lost shields/armor
        $color = in_array($type, ['StructureDestroyed', 'SkyhookDestroyed', 'StructureUnderAttack', 'SkyhookUnderAttack'])
            ? 10038562  // dark red
            : 15158332; // red

        // Title based on type
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

        // Location + Structure Type + Timestamp
        $fields[] = ['name' => "\u{1F4CD} Location", 'value' => $meta['system'] ?? 'Unknown', 'inline' => true];
        $fields[] = ['name' => 'Structure Type', 'value' => $meta['type'] ?? 'Unknown', 'inline' => true];
        $fields[] = ['name' => "\u{23F0} Last Update", 'value' => Carbon::parse($notification->timestamp)->diffForHumans(), 'inline' => true];

        // Shield/Armor/Hull percentages (for StructureUnderAttack)
        if (isset($data['shieldPercentage'])) {
            $fields[] = ['name' => 'Shield', 'value' => number_format($data['shieldPercentage'], 1) . '%', 'inline' => true];
            $fields[] = ['name' => 'Armor', 'value' => number_format($data['armorPercentage'] ?? 100, 1) . '%', 'inline' => true];
            $fields[] = ['name' => 'Hull', 'value' => number_format($data['hullPercentage'] ?? 100, 1) . '%', 'inline' => true];
        }

        // Attacker info
        if (isset($data['corpName'])) {
            $fields[] = ['name' => 'Attacker Corp', 'value' => $data['corpName'] ?? 'Unknown', 'inline' => true];
        }
        if (!empty($data['allianceName'])) {
            $fields[] = ['name' => 'Attacker Alliance', 'value' => $data['allianceName'], 'inline' => true];
        }

        // Detection source
        $fields[] = ['name' => 'Detection', 'value' => 'via ' . $notification->source . ' (' . Carbon::parse($notification->timestamp)->diffForHumans() . ')', 'inline' => true];

        $embed = [
            'title' => ($meta['name'] ?? 'Unknown Structure') . " \u{2014} " . $titleSuffix,
            'color' => $color,
            'fields' => $fields,
            'footer' => ['text' => 'SeAT Structure Manager | Fast ESI Polling'],
            'timestamp' => Carbon::parse($notification->timestamp)->toIso8601String(),
        ];

        // Content line
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

    /**
     * Build lifecycle notification payload (Anchoring, Unanchoring, Destroyed, Ownership).
     */
    private function buildLifecyclePayload(EsiNotification $notification, array $data, array $meta): array
    {
        $type = $notification->type;

        $colorMap = [
            'StructureAnchoring' => 3447003,    // blue
            'AllAnchoringMsg' => 3447003,
            'SkyhookDeployed' => 3447003,
            'StructureUnanchoring' => 16776960,  // yellow
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

        // Corporation info if available
        if (isset($data['corpName'])) {
            $fields[] = ['name' => 'Corporation', 'value' => $data['corpName'], 'inline' => true];
        }

        $embed = [
            'title' => ($meta['name'] ?? $meta['type'] ?? 'Structure') . ' — ' . ($titleMap[$type] ?? $type),
            'color' => $color,
            'fields' => $fields,
            'footer' => ['text' => 'SeAT Structure Manager | Fast ESI Polling'],
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
     * Build fuel-event notification payload (LowPower, HighPower, ServicesOffline, FuelAlert).
     */
    private function buildFuelEventPayload(EsiNotification $notification, array $data, array $meta): array
    {
        $type = $notification->type;

        $colorMap = [
            'StructureWentLowPower' => 16776960,       // yellow
            'StructureWentHighPower' => 3066993,        // green
            'StructureServicesOffline' => 16776960,     // yellow
            'StructureFuelAlert' => 16776960,           // yellow
            'StructureLowReagentsAlert' => 16776960,
            'StructureNoReagentsAlert' => 15158332,     // red
            'SkyhookOnline' => 3066993,                 // green
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

        // Fuel items if present (from CCP's fuel alert)
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
            'footer' => ['text' => 'SeAT Structure Manager | Fast ESI Polling'],
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
     * Resolve structure metadata from notification data.
     */
    private function resolveStructureMeta(array $data): array
    {
        $meta = [
            'name' => null,
            'type' => null,
            'system' => null,
        ];

        // System from solarsystemID
        if (isset($data['solarsystemID'])) {
            $system = DB::table('mapDenormalize')
                ->where('itemID', $data['solarsystemID'])
                ->select('itemName', 'security')
                ->first();
            if ($system) {
                $meta['system'] = $system->itemName . ' (' . number_format($system->security, 2) . ')';
            }
        }

        // Structure name from structureID
        if (isset($data['structureID'])) {
            $structure = DB::table('universe_structures')
                ->where('structure_id', $data['structureID'])
                ->value('name');
            $meta['name'] = $structure;
        }

        // Structure type from structureShowInfoData[1] (typeID)
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
     * Resolve a type name from typeID via the SDE.
     */
    private function resolveTypeName(int $typeId): string
    {
        if ($typeId <= 0) {
            return 'Unknown';
        }

        return DB::table('invTypes')
            ->where('typeID', $typeId)
            ->value('typeName') ?? "Type #{$typeId}";
    }

    /**
     * Get the category for a notification type.
     */
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

    /**
     * Inject role mention into a payload's content line and allowed_mentions.
     */
    private function injectMention(array $payload, string $roleMention, string $category): array
    {
        if (empty($roleMention)) {
            return $payload;
        }

        // Only mention on attack/critical categories, or on fuel warnings
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
