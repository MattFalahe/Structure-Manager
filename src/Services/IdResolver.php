<?php

namespace StructureManager\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Unified name resolver for characters, corporations, and alliances.
 *
 * Three-tier fallback chain per ID:
 *   1. SeAT's local info tables (character_infos, corporation_infos,
 *      alliance_infos) — instant, free, populated when SeAT or another
 *      plugin has touched the entity before.
 *   2. SeAT's universe_names secondary cache — covers entities SeAT
 *      pulled via "name resolve" calls but hasn't fully fetched info for.
 *   3. CCP's public ESI endpoint — synchronous HTTP, 2s timeout,
 *      successful results cached in Laravel Cache for 7 days so the
 *      ESI call only happens once per unique ID per week.
 *
 * Why this lives in Structure Manager and not Manager Core: identity
 * resolution is a SM-specific feature today (used by the attack/
 * lifecycle embed builders). When/if Mining Manager, HR, etc. need
 * the same pattern, this is a clean candidate to promote into MC and
 * expose via the PluginBridge capability surface — but YAGNI for now.
 *
 * Design notes:
 * - Each tier returns null on miss, never an empty string, so callers
 *   can `?? "ID #{$id} (name not cached)"` cleanly.
 * - ESI failures (network errors, timeouts, 4xx/5xx, malformed JSON)
 *   all degrade gracefully to null. The original behaviour of rendering
 *   the ID-only form is preserved when ESI is unreachable.
 * - Successful ESI lookups are ONLY cached in Laravel cache, not
 *   persisted into SeAT's *_infos tables. Persisting there would
 *   require populating every column those tables expect — and SeAT's
 *   own Info-update jobs would clobber us anyway on the next sync.
 *   Cache-only is enough: it covers the embed-render speed concern
 *   without taking on a sync responsibility that's already SeAT's.
 * - 7-day TTL is a balance: character names CAN change in EVE (paid
 *   service), corp names rarely change, alliance names almost never.
 *   A week is short enough that name-change drift is bounded, long
 *   enough that we rarely hit ESI for the same ID twice.
 */
class IdResolver
{
    /** Laravel cache TTL for successful ESI lookups (7 days). */
    private const CACHE_TTL_SECONDS = 60 * 60 * 24 * 7;

    /**
     * Hard timeout on the ESI HTTP call. 2 seconds is plenty for ESI's
     * typical 100-300ms response time, and we'd rather degrade to "name
     * not cached" than block an alert dispatch on a slow ESI.
     */
    private const ESI_TIMEOUT_SECONDS = 2;

    /**
     * ESI public base. The /latest/ alias automatically routes to the
     * current stable endpoint version, which is what we want for these
     * simple name-lookup calls (no schema evolution risk).
     */
    private const ESI_BASE = 'https://esi.evetech.net/latest';

    /**
     * User-Agent header per CCP's third-party developer guidelines.
     * Identifies which plugin made the call so CCP can reach out if
     * we ever start hammering them (which we won't — cache + 2s
     * timeout keeps load minimal).
     */
    private const USER_AGENT = 'SeAT-StructureManager/2.0.1 (+https://github.com/MattFalahe/structure-manager)';

    /**
     * Resolve a character ID to a name. Returns null if all tiers miss.
     */
    public static function characterName(int $characterId): ?string
    {
        if ($characterId <= 0) {
            return null;
        }

        $local = self::lookupLocal('character_infos', 'character_id', $characterId)
            ?? self::lookupUniverseName($characterId, 'character');
        if ($local !== null) {
            return $local;
        }

        return Cache::remember(
            "sm:resolve:char:{$characterId}",
            self::CACHE_TTL_SECONDS,
            fn () => self::fetchEsi("/characters/{$characterId}/")
        );
    }

    /**
     * Resolve a corporation ID to a name. Returns null if all tiers miss.
     */
    public static function corporationName(int $corporationId): ?string
    {
        if ($corporationId <= 0) {
            return null;
        }

        $local = self::lookupLocal('corporation_infos', 'corporation_id', $corporationId)
            ?? self::lookupUniverseName($corporationId, 'corporation');
        if ($local !== null) {
            return $local;
        }

        return Cache::remember(
            "sm:resolve:corp:{$corporationId}",
            self::CACHE_TTL_SECONDS,
            fn () => self::fetchEsi("/corporations/{$corporationId}/")
        );
    }

    /**
     * Resolve an alliance ID to a name. Returns null if all tiers miss.
     */
    public static function allianceName(int $allianceId): ?string
    {
        if ($allianceId <= 0) {
            return null;
        }

        $local = self::lookupLocal('alliance_infos', 'alliance_id', $allianceId)
            ?? self::lookupUniverseName($allianceId, 'alliance');
        if ($local !== null) {
            return $local;
        }

        return Cache::remember(
            "sm:resolve:alli:{$allianceId}",
            self::CACHE_TTL_SECONDS,
            fn () => self::fetchEsi("/alliances/{$allianceId}/")
        );
    }

    /**
     * Tier 1: read from SeAT's primary info table for this entity type.
     * Returns null on miss, table-not-found, or any DB error (defensive
     * against partial SeAT installs).
     */
    private static function lookupLocal(string $table, string $idColumn, int $id): ?string
    {
        try {
            $name = DB::table($table)->where($idColumn, $id)->value('name');
            return !empty($name) ? $name : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Tier 2: read from SeAT's universe_names secondary cache. Populated
     * when SeAT does bulk name-resolve calls (e.g. for killmail
     * attacker lists). Often has entries that the primary *_infos tables
     * don't yet have full records for.
     */
    private static function lookupUniverseName(int $id, string $category): ?string
    {
        try {
            $name = DB::table('universe_names')
                ->where('entity_id', $id)
                ->where('category', $category)
                ->value('name');
            return !empty($name) ? $name : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Tier 3: synchronous public ESI fetch. Returns the 'name' field
     * from the response body, or null on any failure (timeout, non-2xx,
     * malformed JSON, missing name field). All ESI endpoints used here
     * return a top-level 'name' key in the same shape.
     */
    private static function fetchEsi(string $path): ?string
    {
        try {
            $response = Http::timeout(self::ESI_TIMEOUT_SECONDS)
                ->withHeaders(['User-Agent' => self::USER_AGENT])
                ->acceptJson()
                ->get(self::ESI_BASE . $path);

            if (!$response->successful()) {
                Log::debug(sprintf(
                    'IdResolver: ESI returned %d for %s',
                    $response->status(),
                    $path
                ));
                return null;
            }

            $data = $response->json();
            $name = $data['name'] ?? null;
            return is_string($name) && $name !== '' ? $name : null;
        } catch (\Throwable $e) {
            Log::debug(sprintf(
                'IdResolver: ESI fetch failed for %s: %s',
                $path,
                $e->getMessage()
            ));
            return null;
        }
    }

    /**
     * Test-friendly helper: clear a single resolution from the Laravel
     * cache. Useful when an admin notices a stale name post-rename and
     * wants to force a re-fetch without waiting 7 days.
     *
     * Returns true if a cache entry existed and was removed.
     */
    public static function forget(string $kind, int $id): bool
    {
        $key = match ($kind) {
            'character', 'char'      => "sm:resolve:char:{$id}",
            'corporation', 'corp'    => "sm:resolve:corp:{$id}",
            'alliance', 'alli'       => "sm:resolve:alli:{$id}",
            default                  => null,
        };
        return $key ? (bool) Cache::forget($key) : false;
    }
}
