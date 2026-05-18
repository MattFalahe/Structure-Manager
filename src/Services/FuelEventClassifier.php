<?php

namespace StructureManager\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use StructureManager\Helpers\FuelCalculator;
use StructureManager\Helpers\TypeIdRegistry;
use StructureManager\Models\StructureFuelHistory;

/**
 * Pure-function classifier that decides what KIND of fuel event happened
 * between two consecutive bay snapshots.
 *
 * Replaces the v1.x logic which conflated three distinct things into one
 * column: "fuel decreased" was always labelled "consumption", "fuel
 * increased" was always labelled "refuel". A 5,000-block theft and a
 * 4,032-block legit refuel produced identical history rows.
 *
 * The classifier combines bay-delta + reserve-delta + expected-from-services
 * to produce one of eight classifications:
 *
 *   - refuel_internal     bay went UP and reserves went DOWN by ≥80%
 *                         of the bay gain → someone moved CorpSAG → bay
 *   - refuel_external     bay went UP and reserves barely changed → fuel
 *                         entered from outside the structure (haul/buy)
 *   - unexplained_gain    bay went UP and reserves changed but the
 *                         numbers don't add up — surface for review
 *   - consumption_normal  bay went DOWN within ±15% of expected service
 *                         consumption → natural burn
 *   - consumption_anomaly bay went DOWN 15-50% more than expected →
 *                         a service likely activated or low-power lifted
 *   - withdrawal_bay      bay went DOWN by more than 1.5× expected →
 *                         high-confidence "someone yanked from the bay"
 *   - withdrawal_reserves bay normal, but CorpSAG dropped > 500 blocks →
 *                         high-confidence "fuel left the corp" (the bay
 *                         didn't catch it, so it didn't go to the structure)
 *   - unclassified        first snapshot, zero hours elapsed, or no
 *                         baseline data to compare against
 *
 * Hard ESI limit: this classifier infers "what happened" from quantity
 * changes. It cannot identify WHO caused the change — that's
 * WithdrawalForensicsService's job, and even that is probabilistic.
 *
 * Configuration thresholds are class constants. Tier 3 will replace these
 * with per-structure baselines computed from 30+ days of history (Z-score
 * + time-of-day percentile). For Tier 1, sensible hardcoded defaults.
 */
class FuelEventClassifier
{
    /** Tolerance band around expected consumption: ±15% counts as normal. */
    public const NORMAL_TOLERANCE = 0.15;

    /** Anomaly upper bound: between +15% and +50% over expected = consumption_anomaly. */
    public const ANOMALY_TOLERANCE = 0.50;

    /**
     * Reserve-drop threshold (blocks) below which a reserve withdrawal
     * fires its own classification even when the bay was burning normally.
     * Set deliberately high to avoid false-positives on small operational
     * shuffles (e.g. someone consolidating from CorpSAG3 to CorpSAG1).
     */
    public const RESERVE_WITHDRAWAL_THRESHOLD = 500;

    /**
     * Minimum bay-gain (blocks) to call something a refuel. Below this we
     * treat the row as consumption_normal even if `bay_delta > 0`, because
     * tiny positive deltas are most likely measurement noise (asset poll
     * happened in the middle of a transaction).
     */
    public const REFUEL_FLOOR = 50;

    /**
     * Internal-refuel detection: reserves must lose at least this fraction
     * of the bay gain to count as "fuel came from CorpSAG". Tuned at 0.8
     * so a partial top-up + small external haul still classifies cleanly.
     */
    public const INTERNAL_REFUEL_RATIO = 0.8;

    /**
     * External-refuel detection: reserves changed by less than this
     * fraction of the bay gain → reserves essentially unchanged.
     */
    public const EXTERNAL_REFUEL_RATIO = 0.2;

    /**
     * Classify a single fuel event.
     *
     * @param array{
     *     prev_bay: int|null,
     *     current_bay: int,
     *     prev_reserves: int|null,
     *     current_reserves: int|null,
     *     expected_hourly: float|null,
     *     hours_elapsed: float
     * } $ctx
     *
     * @return array{
     *     event_type: string,
     *     expected_consumption: float|null,
     *     unexplained_delta: int|null,
     *     reserves_delta: int|null
     * }
     */
    public static function classify(array $ctx): array
    {
        $prevBay = $ctx['prev_bay'] ?? null;
        $currentBay = (int) ($ctx['current_bay'] ?? 0);
        $prevReserves = $ctx['prev_reserves'] ?? null;
        $currentReserves = $ctx['current_reserves'] ?? null;
        $expectedHourly = $ctx['expected_hourly'] ?? null;
        $hoursElapsed = (float) ($ctx['hours_elapsed'] ?? 0);

        $reservesDelta = ($prevReserves !== null && $currentReserves !== null)
            ? ($currentReserves - $prevReserves)
            : null;

        // No prior snapshot, or zero-hours edge case → can't classify
        if ($prevBay === null || $hoursElapsed <= 0) {
            return [
                'event_type' => StructureFuelHistory::EVENT_UNCLASSIFIED,
                'expected_consumption' => null,
                'unexplained_delta' => null,
                'reserves_delta' => $reservesDelta,
            ];
        }

        $bayDelta = $currentBay - $prevBay;
        $expectedConsumption = ($expectedHourly !== null && $expectedHourly > 0)
            ? round($expectedHourly * $hoursElapsed, 2)
            : null;

        // ============================================================
        // CASE 1: Bay GAINED fuel (someone added blocks)
        // ============================================================
        if ($bayDelta > self::REFUEL_FLOOR) {
            $eventType = self::classifyRefuel($bayDelta, $reservesDelta);
            return [
                'event_type' => $eventType,
                'expected_consumption' => $expectedConsumption,
                // Refuel delta is the bay gain - we use unexplained_delta
                // here to record "how much was added" (negative because
                // gain is the opposite of consumption).
                'unexplained_delta' => -$bayDelta,
                'reserves_delta' => $reservesDelta,
            ];
        }

        // ============================================================
        // CASE 2: Reserves dropped significantly — withdrawal_reserves
        //         fires even when bay was burning normally, because
        //         "reserves down + bay didn't gain" = fuel left the corp.
        // ============================================================
        if ($reservesDelta !== null && $reservesDelta <= -self::RESERVE_WITHDRAWAL_THRESHOLD) {
            $unexplained = -$reservesDelta; // positive number
            return [
                'event_type' => StructureFuelHistory::EVENT_WITHDRAWAL_RESERVES,
                'expected_consumption' => $expectedConsumption,
                'unexplained_delta' => $unexplained,
                'reserves_delta' => $reservesDelta,
            ];
        }

        // ============================================================
        // CASE 3: Bay LOST fuel (consumption or bay-withdrawal)
        // ============================================================
        $actualConsumed = -$bayDelta; // positive number

        if ($expectedConsumption === null || $expectedConsumption <= 0) {
            // No baseline → can't say if normal or anomalous. Default
            // to unclassified rather than mis-labelling. This is the
            // expected state for the FIRST poll after the migration
            // ships, before service-rate data is reliable.
            return [
                'event_type' => StructureFuelHistory::EVENT_UNCLASSIFIED,
                'expected_consumption' => null,
                'unexplained_delta' => null,
                'reserves_delta' => $reservesDelta,
            ];
        }

        $unexplained = (int) round($actualConsumed - $expectedConsumption);
        $overBurnRatio = $unexplained / $expectedConsumption;

        if ($overBurnRatio <= self::NORMAL_TOLERANCE) {
            return [
                'event_type' => StructureFuelHistory::EVENT_CONSUMPTION_NORMAL,
                'expected_consumption' => $expectedConsumption,
                'unexplained_delta' => $unexplained,
                'reserves_delta' => $reservesDelta,
            ];
        }

        if ($overBurnRatio <= self::ANOMALY_TOLERANCE) {
            return [
                'event_type' => StructureFuelHistory::EVENT_CONSUMPTION_ANOMALY,
                'expected_consumption' => $expectedConsumption,
                'unexplained_delta' => $unexplained,
                'reserves_delta' => $reservesDelta,
            ];
        }

        // Over 1.5× expected — too much for normal service activation
        return [
            'event_type' => StructureFuelHistory::EVENT_WITHDRAWAL_BAY,
            'expected_consumption' => $expectedConsumption,
            'unexplained_delta' => $unexplained,
            'reserves_delta' => $reservesDelta,
        ];
    }

    /**
     * Sub-classifier for "bay gained fuel" — refuel_internal vs
     * refuel_external vs unexplained_gain.
     */
    private static function classifyRefuel(int $bayDelta, ?int $reservesDelta): string
    {
        // No reserve data → can't distinguish source. Default to external
        // (the bay grew somehow, just can't say from where).
        if ($reservesDelta === null) {
            return StructureFuelHistory::EVENT_REFUEL_EXTERNAL;
        }

        // Reserves dropped by ≥80% of the bay gain → moved CorpSAG → bay
        $internalThreshold = (int) round($bayDelta * self::INTERNAL_REFUEL_RATIO);
        if ($reservesDelta <= -$internalThreshold) {
            return StructureFuelHistory::EVENT_REFUEL_INTERNAL;
        }

        // Reserves barely moved → fuel came from outside the structure
        $externalThreshold = (int) round($bayDelta * self::EXTERNAL_REFUEL_RATIO);
        if (abs($reservesDelta) < $externalThreshold) {
            return StructureFuelHistory::EVENT_REFUEL_EXTERNAL;
        }

        // Reserves changed but not consistently with either pattern.
        // E.g. reserves +200 while bay +5000 — surface for review.
        return StructureFuelHistory::EVENT_UNEXPLAINED_GAIN;
    }

    /**
     * Convenience: pull the expected hourly consumption for a structure
     * from FuelCalculator::calculateFromActiveServices. Returns null on
     * failure so the classifier degrades gracefully to 'unclassified'.
     */
    public static function expectedHourlyConsumption(int $structureId): ?float
    {
        try {
            $result = FuelCalculator::calculateFromActiveServices($structureId);
            if (isset($result['hourly']) && is_numeric($result['hourly']) && $result['hourly'] > 0) {
                return (float) $result['hourly'];
            }
        } catch (\Throwable $e) {
            // Fall through to null - the classifier handles missing baselines.
        }
        return null;
    }

    /**
     * Compute the LIVE total reserves quantity for a structure across all
     * CorpSAG hangars + nested Office containers, querying SeAT's
     * corporation_assets table directly.
     *
     * IMPORTANT: this is intentionally NOT reading from the
     * structure_fuel_reserves snapshot table — TrackFuelConsumption runs
     * the classifier BEFORE trackStructureReserves refreshes the snapshot,
     * so the snapshot table holds last-poll values during classification.
     * Querying corporation_assets directly is the only way to see "current
     * reserves NOW" at classification time.
     *
     * Returns null when corporation_assets has no relevant rows (also
     * covers "table doesn't exist" on stripped-down SeAT installs).
     */
    public static function liveTotalReserves(int $structureId): ?int
    {
        if (!Schema::hasTable('corporation_assets')) {
            return null;
        }

        // Fuel block types + magmatic gas (Metenox-relevant on regular structures)
        $fuelTypes = array_merge(TypeIdRegistry::FUEL_BLOCK_IDS, [TypeIdRegistry::MAGMATIC_GAS]);

        // METHOD 1: direct CorpSAG holdings on the structure
        $direct = (int) DB::table('corporation_assets')
            ->where('location_id', $structureId)
            ->where('location_flag', 'LIKE', 'CorpSAG%')
            ->whereIn('type_id', $fuelTypes)
            ->sum('quantity');

        // METHOD 2: nested Office containers inside the structure
        $nested = 0;
        if (Schema::hasTable('invTypes')) {
            $nested = (int) DB::table('corporation_assets as fuel')
                ->join('corporation_assets as office', 'fuel.location_id', '=', 'office.item_id')
                ->join('invTypes as office_type', 'office.type_id', '=', 'office_type.typeID')
                ->where('office.location_id', $structureId)
                ->where('office_type.typeName', 'Office')
                ->where('fuel.location_flag', 'LIKE', 'CorpSAG%')
                ->whereIn('fuel.type_id', $fuelTypes)
                ->sum('fuel.quantity');
        }

        $total = $direct + $nested;
        // 0 is a valid signal — "the structure has CorpSAGs but they're empty".
        // Return null only when both sources legitimately had no rows AND
        // there's no history to suggest reserves SHOULD exist; treating
        // any-zero as null would prevent classifier from spotting "reserves
        // emptied from 5000 → 0", which is exactly a withdrawal_reserves.
        return $total;
    }

    /**
     * @deprecated since v2.0.0 — use liveTotalReserves() instead.
     * Kept temporarily for any external consumer; reads from the
     * snapshot table (one-poll-stale during classification).
     */
    public static function currentTotalReserves(int $structureId): ?int
    {
        $rows = \StructureManager\Models\StructureFuelReserves::getCurrentReserves($structureId);
        if ($rows->isEmpty()) {
            return null;
        }
        return (int) $rows->sum('reserve_quantity');
    }
}
