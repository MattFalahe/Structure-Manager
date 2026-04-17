<?php

namespace StructureManager\Services;

use Illuminate\Support\Facades\Log;

/**
 * Detects optional Discord role-provider packages and, when present, surfaces
 * role lists for the admin UI dropdown.
 *
 * Supported providers (any ONE installed enables the dropdown):
 *   - zenobio93/seat-connector   (unified SeAT connector framework)
 *   - warlof/seat-discord-connector  (legacy standalone Discord plugin)
 *
 * When no provider is installed, the notification settings UI falls back to
 * a plain text input for the role mention string (<@&ROLE_ID> or numeric ID).
 *
 * This service is read-only. It never writes to the connector packages.
 * If the connector is uninstalled after a role was picked, the stored
 * role_mention string still works because it's a static Discord-format string.
 */
class DiscordRoleResolver
{
    public const PROVIDER_SEAT_CONNECTOR   = 'seat-connector';
    public const PROVIDER_WARLOF_DISCORD   = 'warlof-discord';

    /**
     * Detect which connector (if any) is installed and return its identifier.
     * Returns null when neither is present.
     */
    public static function detectProvider(): ?string
    {
        // zenobio93/seat-connector: look for the Driver interface shipped by that package.
        // Candidate namespaces — covers common installed paths for this evolving package.
        foreach ([
            '\\Seat\\Connector\\Drivers\\Discord\\Driver',
            '\\Seat\\Connector\\Drivers\\IClient',
        ] as $candidate) {
            if (class_exists($candidate) || interface_exists($candidate)) {
                return self::PROVIDER_SEAT_CONNECTOR;
            }
        }

        // warlof/seat-discord-connector: classic standalone plugin.
        foreach ([
            '\\Warlof\\Seat\\Connector\\Discord\\Discord',
            '\\Warlof\\Seat\\Connector\\Discord\\Driver\\DiscordClient',
            '\\Warlof\\Seat\\Connector\\Discord\\Models\\DiscordUser',
        ] as $candidate) {
            if (class_exists($candidate)) {
                return self::PROVIDER_WARLOF_DISCORD;
            }
        }

        return null;
    }

    /**
     * Is any supported provider available?
     */
    public static function isAvailable(): bool
    {
        return self::detectProvider() !== null;
    }

    /**
     * Return a list of Discord roles the admin can pick from.
     *
     * Shape: [['id' => '123', 'name' => 'Fleet Commanders', 'color' => null], ...]
     *
     * Returns [] when no provider is installed, or when the provider is
     * installed but has no cached role list yet (admin should pick manually).
     *
     * NOTE: The concrete role-listing logic per provider is left as a stub.
     * Each connector has its own schema / API — implement these once the
     * package is installed on the target SeAT instance and we can verify
     * the actual class paths and query shape.
     */
    public static function listRoles(): array
    {
        $provider = self::detectProvider();

        try {
            return match ($provider) {
                self::PROVIDER_SEAT_CONNECTOR => self::rolesFromSeatConnector(),
                self::PROVIDER_WARLOF_DISCORD => self::rolesFromWarlof(),
                default => [],
            };
        } catch (\Throwable $e) {
            Log::warning('[Structure Manager] DiscordRoleResolver: failed to list roles from ' . ($provider ?? 'unknown') . ': ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Human label for the UI to show which provider is being used.
     */
    public static function providerLabel(): string
    {
        return match (self::detectProvider()) {
            self::PROVIDER_SEAT_CONNECTOR => 'SeAT Connector (zenobio93)',
            self::PROVIDER_WARLOF_DISCORD => 'SeAT Discord Connector (warlof)',
            default => 'Manual input only',
        };
    }

    // ---- Provider-specific role listing (stubs — fill in once installed) ----

    /**
     * zenobio93/seat-connector role listing.
     *
     * When the connector's Driver is registered, its role registry typically
     * lives in a `seat_connector_roles` table or similar. Safe stub: return
     * [] so the UI falls back to manual input. Implement when a target
     * environment has this installed and we can confirm the actual model.
     */
    private static function rolesFromSeatConnector(): array
    {
        if (\Illuminate\Support\Facades\Schema::hasTable('seat_connector_roles')) {
            $rows = \Illuminate\Support\Facades\DB::table('seat_connector_roles')
                ->select(['id', 'name', 'discord_id'])
                ->orderBy('name')
                ->get();

            return $rows->map(function ($r) {
                return [
                    'id'    => (string) ($r->discord_id ?? $r->id),
                    'name'  => (string) $r->name,
                    'color' => null,
                ];
            })->all();
        }

        return [];
    }

    /**
     * warlof/seat-discord-connector role listing.
     *
     * Historical table name: `warlof_discord_connector_roles` or similar.
     * Safe stub for now.
     */
    private static function rolesFromWarlof(): array
    {
        foreach (['warlof_discord_connector_roles', 'discord_connector_roles'] as $tableCandidate) {
            if (\Illuminate\Support\Facades\Schema::hasTable($tableCandidate)) {
                $rows = \Illuminate\Support\Facades\DB::table($tableCandidate)
                    ->select(['id', 'name', 'discord_id'])
                    ->orderBy('name')
                    ->get();

                return $rows->map(function ($r) {
                    return [
                        'id'    => (string) ($r->discord_id ?? $r->id),
                        'name'  => (string) $r->name,
                        'color' => null,
                    ];
                })->all();
            }
        }

        return [];
    }
}
