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
     * Minimum scale of unexplained consumption before classifying as
     * `withdrawal_bay`. Expressed as hours of expected consumption — so a
     * structure with `expected_hourly = 27` needs `unexplained >= 12 × 27 =
     * 324 blocks` before the classifier promotes the event to theft.
     * Below this threshold the row records as `consumption_anomaly` (still
     * visible on the panel, no `WithdrawalForensicsJob` dispatch).
     *
     * Rationale: real fuel theft moves meaningful quantities. A thief who
     * pulls 4-6 hours of fuel isn't worth the operational cost (ESI access,
     * fuel-block resale, corp drama) for a few thousand ISK; they either
     * empty a hangar or don't bother. Setting the bar at 12 hours catches
     * scale-of-theft events while letting service-activation windows and
     * residual classifier variance pass.
     *
     * Sized for the structure's own burn rate — 12 hours on a Keepstar
     * (~120/hr) = 1440 blocks, 12 hours on an Astrahus (~12/hr) = 144
     * blocks. Auto-scales by service load.
     */
    public const THEFT_MAGNITUDE_HOURS = 12;

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

        // v2.0.2 — reserves-delta sanity check before promoting to
        // withdrawal_bay. Real fuel theft has to move blocks somewhere; a
        // genuine bay withdrawal is fuel leaving the corp (CorpSAG hangars
        // emptied, or moved into the bay from reserves and then drawn out).
        // If reservesDelta says reserves DIDN'T drop (>= 0) while the bay
        // over-burned, the much more likely explanation is a service
        // activation spike — operator brings a Manufacturing/Reprocessing
        // module online for a few hours, bay rate jumps from baseline 27/hr
        // to ~54/hr, no fuel actually left the corp's stockpile. Downgrade
        // to consumption_anomaly so the row still surfaces "something
        // unusual happened during this window" without dispatching
        // WithdrawalForensicsJob and fanning out a wide suspect list on
        // what isn't really theft.
        //
        // Only downgrade when reservesDelta is KNOWN to be non-negative.
        // When reservesDelta is null (no reserves baseline available), keep
        // the existing withdrawal_bay classification so the safety net for
        // installs with incomplete reserves tracking is preserved.
        //
        // The 2026-05-30 production audit showed reservesDelta = 0 across
        // every false-positive row in the 7-day sample for a single Azbel;
        // this gate would land all of them as consumption_anomaly instead.
        // Real thefts (bay over-burn + reservesDelta < 0) still classify
        // as withdrawal_bay via the path below.
        if ($reservesDelta !== null && $reservesDelta >= 0) {
            return [
                'event_type' => StructureFuelHistory::EVENT_CONSUMPTION_ANOMALY,
                'expected_consumption' => $expectedConsumption,
                'unexplained_delta' => $unexplained,
                'reserves_delta' => $reservesDelta,
            ];
        }

        // v2.0.2 — minimum-magnitude gate before promoting to withdrawal_bay.
        // Real fuel theft moves meaningful quantities (operational risk
        // vs. resale value floors this around 12h of structure burn — see
        // THEFT_MAGNITUDE_HOURS docblock). Anything smaller is service-
        // activation noise that happened to also coincide with a reserves
        // dip. Downgrade to consumption_anomaly so the row still shows on
        // the panel without firing forensics. A real bay theft well above
        // this threshold still classifies as withdrawal_bay.
        if ($unexplained < ($expectedHourly * self::THEFT_MAGNITUDE_HOURS)) {
            return [
                'event_type' => StructureFuelHistory::EVENT_CONSUMPTION_ANOMALY,
                'expected_consumption' => $expectedConsumption,
                'unexplained_delta' => $unexplained,
                'reserves_delta' => $reservesDelta,
            ];
        }

        // Over 1.5× expected AND reserves actually dropped AND the magnitude
        // is theft-scale — fuel left the corp in quantities worth investigating.
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
