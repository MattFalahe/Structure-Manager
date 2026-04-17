<?php

namespace StructureManager\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Detects Discord role providers on a SeAT install and surfaces role lists
 * for the admin notification-settings UI.
 *
 * Detection is TABLE-based (not class_exists) because SeAT plugins ship
 * differently (composer, tarball, manual), and a package may be installed
 * without all its classes being autoloadable under our namespace resolution.
 * If the table is present and has rows, the dropdown works.
 *
 * Known providers and their ownership (confirmed on a real install):
 *   - discord_roles table            → mattfalahe/seat-discord-pings
 *     Curated role registry that the Pings plugin uses. Columns include
 *     mention_format and color — richest UX for a role picker. Always
 *     preferred when present.
 *
 *   - seat_connector_sets            → warlof/seat-connector (framework)
 *     + warlof/seat-discord-connector (Discord driver)
 *     Rows with connector_type='discord' are Discord roles synced from a
 *     guild. Has no color or description fields, but covers the full guild.
 *
 *   - warlof_discord_connector_roles → older warlof-only install (legacy)
 *
 * Priority order: discord_roles → seat_connector_sets → warlof legacy → null.
 *
 * Returns [] from listRoles() when no provider is detected, so the UI falls
 * back to a plain text input.
 */
class DiscordRoleResolver
{
    public const PROVIDER_DISCORD_ROLES_TABLE = 'discord-roles-table';
    public const PROVIDER_SEAT_CONNECTOR      = 'seat-connector';
    public const PROVIDER_WARLOF_DISCORD      = 'warlof-discord';

    /**
     * Detect which Discord role source is available.
     */
    public static function detectProvider(): ?string
    {
        if (Schema::hasTable('discord_roles') && Schema::hasColumn('discord_roles', 'role_id')) {
            return self::PROVIDER_DISCORD_ROLES_TABLE;
        }

        if (Schema::hasTable('seat_connector_sets')) {
            return self::PROVIDER_SEAT_CONNECTOR;
        }

        foreach (['warlof_discord_connector_roles', 'discord_connector_roles'] as $t) {
            if (Schema::hasTable($t)) {
                return self::PROVIDER_WARLOF_DISCORD;
            }
        }

        return null;
    }

    public static function isAvailable(): bool
    {
        return self::detectProvider() !== null;
    }

    /**
     * Return Discord roles for the picker.
     *
     * Shape:
     *   [
     *     'id'             => '1227722401236652123',   // Discord role snowflake
     *     'name'           => 'Corp Member',
     *     'mention_format' => '<@&1227722401236652123>', // exact string to store
     *     'color'          => '#2ecc71' | null,
     *   ]
     */
    public static function listRoles(): array
    {
        $provider = self::detectProvider();

        try {
            return match ($provider) {
                self::PROVIDER_DISCORD_ROLES_TABLE => self::rolesFromDiscordRolesTable(),
                self::PROVIDER_SEAT_CONNECTOR      => self::rolesFromSeatConnector(),
                self::PROVIDER_WARLOF_DISCORD      => self::rolesFromWarlof(),
                default                            => [],
            };
        } catch (\Throwable $e) {
            Log::warning('[Structure Manager] DiscordRoleResolver: failed listing roles from ' . ($provider ?? 'unknown') . ': ' . $e->getMessage());
            return [];
        }
    }

    public static function providerLabel(): string
    {
        return match (self::detectProvider()) {
            self::PROVIDER_DISCORD_ROLES_TABLE => 'SeAT Discord Pings (mattfalahe) — curated role list',
            self::PROVIDER_SEAT_CONNECTOR      => 'SeAT Connector (warlof) — Discord sets',
            self::PROVIDER_WARLOF_DISCORD      => 'SeAT Discord Connector (warlof, legacy tables)',
            default                            => 'Manual input only',
        };
    }

    // ---- Provider-specific queries ----

    /**
     * Primary source when available. Columns:
     *   id, name, role_id (snowflake), mention_format, color, is_active, ...
     */
    private static function rolesFromDiscordRolesTable(): array
    {
        $rows = DB::table('discord_roles')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'role_id', 'mention_format', 'color']);

        return $rows->map(function ($r) {
            return [
                'id'             => (string) $r->role_id,
                'name'           => (string) $r->name,
                'mention_format' => (string) ($r->mention_format ?: '<@&' . $r->role_id . '>'),
                'color'          => $r->color ?: null,
            ];
        })->all();
    }

    /**
     * zenobio93/seat-connector. Schema:
     *   id, connector_type, connector_id, name, is_public
     *
     * Discord roles are rows where connector_type='discord'.
     * connector_id is the Discord role snowflake.
     */
    private static function rolesFromSeatConnector(): array
    {
        $rows = DB::table('seat_connector_sets')
            ->where('connector_type', 'discord')
            ->orderBy('name')
            ->get(['id', 'connector_id', 'name']);

        return $rows->map(function ($r) {
            return [
                'id'             => (string) $r->connector_id,
                'name'           => (string) $r->name,
                'mention_format' => '<@&' . $r->connector_id . '>',
                'color'          => null,
            ];
        })->all();
    }

    /**
     * warlof/seat-discord-connector legacy tables. Best-effort query;
     * adjust the column names if a future install uses a different schema.
     */
    private static function rolesFromWarlof(): array
    {
        foreach (['warlof_discord_connector_roles', 'discord_connector_roles'] as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            $columns = Schema::getColumnListing($table);
            $idCol = in_array('discord_id', $columns) ? 'discord_id' : 'id';
            $nameCol = in_array('name', $columns) ? 'name' : $idCol;

            $rows = DB::table($table)
                ->orderBy($nameCol)
                ->get([$idCol . ' as role_id', $nameCol . ' as name']);

            return $rows->map(function ($r) {
                return [
                    'id'             => (string) $r->role_id,
                    'name'           => (string) $r->name,
                    'mention_format' => '<@&' . $r->role_id . '>',
                    'color'          => null,
                ];
            })->all();
        }

        return [];
    }
}
