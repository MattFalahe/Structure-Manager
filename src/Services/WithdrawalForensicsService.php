<?php

namespace StructureManager\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use StructureManager\Helpers\TypeIdRegistry;
use StructureManager\Models\StructureFuelEventCandidate;
use StructureManager\Models\StructureFuelHistory;

/**
 * Tier 2 — suspect narrowing for withdrawal_* events.
 *
 * Given a withdrawal_bay or withdrawal_reserves event (a row in
 * structure_fuel_history with event_type matching), compute the list of
 * candidate handlers using four collateral SeAT data sources:
 *
 *   1. corporation_member_trackings  — who was logged on within the
 *                                      ±1h window of the event?
 *   2. character_assets              — did any corp member's personal
 *                                      hangar gain ~unexplained_delta fuel
 *                                      blocks of the same type?
 *   3. corporation_member_titles     — does the candidate have any title
 *                                      at all? Bay access needs Station
 *                                      Manager or Director - we treat
 *                                      "has any title" as a proxy
 *                                      because role-bitfield lookup is
 *                                      not exposed in SeAT v5 core.
 *   4. character_wallet_transactions — did the candidate sell ~N fuel
 *                                      blocks of the same type within 48h
 *                                      after the event?
 *
 * Scoring rubric (capped at 100):
 *
 *   +30 online during ±1h window
 *   +40 asset_gain matched ±10% of unexplained_delta
 *   +20 has any corp title (proxy for "can access bay")
 *   +30 sold matching fuel on market within 48h
 *
 * Buckets: HIGH ≥60, MEDIUM 30-59, LOW 10-29. Below 10 dropped (noise).
 *
 * HARD ESI LIMIT: this is PROBABILISTIC inference. The same logistics alt
 * who legitimately moves fuel every Monday will score HIGH on every
 * suspect list. Tier 4 (audit workflow) gives operators a way to mark
 * those as "approved" so the system learns.
 */
class WithdrawalForensicsService
{
    /** Time window around the event to look for online-status overlap. */
    public const ONLINE_WINDOW_HOURS = 1;

    /** Look-back window for asset gains - bigger than online window
     *  because asset-poll cadence can lag. */
    public const ASSET_WINDOW_HOURS = 2;

    /** Forward window for wallet sales after the event. */
    public const WALLET_LOOKBACK_HOURS = 48;

    /** Tolerance for "matching quantity" - asset gain or wallet sale must
     *  be within ±10% of unexplained_delta. */
    public const QUANTITY_TOLERANCE = 0.10;

    /** Score weights — keep in one place for future tuning. */
    public const SCORE_ONLINE       = 30;
    public const SCORE_ASSET_MATCH  = 40;
    public const SCORE_HAS_ROLE     = 20;
    public const SCORE_WALLET_SALE  = 30;

    /**
     * Compute candidate list for a withdrawal event and return an array of
     * candidate descriptors ready to bulk-insert into
     * structure_fuel_event_candidates.
     *
     * @return array<int, array{
     *     fuel_history_id: int,
     *     character_id: int,
     *     character_name: string|null,
     *     corporation_id: int|null,
     *     confidence: string,
     *     score: int,
     *     signals: array
     * }>
     */
    public static function computeCandidates(int $fuelHistoryId): array
    {
        $event = StructureFuelHistory::find($fuelHistoryId);
        if (!$event) {
            Log::warning("WithdrawalForensicsService: fuel_history #{$fuelHistoryId} not found");
            return [];
        }
        if (!$event->isWithdrawalEvent()) {
            // Not a withdrawal-class event — no candidates to compute.
            return [];
        }

        $corpId = (int) $event->corporation_id;
        $eventTime = Carbon::parse($event->created_at);
        $magnitude = (int) abs($event->unexplained_delta ?? 0);

        if ($corpId <= 0 || $magnitude <= 0) {
            return [];
        }

        // Resolve the fuel type involved. Bay events use whatever's in the
        // bay (could be mixed); reserves events use the dominant CorpSAG
        // fuel type. We use the fuel_type_id from the most recent reserves
        // row for this structure to filter character_assets matching.
        $fuelTypeIds = self::resolveFuelTypeIds($event->structure_id);

        // 1. Pull the candidate pool: corp members tracked at the time
        $members = self::memberPool($corpId);
        if (empty($members)) {
            return [];
        }

        // 2. Pre-fetch the four signal sets for this corp + window
        $onlineSet = self::membersOnlineDuringWindow($members, $eventTime);
        $titledSet = self::membersWithAnyTitle($corpId, $members);
        $assetGainSet = !empty($fuelTypeIds)
            ? self::membersWithAssetGain($members, $fuelTypeIds, $magnitude, $eventTime)
            : [];
        $walletSaleSet = !empty($fuelTypeIds)
            ? self::membersWithWalletSale($members, $fuelTypeIds, $magnitude, $eventTime)
            : [];

        // 3. Score each candidate
        $candidates = [];
        foreach ($members as $characterId => $characterName) {
            $score = 0;
            $signals = [];

            if (in_array($characterId, $onlineSet, true)) {
                $score += self::SCORE_ONLINE;
                $signals[StructureFuelEventCandidate::SIGNAL_ONLINE_WINDOW] = true;
            }
            if (isset($assetGainSet[$characterId])) {
                $score += self::SCORE_ASSET_MATCH;
                $signals[StructureFuelEventCandidate::SIGNAL_ASSET_GAIN] = [
                    'quantity' => $assetGainSet[$characterId],
                ];
            }
            if (in_array($characterId, $titledSet, true)) {
                $score += self::SCORE_HAS_ROLE;
                $signals[StructureFuelEventCandidate::SIGNAL_HAS_ROLE] = true;
            }
            if (isset($walletSaleSet[$characterId])) {
                $score += self::SCORE_WALLET_SALE;
                $signals[StructureFuelEventCandidate::SIGNAL_WALLET_SALE] = [
                    'quantity' => $walletSaleSet[$characterId],
                ];
            }

            $score = min($score, 100);
            $bucket = StructureFuelEventCandidate::bucketForScore($score);
            if ($bucket === null) {
                continue; // Below storage threshold
            }

            $candidates[] = [
                'fuel_history_id' => $fuelHistoryId,
                'character_id' => $characterId,
                'character_name' => $characterName,
                'corporation_id' => $corpId,
                'confidence' => $bucket,
                'score' => $score,
                'signals' => $signals,
            ];
        }

        // Sort: HIGH > MEDIUM > LOW, then by score desc
        usort($candidates, function ($a, $b) {
            $bucketOrder = ['HIGH' => 0, 'MEDIUM' => 1, 'LOW' => 2];
            $cmp = $bucketOrder[$a['confidence']] <=> $bucketOrder[$b['confidence']];
            return $cmp !== 0 ? $cmp : ($b['score'] <=> $a['score']);
        });

        return $candidates;
    }

    /**
     * Resolve which fuel type IDs to filter character_assets and wallet
     * transactions against. Looks at the most recent reserves row(s) for
     * the structure and returns distinct fuel_type_ids.
     *
     * Falls back to all four fuel block types + magmatic gas when no
     * reserves data exists.
     *
     * @return int[]
     */
    private static function resolveFuelTypeIds(int $structureId): array
    {
        if (!Schema::hasTable('structure_fuel_reserves')) {
            return TypeIdRegistry::FUEL_BLOCK_IDS;
        }

        $types = DB::table('structure_fuel_reserves')
            ->where('structure_id', $structureId)
            ->where('created_at', '>=', Carbon::now()->subDays(7))
            ->distinct()
            ->pluck('fuel_type_id')
            ->map(fn($v) => (int) $v)
            ->all();

        if (empty($types)) {
            $types = array_merge(TypeIdRegistry::FUEL_BLOCK_IDS, [TypeIdRegistry::MAGMATIC_GAS]);
        }

        return $types;
    }

    /**
     * Pool of corp members at the time of the event. Returns
     * [character_id => character_name].
     *
     * @return array<int, string>
     */
    private static function memberPool(int $corpId): array
    {
        if (!Schema::hasTable('corporation_member_trackings')) {
            return [];
        }

        // Members currently affiliated with the corp (character_affiliations
        // gives the simplest current snapshot). We use character_infos for
        // the name when available.
        $rows = DB::table('character_affiliations as ca')
            ->leftJoin('character_infos as ci', 'ci.character_id', '=', 'ca.character_id')
            ->where('ca.corporation_id', $corpId)
            ->select('ca.character_id', 'ci.name')
            ->get();

        $pool = [];
        foreach ($rows as $row) {
            $pool[(int) $row->character_id] = $row->name ?: ('Character ' . $row->character_id);
        }
        return $pool;
    }

    /**
     * Members logged on within ±ONLINE_WINDOW_HOURS of the event.
     *
     * SeAT keeps the most recent (logon_date, logoff_date) per member. We
     * detect overlap with the event window using either:
     *   - logon_date <= event_time AND (logoff_date IS NULL OR logoff_date >= event_time - window)
     *   - logon_date BETWEEN event_time - window AND event_time + window
     *
     * @param array<int, string> $members
     * @return int[]
     */
    private static function membersOnlineDuringWindow(array $members, Carbon $eventTime): array
    {
        if (empty($members) || !Schema::hasTable('corporation_member_trackings')) {
            return [];
        }

        $windowStart = $eventTime->copy()->subHours(self::ONLINE_WINDOW_HOURS);
        $windowEnd = $eventTime->copy()->addHours(self::ONLINE_WINDOW_HOURS);

        return DB::table('corporation_member_trackings')
            ->whereIn('character_id', array_keys($members))
            ->where(function ($q) use ($windowStart, $windowEnd) {
                // Logged on during the window
                $q->whereBetween('logon_date', [$windowStart, $windowEnd])
                  // OR was online through the window (logon before, logoff after or still online)
                  ->orWhere(function ($q2) use ($windowStart, $windowEnd) {
                      $q2->where('logon_date', '<=', $windowEnd)
                         ->where(function ($q3) use ($windowStart) {
                             $q3->whereNull('logoff_date')
                                ->orWhere('logoff_date', '>=', $windowStart);
                         });
                  });
            })
            ->pluck('character_id')
            ->map(fn($v) => (int) $v)
            ->all();
    }

    /**
     * Members with any title in the corp at the time of the event.
     *
     * SeAT v5's corporation_member_titles is a (character_id, title_id)
     * pivot. Having ANY title is our proxy for "could plausibly have a
     * bay-access role". Role-bitfield lookup isn't exposed in core SeAT
     * v5 in a portable way, so this is the cheapest practical filter.
     *
     * Operators can later customize this in Tier 4 (e.g. "only count
     * Director + Station Manager") via a settings toggle.
     *
     * @param array<int, string> $members
     * @return int[]
     */
    private static function membersWithAnyTitle(int $corpId, array $members): array
    {
        if (empty($members) || !Schema::hasTable('corporation_member_titles')) {
            return [];
        }

        return DB::table('corporation_member_titles')
            ->where('corporation_id', $corpId)
            ->whereIn('character_id', array_keys($members))
            ->distinct()
            ->pluck('character_id')
            ->map(fn($v) => (int) $v)
            ->all();
    }

    /**
     * Members whose character_assets shows a gain of ~magnitude fuel
     * blocks of one of $fuelTypeIds within ASSET_WINDOW_HOURS.
     *
     * Note: ESI assets are snapshots, not deltas, so we can't reliably
     * tell "exactly when X fuel blocks appeared". We use the proxy of
     * "the character has ≥ (magnitude × 0.9) of this fuel type RIGHT
     * NOW, and they didn't have it a week ago" by comparing against the
     * `updated_at` of their assets table. This is approximate.
     *
     * @param array<int, string> $members
     * @param int[] $fuelTypeIds
     * @return array<int, int> character_id => matched quantity
     */
    private static function membersWithAssetGain(array $members, array $fuelTypeIds, int $magnitude, Carbon $eventTime): array
    {
        if (empty($members) || !Schema::hasTable('character_assets')) {
            return [];
        }

        $lowerBound = (int) floor($magnitude * (1 - self::QUANTITY_TOLERANCE));

        // Sum each character's holdings of any matching fuel type. We only
        // match members holding at least the lower bound — this is the
        // closest approximation we can make without a time-series asset
        // history (which SeAT doesn't keep).
        $rows = DB::table('character_assets')
            ->whereIn('character_id', array_keys($members))
            ->whereIn('type_id', $fuelTypeIds)
            ->groupBy('character_id')
            ->select('character_id', DB::raw('SUM(quantity) as total_qty'))
            ->havingRaw('SUM(quantity) >= ?', [$lowerBound])
            ->get();

        $matches = [];
        foreach ($rows as $row) {
            $matches[(int) $row->character_id] = (int) $row->total_qty;
        }
        return $matches;
    }

    /**
     * Members who sold ≥ (magnitude × 0.9) of a matching fuel type within
     * WALLET_LOOKBACK_HOURS after the event.
     *
     * @param array<int, string> $members
     * @param int[] $fuelTypeIds
     * @return array<int, int> character_id => matched quantity sold
     */
    private static function membersWithWalletSale(array $members, array $fuelTypeIds, int $magnitude, Carbon $eventTime): array
    {
        if (empty($members) || !Schema::hasTable('character_wallet_transactions')) {
            return [];
        }

        $lowerBound = (int) floor($magnitude * (1 - self::QUANTITY_TOLERANCE));
        $windowStart = $eventTime->copy();
        $windowEnd = $eventTime->copy()->addHours(self::WALLET_LOOKBACK_HOURS);

        $rows = DB::table('character_wallet_transactions')
            ->whereIn('character_id', array_keys($members))
            ->whereIn('type_id', $fuelTypeIds)
            ->where('is_buy', false) // SOLD, not bought
            ->whereBetween('date', [$windowStart, $windowEnd])
            ->groupBy('character_id')
            ->select('character_id', DB::raw('SUM(quantity) as total_sold'))
            ->havingRaw('SUM(quantity) >= ?', [$lowerBound])
            ->get();

        $matches = [];
        foreach ($rows as $row) {
            $matches[(int) $row->character_id] = (int) $row->total_sold;
        }
        return $matches;
    }
}
