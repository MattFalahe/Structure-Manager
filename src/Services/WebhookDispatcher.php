<?php

namespace StructureManager\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use StructureManager\Models\NotificationCategory;
use StructureManager\Models\WebhookConfiguration;

/**
 * Resolves which webhooks should receive a notification for a given
 * (namespace, category_key, corporation_id) tuple, with the role mention
 * picked using a three-tier precedence:
 *
 *   1. pivot.role_mention  — per-binding override (admin explicitly set for this webhook)
 *   2. category.role_mention — category default
 *   3. webhook.role_mention — legacy v3.0 fallback
 *
 * This is the single place every dispatch job goes through. If you change
 * how role mentions are picked, change it here.
 */
class WebhookDispatcher
{
    /**
     * Resolve active webhook bindings for a notification.
     *
     * @return array<int, array{webhook_id:int, webhook_url:string, role_mention:?string}>
     *         Returns an empty array if:
     *           - category does not exist
     *           - category is disabled
     *           - no webhooks are bound/enabled for this corp
     */
    public static function resolveBindings(string $namespace, string $categoryKey, ?int $corporationId): array
    {
        $category = NotificationCategory::forKey($namespace, $categoryKey);

        if ($category === null || !$category->enabled) {
            return [];
        }

        $query = DB::table('structure_manager_category_webhook as cw')
            ->join('structure_manager_webhooks as w', 'cw.webhook_id', '=', 'w.id')
            ->where('cw.category_id', $category->id)
            ->where('cw.enabled', true)
            ->where('w.enabled', true);

        // Corporation filter: null on webhook means "all corps"; otherwise match the event's corp
        if ($corporationId !== null) {
            $query->where(function ($q) use ($corporationId) {
                $q->whereNull('w.corporation_id')
                  ->orWhere('w.corporation_id', $corporationId);
            });
        }

        $rows = $query->select([
            'w.id as webhook_id',
            'w.webhook_url',
            'w.role_mention as webhook_legacy_role',
            'cw.role_mention as binding_role',
        ])->get();

        $bindings = [];
        foreach ($rows as $row) {
            $mention = $row->binding_role
                ?: ($category->role_mention
                    ?: ($row->webhook_legacy_role ?: null));

            $bindings[] = [
                'webhook_id'   => (int) $row->webhook_id,
                'webhook_url'  => $row->webhook_url,
                'role_mention' => $mention,
            ];
        }

        return $bindings;
    }

    /**
     * Convenience: is this (namespace, key) category currently enabled at all?
     * Used by dispatchers for early bail-out before building any payload.
     */
    public static function isCategoryEnabled(string $namespace, string $categoryKey): bool
    {
        $cat = NotificationCategory::forKey($namespace, $categoryKey);
        return $cat !== null && (bool) $cat->enabled;
    }

    /**
     * Format a role mention string for Discord payload content + allowed_mentions.
     *
     * Accepts <@&ROLE_ID>, <@USER_ID>, <@!USER_ID>, or raw numeric role ID.
     * Returns [prefix_string, allowed_mentions_array].
     *
     *   [$prefix, $mentions] = WebhookDispatcher::formatMention('<@&123456789>');
     *   $payload['content'] = $prefix . $payload['content'];
     *   $payload['allowed_mentions'] = $mentions;
     */
    public static function formatMention(?string $raw): array
    {
        $allowedMentions = ['parse' => [], 'users' => [], 'roles' => []];

        if ($raw === null || trim($raw) === '') {
            return ['', $allowedMentions];
        }

        $mention = trim($raw);

        if (preg_match('/^<@&(\d+)>$/', $mention, $m)) {
            return ["<@&{$m[1]}> ", ['parse' => [], 'users' => [], 'roles' => [$m[1]]]];
        }
        if (preg_match('/^<@!?(\d+)>$/', $mention, $m)) {
            return ["<@{$m[1]}> ", ['parse' => [], 'users' => [$m[1]], 'roles' => []]];
        }
        if (preg_match('/^\d+$/', $mention)) {
            return ["<@&{$mention}> ", ['parse' => [], 'users' => [], 'roles' => [$mention]]];
        }

        // Unrecognized format — drop silently to prevent XSS / crafted-payload
        // injection, but log so admins can see why their role mention isn't
        // firing (would otherwise be a very confusing debugging experience).
        Log::warning('StructureManager\\WebhookDispatcher: dropping malformed role mention string "' . $mention . '". Expected <@&ROLE_ID>, <@USER_ID>, or numeric role ID.');
        return ['', $allowedMentions];
    }
}
