<?php

namespace StructureManager\Handlers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use StructureManager\Models\WebhookConfiguration;
use StructureManager\Services\WebhookDispatcher;
use Carbon\Carbon;

/**
 * POS notifications from EVE.
 *
 * Kept apart from StructureEventHandler on purpose. That class resolves
 * everything through structureID and corporation_structures, which a POS has
 * neither of, and POS code stays in its own files so it can be removed
 * cleanly if CCP ever retires starbases.
 *
 * Why this exists at all: measured during a live attack, TowerAlertMsg landed
 * the instant the tower was shot, while every SeAT corporation table was about
 * an hour stale. Polling corporation_starbases.state cannot warn about an
 * attack in progress; a two-hour reinforcement timer can expire before the
 * state column ever reports it. This notification is the only timely signal.
 */
class PosEventHandler
{
    /**
     * TowerAlertMsg is the tower being shot.
     *
     * TowerResourceAlertMsg is deliberately NOT handled. EVE sends it hourly,
     * per tower, once fuel drops below a day, and the plugin's own alert
     * ladder exists specifically to replace that spam. Consuming it would
     * reintroduce the problem this plugin was written to solve.
     */
    public const ATTACK_TYPES = [
        'TowerAlertMsg',
    ];

    public static function registeredTypes(): array
    {
        return self::ATTACK_TYPES;
    }

    public static function handles(?string $type): bool
    {
        return $type !== null && in_array($type, self::registeredTypes(), true);
    }

    /**
     * @param \StructureManager\Models\EsiNotification $notification
     */
    public static function handle($notification): void
    {
        if (! self::handles($notification->type ?? null)) {
            return;
        }

        (new self())->dispatchAttack($notification);
    }

    /**
     * Turn one TowerAlertMsg into an alert.
     *
     * The payload has no starbase id. It identifies the tower by the moon it
     * is anchored on plus its type, both of which corporation_starbases holds
     * and indexes:
     *
     *   aggressorAllianceID, aggressorCorpID, aggressorID
     *   armorValue, hullValue, shieldValue   (fractions of 1)
     *   moonID, solarSystemID, typeID
     */
    private function dispatchAttack($notification): void
    {
        $data = $notification->parsed_data;

        if (is_string($data)) {
            $data = json_decode($data, true);
        }

        if (! is_array($data)) {
            Log::warning("PosEventHandler: notification #{$notification->notification_id} has no usable payload.");

            return;
        }

        $moonId = $data['moonID'] ?? null;
        $typeId = $data['typeID'] ?? null;

        if (! $moonId || ! $typeId) {
            Log::warning("PosEventHandler: notification #{$notification->notification_id} is missing moonID or typeID.");

            return;
        }

        $tower = $this->resolveTower((int) $moonId, (int) $typeId, $notification->corporation_id ?? null);

        if (! $tower) {
            // The corp may no longer hold the tower, or SeAT has not yet seen
            // it. Nothing to alert against.
            Log::info(sprintf(
                'PosEventHandler: no tower for moon %d / type %d, skipping notification #%s.',
                $moonId,
                $typeId,
                $notification->notification_id
            ));

            return;
        }

        $bindings = WebhookDispatcher::resolveBindings('pos', 'attack', (int) $tower->corporation_id);

        // Logged either way, so an attack is visible to an operator who has
        // not bound a webhook yet.
        Log::warning(sprintf(
            'PosEventHandler: POS %d (%s) under attack — shield %.0f%%, armor %.0f%%, hull %.0f%%',
            $tower->starbase_id,
            $tower->starbase_name ?? $tower->tower_type,
            (float) ($data['shieldValue'] ?? 1) * 100,
            (float) ($data['armorValue'] ?? 1) * 100,
            (float) ($data['hullValue'] ?? 1) * 100
        ));

        if (empty($bindings)) {
            return;
        }

        foreach ($bindings as $binding) {
            $this->send($tower, $data, $notification, $binding['webhook_url'], $binding['role_mention'] ?? '');
        }
    }

    /**
     * moon_id + type_id identifies the tower. Both columns are indexed.
     *
     * The corporation from the notification is used as a tiebreaker rather
     * than a filter: the notification goes to characters whose affiliation
     * may have been resolved differently, and two corps cannot hold a tower
     * on the same moon anyway.
     */
    private function resolveTower(int $moonId, int $typeId, $corporationId)
    {
        if (! Schema::hasTable('corporation_starbases')) {
            return null;
        }

        $query = DB::table('corporation_starbases as cs')
            ->join('invTypes as it', 'cs.type_id', '=', 'it.typeID')
            ->leftJoin('mapDenormalize as md', 'cs.system_id', '=', 'md.itemID')
            ->leftJoin('corporation_assets as ca', 'cs.starbase_id', '=', 'ca.item_id')
            ->where('cs.moon_id', $moonId)
            ->where('cs.type_id', $typeId)
            ->select(
                'cs.starbase_id',
                'cs.corporation_id',
                'cs.state',
                'cs.moon_id',
                'it.typeName as tower_type',
                'md.itemName as system_name',
                'md.security as system_security',
                'ca.name as starbase_name'
            );

        if ($corporationId) {
            $preferred = (clone $query)->where('cs.corporation_id', $corporationId)->first();

            if ($preferred) {
                return $preferred;
            }
        }

        return $query->first();
    }

    /**
     * Severity follows how deep the attack has gone. Shields regenerate and a
     * tower can sit at reduced shields harmlessly; armor or hull damage means
     * the shield layer is already gone and the tower is in real trouble.
     */
    private function severityFor(array $data): array
    {
        $armor = (float) ($data['armorValue'] ?? 1);
        $hull  = (float) ($data['hullValue'] ?? 1);

        if ($hull < 1.0) {
            return ['label' => 'HULL', 'colour' => 10038562, 'icon' => '💀',
                    'note' => 'Hull is being hit. The tower is at real risk of being destroyed.'];
        }

        if ($armor < 1.0) {
            return ['label' => 'ARMOR', 'colour' => 15158332, 'icon' => '🔴',
                    'note' => 'Shields are gone and armor is taking damage.'];
        }

        return ['label' => 'SHIELD', 'colour' => 16776960, 'icon' => '⚠️',
                'note' => 'The tower is being shot. Shields are holding for now.'];
    }

    private function send($tower, array $data, $notification, string $webhookUrl, string $roleMention = ''): void
    {
        $sev = $this->severityFor($data);
        $pct = fn ($v) => number_format(((float) $v) * 100, 0) . '%';

        $spaceType = 'Unknown';
        if ($tower->system_security !== null) {
            if ($tower->system_security >= \StructureManager\Helpers\PosFuelCalculator::HIGH_SEC_THRESHOLD) {
                $spaceType = 'High-Sec';
            } elseif ($tower->system_security > 0) {
                $spaceType = 'Low-Sec';
            } else {
                $spaceType = 'Null-Sec';
            }
        }

        $fields = [
            [
                'name'   => '📍 Location',
                'value'  => ($tower->system_name ?? 'Unknown') . " ({$spaceType})",
                'inline' => true,
            ],
            [
                'name'   => '🏗️ Tower Type',
                'value'  => $tower->tower_type,
                'inline' => true,
            ],
            [
                'name'   => '🕒 Reported',
                'value'  => Carbon::parse($notification->timestamp)->diffForHumans(),
                'inline' => true,
            ],
            [
                'name'   => $sev['icon'] . ' Damage',
                'value'  => sprintf(
                    "**Shield:** %s\n**Armor:** %s\n**Hull:** %s",
                    $pct($data['shieldValue'] ?? 1),
                    $pct($data['armorValue'] ?? 1),
                    $pct($data['hullValue'] ?? 1)
                ),
                'inline' => false,
            ],
            [
                'name'   => '🎯 What this means',
                'value'  => $sev['note'],
                'inline' => false,
            ],
        ];

        $aggressor = $this->describeAggressor($data);
        if ($aggressor !== null) {
            $fields[] = ['name' => '⚔️ Aggressor', 'value' => $aggressor, 'inline' => false];
        }

        // SeAT corporation data runs about an hour behind, so the tower state
        // shown in the plugin will not reflect this attack for a while. Say so
        // rather than letting the operator draw the wrong conclusion from a
        // page that still reads Online.
        $fields[] = [
            'name'   => 'ℹ️ Note',
            'value'  => 'Tower state in Structure Manager updates on SeAT corporation refresh, which lags by around an hour. Check in game for the current state.',
            'inline' => false,
        ];

        $embed = [
            'title'     => $sev['icon'] . ' ' . ($tower->starbase_name ?? $tower->tower_type),
            'color'     => $sev['colour'],
            'fields'    => $fields,
            'footer'    => ['text' => 'SeAT Structure Manager | POS ID: ' . $tower->starbase_id],
            'timestamp' => Carbon::parse($notification->timestamp)->toIso8601String(),
        ];

        [$content, $allowedMentions] = WebhookDispatcher::formatMention($roleMention);

        $content .= sprintf('**POS UNDER ATTACK (%s): %s**', $sev['label'], $tower->starbase_name ?? $tower->tower_type);

        $payload = [
            'content'          => $content,
            'embeds'           => [$embed],
            'username'         => 'SeAT Structure Manager',
            'allowed_mentions' => $allowedMentions,
        ];

        if (! WebhookConfiguration::isValidWebhookUrl($webhookUrl)) {
            Log::error('PosEventHandler: refusing to POST to an invalid webhook URL.');

            return;
        }

        \StructureManager\Services\WebhookDeliveryService::sendByUrl(
            $webhookUrl,
            $payload,
            'pos.attack',
            sprintf('POS under attack (%s) — %s', $sev['label'], $tower->starbase_name ?? $tower->starbase_id)
        );
    }

    /**
     * Resolve the attacker to names where SeAT already knows them, falling
     * back to raw ids. No ESI call: this runs on the alert path and must not
     * delay it.
     */
    private function describeAggressor(array $data): ?string
    {
        $charId     = $data['aggressorID'] ?? null;
        $corpId     = $data['aggressorCorpID'] ?? null;
        $allianceId = $data['aggressorAllianceID'] ?? null;

        if (! $charId && ! $corpId && ! $allianceId) {
            return null;
        }

        $name = function (string $table, string $idColumn, string $nameColumn, $id) {
            if (! $id || ! Schema::hasTable($table)) {
                return null;
            }

            return DB::table($table)->where($idColumn, $id)->value($nameColumn);
        };

        $charName     = $name('character_infos', 'character_id', 'name', $charId);
        $corpName     = $name('corporation_infos', 'corporation_id', 'name', $corpId);
        $allianceName = $name('alliances', 'alliance_id', 'name', $allianceId);

        $lines = [];

        if ($charId) {
            $lines[] = '**Pilot:** ' . ($charName ?: "ID {$charId}");
        }
        if ($corpId) {
            $lines[] = '**Corp:** ' . ($corpName ?: "ID {$corpId}");
        }
        if ($allianceId) {
            $lines[] = '**Alliance:** ' . ($allianceName ?: "ID {$allianceId}");
        }

        return implode("\n", $lines);
    }
}
