<?php

namespace StructureManager\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Threat-intel lookup for attacker characters via zKillboard's public stats
 * API. Used by DispatchAttackerThreatIntel to enrich the under-attack alert
 * with a follow-up "who is this attacker" embed.
 *
 * This is a SEPARATE service from ZkbClient — that one finds specific
 * killmails for our own destroyed structures, this one profiles attackers
 * (different endpoints, different cache semantics, different fail modes).
 *
 * zKillboard stats endpoint: https://zkillboard.com/api/stats/characterID/{id}/
 * Returns shipsDestroyed / shipsLost / soloKills / topAllTime / months /
 * danger/gangRatio. We extract the fields most useful for an FC deciding
 * "should I form fleet": kill count over time, danger ratio, last activity.
 *
 * Cache: 7 days. Attacker profiles change slowly (a character active in
 * March is still active in April with similar stats); per-attack lookups
 * for the same pilot during a coordinated attack window can hit cache
 * dozens of times without re-querying zKB.
 *
 * Fail-open: any error (timeout, 429, non-200, parse failure) returns
 * null. Callers MUST treat null as "no enrichment available" and degrade
 * gracefully — never block the primary alert dispatch.
 *
 * Opsec: only attacker char_id is queried. Attacker char_id is already
 * public (zKB shows it on the killmail). No defender data leaves SeAT.
 */
class ZKillThreatService
{
    private const ZKB_API_BASE = 'https://zkillboard.com/api/';
    private const REQUEST_TIMEOUT_SECONDS = 2;
    private const CACHE_TTL_SECONDS = 7 * 24 * 3600; // 7 days
    private const CACHE_KEY_PREFIX = 'sm:zkb:threat:char:';

    /**
     * User-Agent matches ZkbClient — same plugin, same identity. zKB asks
     * automated callers to identify the project so they can route any
     * rate-limit warnings or compatibility notes back to the maintainer.
     */
    public const USER_AGENT = 'SeAT-Structure-Manager/2.0 (+https://github.com/MattFalahe/Structure-Manager)';

    /**
     * Hot/cold thresholds. Tuned to give FCs a quick read on what kind of
     * pilot they're up against. "Professional" implies a pilot who shoots
     * structures frequently; "opportunistic" implies a passerby; "dormant"
     * implies a returning player or alpha attempt.
     */
    private const TIER_PROFESSIONAL_KILLS_30D = 50;
    private const TIER_ACTIVE_KILLS_30D       = 10;
    private const TIER_DORMANT_DAYS_INACTIVE  = 90;

    /**
     * Look up the threat profile for an attacker character.
     *
     * Returns null on ANY failure — caller treats null as "skip enrichment".
     * Returns the full profile array on success.
     *
     * Profile shape:
     *   [
     *     'character_id'        => 1234567890,
     *     'kills_30d'           => 47,
     *     'kills_lifetime'      => 12345,
     *     'losses_lifetime'     => 678,
     *     'danger_ratio'        => 87,          // zKB's danger metric 0-100
     *     'gang_ratio'          => 35,          // 0=solo / 100=blob
     *     'top_ship_name'       => 'Loki',      // most-flown ship (lifetime)
     *     'top_ship_type_id'    => 29984,
     *     'first_seen_at'       => '2021-03-15T10:30:00+00:00',  // approx — earliest tracked killmail
     *     'last_seen_at'        => '2026-05-16T14:23:00+00:00',
     *     'days_since_last_kill'=> 1,
     *     'tier'                => 'professional' | 'active' | 'casual' | 'dormant',
     *     'tier_label'          => '🔥 Professional structure killer',
     *     'zkb_url'             => 'https://zkillboard.com/character/1234567890/',
     *   ]
     */
    public function getProfile(int $characterId): ?array
    {
        if ($characterId <= 0) {
            return null;
        }

        $cacheKey = self::CACHE_KEY_PREFIX . $characterId;

        // Cache hit — return stored profile (or stored null sentinel)
        if (Cache::has($cacheKey)) {
            $cached = Cache::get($cacheKey);
            return $cached === '__null__' ? null : $cached;
        }

        $profile = $this->fetchAndParse($characterId);

        // Cache both successful profiles AND failures (as null sentinel) to
        // avoid hammering zKB for a pilot whose data is permanently missing.
        // Shorter cache for failures (1 hour) so transient outages recover.
        if ($profile === null) {
            Cache::put($cacheKey, '__null__', 3600);
        } else {
            Cache::put($cacheKey, $profile, self::CACHE_TTL_SECONDS);
        }

        return $profile;
    }

    /**
     * Hit zKB and parse the response into a profile array.
     *
     * Returns null on any failure (timeout, 429, non-200, malformed JSON).
     */
    private function fetchAndParse(int $characterId): ?array
    {
        $url = self::ZKB_API_BASE . "stats/characterID/{$characterId}/";

        try {
            $response = Http::withHeaders([
                'User-Agent' => self::USER_AGENT,
                'Accept'     => 'application/json',
            ])
                ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->get($url);
        } catch (\Throwable $e) {
            // Network errors, DNS failures, connection refused. Log at debug
            // because attacker enrichment is best-effort; spam at warning
            // would noise up logs during zKB outages.
            Log::debug("ZKillThreatService: zKB request errored for character {$characterId}: " . $e->getMessage());
            return null;
        }

        if (!$response->successful()) {
            // 429 (rate limited), 404 (no stats — happens for new chars zKB
            // hasn't ingested), 5xx (zKB outage). All treated the same:
            // skip enrichment, cache the miss briefly, move on.
            return null;
        }

        $data = $response->json();
        if (!is_array($data) || empty($data)) {
            return null;
        }

        return $this->parseProfile($characterId, $data);
    }

    /**
     * Parse zKB's response into our profile schema. Defensive against
     * missing fields — zKB occasionally returns partial data for new chars.
     */
    private function parseProfile(int $characterId, array $data): array
    {
        $shipsDestroyed = (int) ($data['shipsDestroyed'] ?? 0);
        $shipsLost      = (int) ($data['shipsLost'] ?? 0);
        $dangerRatio    = (int) ($data['dangerRatio'] ?? 0);
        $gangRatio      = (int) ($data['gangRatio'] ?? 0);

        // Recent activity — pull the most recent month's kill count from
        // the "months" object which zKB structures as YYYYMM keys.
        $months = $data['months'] ?? [];
        $kills30d = 0;
        if (is_array($months)) {
            // The current + previous month combined give a rough "last 30
            // days" approximation. zKB doesn't expose a true 30-day rolling
            // window in the stats endpoint, but combining the two recent
            // calendar months gives a stable proxy.
            $now = Carbon::now();
            $thisMonthKey = $now->format('Ym');
            $lastMonthKey = $now->copy()->subMonth()->format('Ym');
            $kills30d = (int) (($months[$thisMonthKey]['shipsDestroyed'] ?? 0)
                + ($months[$lastMonthKey]['shipsDestroyed'] ?? 0));
        }

        // Top ship — the "topAllTime" field structures as an array of arrays
        // by type (ship/system/region/etc.). We want the entry with type=ship.
        $topShipName   = null;
        $topShipTypeId = null;
        $topAllTime = $data['topAllTime'] ?? [];
        if (is_array($topAllTime)) {
            foreach ($topAllTime as $bucket) {
                if (!is_array($bucket) || ($bucket['type'] ?? null) !== 'shipType') {
                    continue;
                }
                $first = $bucket['data'][0] ?? null;
                if (is_array($first)) {
                    $topShipName   = $first['shipTypeName'] ?? null;
                    $topShipTypeId = isset($first['shipTypeID']) ? (int) $first['shipTypeID'] : null;
                }
                break;
            }
        }

        // Activity window — zKB doesn't expose first/last directly, but the
        // "info" sub-object sometimes carries lastApiUpdate. We try to
        // reconstruct from months array — earliest + latest non-zero months.
        $firstSeenIso = null;
        $lastSeenIso  = null;
        $daysSinceLastKill = null;
        if (is_array($months) && !empty($months)) {
            $monthKeys = array_keys(array_filter($months, fn($m) => is_array($m) && ($m['shipsDestroyed'] ?? 0) > 0));
            if (!empty($monthKeys)) {
                sort($monthKeys);
                try {
                    $firstSeenIso = Carbon::createFromFormat('Ym', (string) $monthKeys[0])
                        ->startOfMonth()
                        ->toIso8601String();
                    $lastSeenIso = Carbon::createFromFormat('Ym', (string) end($monthKeys))
                        ->endOfMonth()
                        ->toIso8601String();
                    $daysSinceLastKill = (int) Carbon::parse($lastSeenIso)->diffInDays(Carbon::now());
                } catch (\Throwable $e) {
                    // ignore — leave null
                }
            }
        }

        // Tier classification — synthesize a single label for the embed
        [$tier, $tierLabel] = $this->classifyTier($kills30d, $daysSinceLastKill);

        return [
            'character_id'         => $characterId,
            'kills_30d'            => $kills30d,
            'kills_lifetime'       => $shipsDestroyed,
            'losses_lifetime'      => $shipsLost,
            'danger_ratio'         => $dangerRatio,
            'gang_ratio'           => $gangRatio,
            'top_ship_name'        => $topShipName,
            'top_ship_type_id'     => $topShipTypeId,
            'first_seen_at'        => $firstSeenIso,
            'last_seen_at'         => $lastSeenIso,
            'days_since_last_kill' => $daysSinceLastKill,
            'tier'                 => $tier,
            'tier_label'           => $tierLabel,
            'zkb_url'              => "https://zkillboard.com/character/{$characterId}/",
        ];
    }

    /**
     * Classify pilot into a tier from their recent activity. Tiers are FC-
     * actionable labels — not zKB metrics. The FC reading the embed wants
     * "should I be worried" not "what's their danger ratio".
     *
     * @return array{0:string,1:string} [$tierKey, $humanLabel]
     */
    private function classifyTier(int $kills30d, ?int $daysSinceLastKill): array
    {
        // Dormant: hasn't killed anything in months — likely returning player
        // or alpha trial. Lower threat than the raw kill count suggests.
        if ($daysSinceLastKill !== null && $daysSinceLastKill > self::TIER_DORMANT_DAYS_INACTIVE) {
            return ['dormant', "\u{1F4A4} Dormant — first activity in {$daysSinceLastKill} days"];
        }

        if ($kills30d >= self::TIER_PROFESSIONAL_KILLS_30D) {
            return ['professional', "\u{1F525} Professional — {$kills30d} kills in last ~30 days"];
        }

        if ($kills30d >= self::TIER_ACTIVE_KILLS_30D) {
            return ['active', "\u{26A0}\u{FE0F} Active — {$kills30d} kills in last ~30 days"];
        }

        if ($kills30d > 0) {
            return ['casual', "\u{1F50D} Casual — {$kills30d} kills in last ~30 days"];
        }

        return ['cold', "\u{2744}\u{FE0F} Cold — no recent activity tracked"];
    }
}
