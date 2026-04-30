<?php

namespace StructureManager\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin client for zKillboard's public API. Used by EnrichKillmailJob to
 * locate the killmail for a destroyed structure when CCP's destruction
 * notification doesn't carry a killmail ID.
 *
 * Discovery strategy: zKB has no direct "this specific structure was killed"
 * endpoint. Their indexes are by character / corp / alliance / ship type /
 * system / region / location. To find OUR structure's killmail we search by
 *   victimCorporationID  (= owner corp at time of destruction)
 *   shipTypeID           (= structure type — Astrahus, Athanor, etc.)
 * and filter the response to kills matching our solar system AND happening
 * within a configurable time window of our destroyed_at timestamp.
 *
 * In the rare case where a corp loses two same-type structures in the same
 * system within the time window, we pick the closest match by time.
 *
 * User-Agent identifies the plugin + community (not any specific user) so
 * zKB operators can route rate-limit warnings or compatibility notes back
 * to the project itself. Anyone running this plugin sends the same UA.
 */
class ZkbClient
{
    private const ZKB_API_BASE = 'https://zkillboard.com/api/';
    private const REQUEST_TIMEOUT_SECONDS = 10;

    /**
     * Identifies the plugin (NOT any individual operator). zKB asks
     * automated callers to include a contact in their UA so they can
     * notify the project of issues; the GitHub repo URL is the contact
     * for the open-source plugin community as a whole.
     */
    public const USER_AGENT = 'SeAT-Structure-Manager/2.0 (+https://github.com/MattFalahe/Structure-Manager)';

    /**
     * Find a killmail matching the given structure-loss criteria.
     *
     * @param int                $ownerCorporationId  Owner corp at time of destruction
     * @param int                $structureTypeId     Type of structure (Astrahus, Athanor, etc.)
     * @param int                $systemId            Solar system the structure was in
     * @param CarbonInterface    $destroyedAt         Best-known destruction time
     * @param int                $matchWindowHours    +/- window for time matching (default 4h)
     *
     * @return array|null  zKB killmail data array on match, null on miss
     *                     (zKB hasn't ingested OR no such kill exists). The
     *                     caller decides whether to retry vs give up.
     *
     * @throws ZkbRateLimitedException  on HTTP 429; caller may inspect $retryAfterSeconds
     */
    public function findStructureKillmail(
        int $ownerCorporationId,
        int $structureTypeId,
        int $systemId,
        CarbonInterface $destroyedAt,
        int $matchWindowHours = 4
    ): ?array {
        $url = self::ZKB_API_BASE
            . "kills/victimCorporationID/{$ownerCorporationId}/shipTypeID/{$structureTypeId}/";

        try {
            $response = Http::withHeaders([
                'User-Agent' => self::USER_AGENT,
                'Accept'     => 'application/json',
            ])
                ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->get($url);
        } catch (\Throwable $e) {
            // Network errors, DNS failures, connection refused etc. — treat
            // as a miss; the calling job will retry per its backoff.
            Log::warning("ZkbClient: zKB request errored for corp={$ownerCorporationId} type={$structureTypeId}: " . $e->getMessage());
            return null;
        }

        if ($response->status() === 429) {
            $retryAfter = (int) ($response->header('Retry-After') ?? 60);
            throw new ZkbRateLimitedException($retryAfter);
        }

        if (!$response->successful()) {
            Log::warning("ZkbClient: zKB returned status {$response->status()} for corp={$ownerCorporationId} type={$structureTypeId}");
            return null;
        }

        $kills = $response->json();
        if (!is_array($kills) || empty($kills)) {
            return null;
        }

        return $this->pickBestMatch($kills, $systemId, $destroyedAt, $matchWindowHours);
    }

    /**
     * Filter the zKB response to kills that plausibly match our destruction:
     *   - same solar system
     *   - killmail_time within +/- $matchWindowHours of our destroyed_at
     *
     * If multiple matches exist (rare — corp loses two same-type structures
     * in the same system within the window), pick the closest by time.
     */
    private function pickBestMatch(
        array $kills,
        int $systemId,
        CarbonInterface $destroyedAt,
        int $matchWindowHours
    ): ?array {
        $matchWindowSeconds = $matchWindowHours * 3600;
        $best = null;
        $bestDelta = PHP_INT_MAX;

        foreach ($kills as $kill) {
            // System filter
            if (!isset($kill['solar_system_id']) || (int) $kill['solar_system_id'] !== $systemId) {
                continue;
            }
            // Time window filter
            if (!isset($kill['killmail_time'])) {
                continue;
            }
            try {
                $killTime = Carbon::parse($kill['killmail_time']);
            } catch (\Throwable $e) {
                continue;
            }

            $delta = (int) abs($killTime->diffInSeconds($destroyedAt, false));
            if ($delta > $matchWindowSeconds) {
                continue;
            }
            if ($delta < $bestDelta) {
                $best = $kill;
                $bestDelta = $delta;
            }
        }

        return $best;
    }
}
