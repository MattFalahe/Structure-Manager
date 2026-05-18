<?php

namespace StructureManager\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use StructureManager\Helpers\FuelCalculator;
use StructureManager\Helpers\PosFuelCalculator;
use StructureManager\Helpers\TypeIdRegistry;
use StructureManager\Integrations\ManagerCoreIntegration;

/**
 * Computes fuel-cost economics across the operators structures over a
 * configurable look-back window.
 *
 * Pulls per-day actuals from the `*fuel_consumption` aggregates SM keeps
 * (so a structure that was offline for a week shows up as a true gap, not
 * extrapolated from an instantaneous rate). Multiplies each consumed unit
 * by Manager Cores priceForPlugin('structure-manager', $typeId), which
 * honours SMs registered preference (Jita sell by default) and any admin
 * override set in MCs Pricing Preferences page.
 *
 * Returns a single payload with totals, per-system, per-structure, per-type,
 * and per-day-trend buckets. The controller picks which buckets to render
 * for each tab; the math side computes them all in one pass for cache
 * coherency.
 *
 * Corp scoping:
 *   - $corpScope = null  → all corps (admin / superuser caller)
 *   - $corpScope = []    → no corps (return empty payload — defensive)
 *   - $corpScope = [...] → only structures owned by these corp_ids
 *
 * No cache here. The controller wraps the call in MCs cache layer (the
 * same per-section cache the diagnostic page uses) and the page exposes
 * a Force refresh button for live re-compute.
 */
class FuelEconomicsService
{
    public const PLUGIN_KEY = 'structure-manager';

    /**
     * Allowed look-back windows. 90/180/365 covers everything from a
     * single moon-frack cycle (90d) to a full year-over-year comparison.
     * Athanors moon cycle is ~50 days, so 30d would only cover one cycle
     * which is too noisy for a meaningful prognosis.
     */
    public const PERIODS_DAYS = [90, 180, 365];
    public const DEFAULT_PERIOD_DAYS = 180;

    /**
     * Build the full economics payload for a period and corp scope.
     *
     * Two data sources, two purposes:
     *
     *   PROJECTION (totals, by_system, by_structure, by_type) is computed
     *   from FuelCalculator + PosFuelCalculator. They derive consumption
     *   from active services + EVE static data — same path the Logistics
     *   Report uses. This does NOT depend on the consumption-tracker job
     *   running, and gives the actual go-forward cost estimate.
     *
     *   HISTORICAL (trend chart, services_offline_days) is computed from
     *   the consumption tables. That answers "what HAS happened" and
     *   includes gaps when a structure was offline.
     *
     * Upwell + Metenox fuel-block side prices at the cheapest of the
     * 4 fuel-block typeIDs (Nitrogen / Hydrogen / Helium / Oxygen) since
     * Upwell can use any of them. POS fuel-block side prices at the
     * racial type since POSes can't substitute.
     *
     * Strontium and POS reinforce reagents are NOT included in the
     * projection because they're only consumed during reinforcement
     * (rare, irregular). Cyno reagents are similarly omitted as they're
     * quantity-based, not time-based.
     *
     * @param int $periodDays
     * @param array<int>|null $corpScope null = all corps, [] = none, [...] = filter
     */
    public function buildEconomics(int $periodDays = self::DEFAULT_PERIOD_DAYS, ?array $corpScope = null): array
    {
        $periodDays = in_array($periodDays, self::PERIODS_DAYS, true) ? $periodDays : self::DEFAULT_PERIOD_DAYS;
        $startDate  = Carbon::now()->startOfDay()->subDays($periodDays);

        // Empty scope = no work
        if ($corpScope !== null && empty($corpScope)) {
            return $this->emptyPayload($periodDays);
        }

        // ---- 1. Resolve prices for all 12 fuel-related typeIDs upfront ----
        // Already subscribed at SM boot (registerPricingPreference + subscribePricingTypes),
        // so this just reads from MC's price cache. Includes all 4 fuel-block
        // types so we can pick the cheapest for Upwell.
        $allTypeIds = ManagerCoreIntegration::REQUIRED_PRICING_TYPE_IDS;
        $prices     = ManagerCoreIntegration::pricesForTypes($allTypeIds);

        // ---- 2. Pick cheapest Upwell-compatible fuel block right now ----
        $cheapestBlock = $this->pickCheapestFuelBlock($prices);

        // ---- 3. Walk structures, compute calculator-based projections ----
        // Each row: (structure_id, type_id, quantity, ...). type_id is what
        // the structure WILL spend ISK on for the projection window.
        $upwellRows  = $this->collectUpwellProjection($corpScope, $periodDays, $cheapestBlock);
        $metenoxRows = $this->collectMetenoxProjection($corpScope, $periodDays, $cheapestBlock);
        $posRows     = $this->collectPosProjection($corpScope, $periodDays);

        $consumption = array_merge($upwellRows, $metenoxRows, $posRows);

        if (empty($consumption)) {
            return $this->emptyPayload($periodDays);
        }

        // ---- 4. Multiply quantities by prices, build all breakdowns ----
        // Each consumption row carries:
        //   - type_id      = what the structure is CURRENTLY using
        //   - optimal_type_id = what it would use if switched (substitutable
        //                       Upwell/Metenox blocks only — POS racial and
        //                       magmatic gas have this unset)
        // We price two ways: at current type (real cost) and at optimal type
        // (best case). Per-structure savings = current - optimal.
        $byStructure = [];
        $bySystem    = [];
        $byType      = [];
        $optimizationSavingsPeriod = 0.0;
        $structuresOptimizable = 0;

        // Pre-resolve a fallback price for rows whose specific typeID
        // doesn't have a cached price yet. The cheapest fuel block is
        // always cached (we just used it) so it's a reliable fallback.
        // Without this, a POS using Hydrogen (racial) where Hydrogen
        // price hasn't been fetched yet by MC would get dropped from
        // the per-structure list entirely — the POS would vanish from
        // the page rather than showing with a price-fallback caveat.
        $fallbackPrice = $cheapestBlock['price'] ?? null;

        foreach ($consumption as $row) {
            $currentPrice    = $prices[$row['type_id']] ?? null;
            $usedFallback    = false;
            if ($currentPrice === null) {
                if ($fallbackPrice === null) {
                    continue; // truly nothing priced — drop
                }
                $currentPrice = $fallbackPrice;
                $usedFallback = true;
            }

            $currentIsk = $row['quantity'] * $currentPrice;

            // Optimal price: only meaningful for substitutable rows
            // (Upwell + Metenox fuel blocks). For everything else (POS
            // racial, magmatic gas, charters), optimal = current.
            $optimalPrice = $currentPrice;
            if (!empty($row['fuel_substitutable']) && isset($row['optimal_type_id'])) {
                $optimalPrice = $prices[$row['optimal_type_id']] ?? $currentPrice;
            }
            $optimalIsk = $row['quantity'] * $optimalPrice;
            $rowSavings = max(0, $currentIsk - $optimalIsk);

            $sid = $row['structure_id'];
            if (!isset($byStructure[$sid])) {
                $byStructure[$sid] = [
                    'structure_id'        => $sid,
                    'structure_name'      => $row['structure_name'] ?? "Structure #{$sid}",
                    'type_id'             => $row['structure_type_id'] ?? null,
                    'type_name'            => $row['structure_type_name'] ?? null,
                    'corp_id'             => $row['corporation_id'] ?? null,
                    'corp_name'           => $row['corporation_name'] ?? null,
                    'system_id'           => $row['system_id'] ?? null,
                    'system_name'         => $row['system_name'] ?? null,
                    'period_isk'          => 0.0,    // CURRENT cost (what they'll really spend)
                    'period_optimal_isk'  => 0.0,    // BEST cost (if they switched everything)
                    'period_savings'      => 0.0,    // current - optimal
                    'is_pos'              => $row['is_pos'] ?? false,
                    // Surface what the structure is currently using vs the
                    // suggested optimal so the per-structure table can show
                    // both. Picks the substitutable row's types when present
                    // (POS racial / gas rows don't override these fields).
                    'current_fuel_type_id'    => null,
                    'current_fuel_type_name'  => null,
                    'optimal_fuel_type_id'    => null,
                    'optimal_fuel_type_name'  => null,
                    'fuel_substitutable'      => false,
                    'has_bay_contents'        => false,
                    // POS-specific (set later from racial fuel block row)
                    'pos_racial_fuel_id'      => null,
                    'pos_racial_fuel_name'    => null,
                    'pos_race'                => null,
                ];
            }
            $byStructure[$sid]['period_isk']         += $currentIsk;
            $byStructure[$sid]['period_optimal_isk'] += $optimalIsk;
            $byStructure[$sid]['period_savings']     += $rowSavings;

            // Capture current/optimal fuel types from the substitutable row
            // (Upwell or Metenox fuel-block side, not gas / charters / racial).
            if (!empty($row['fuel_substitutable']) && !$byStructure[$sid]['fuel_substitutable']) {
                $byStructure[$sid]['current_fuel_type_id']   = $row['type_id'];
                $byStructure[$sid]['current_fuel_type_name'] = $row['type_name'] ?? null;
                $byStructure[$sid]['optimal_fuel_type_id']   = $row['optimal_type_id']   ?? $row['type_id'];
                $byStructure[$sid]['optimal_fuel_type_name'] = $row['optimal_type_name'] ?? $row['type_name'];
                $byStructure[$sid]['fuel_substitutable']     = true;
                $byStructure[$sid]['has_bay_contents']       = $row['has_bay_contents'] ?? false;
            }

            // POS racial fuel detection: when the row is for a POS AND the
            // type_id is one of the four fuel block typeIDs, that's the
            // structure's racial fuel. Capture it so the per-structure
            // table can show 'Hydrogen — Minmatar racial' and the breakdown
            // can split POSes by race.
            if (!empty($row['is_pos']) && in_array((int) $row['type_id'], [4051, 4246, 4247, 4312], true)) {
                $byStructure[$sid]['pos_racial_fuel_id']   = (int) $row['type_id'];
                $byStructure[$sid]['pos_racial_fuel_name'] = $row['type_name'];
                static $blockToRace = [
                    4051 => 'caldari',
                    4246 => 'minmatar',
                    4247 => 'amarr',
                    4312 => 'gallente',
                ];
                $byStructure[$sid]['pos_race'] = $blockToRace[(int) $row['type_id']] ?? 'other';
            }

            $key = $row['system_id'] ?? 0;
            if (!isset($bySystem[$key])) {
                $bySystem[$key] = [
                    'system_id'        => $row['system_id'],
                    'system_name'      => $row['system_name'] ?? 'Unknown',
                    'structures_count' => 0,
                    'period_isk'       => 0.0,
                    'structure_ids'    => [],
                ];
            }
            $bySystem[$key]['period_isk'] += $currentIsk;
            if (!in_array($sid, $bySystem[$key]['structure_ids'], true)) {
                $bySystem[$key]['structure_ids'][] = $sid;
                $bySystem[$key]['structures_count']++;
            }

            $tkey = $row['type_id'];
            if (!isset($byType[$tkey])) {
                $byType[$tkey] = [
                    'type_id'         => $tkey,
                    'type_name'       => $row['type_name'] ?? "Type #{$tkey}",
                    'period_isk'      => 0.0,
                    'period_quantity' => 0,
                ];
            }
            $byType[$tkey]['period_isk']      += $currentIsk;
            $byType[$tkey]['period_quantity'] += $row['quantity'];
        }

        // ---- 5. Derive period totals + scaled buckets ----
        // Calculator-based projection gives the cost across the full
        // periodDays window (current consumption rate × hours × days),
        // so the denominator is straightforwardly periodDays.
        $periodTotal = array_sum(array_column($byStructure, 'period_isk'));
        $totals      = $this->scaleTotals($periodTotal, $periodDays);

        // Aggregate optimization summary across all structures.
        // Counts only structures that ARE substitutable AND have a
        // detected current fuel that differs from optimal — i.e. a real
        // savings opportunity (not "we don't know what's in the bay" or
        // "POS, can't switch anyway").
        foreach ($byStructure as $s) {
            if ($s['period_savings'] > 0) {
                $optimizationSavingsPeriod += $s['period_savings'];
                $structuresOptimizable++;
            }
        }

        foreach ($byStructure as &$s) {
            $s['weekly_isk']           = $this->scale($s['period_isk'], $periodDays, 7);
            $s['monthly_isk']          = $this->scale($s['period_isk'], $periodDays, 30);
            $s['monthly_savings_isk']  = $this->scale($s['period_savings'], $periodDays, 30);
        }
        unset($s);
        foreach ($bySystem as &$s) {
            $s['weekly_isk']  = $this->scale($s['period_isk'], $periodDays, 7);
            $s['monthly_isk'] = $this->scale($s['period_isk'], $periodDays, 30);
            unset($s['structure_ids']); // internal, drop from output
        }
        unset($s);

        // ---- 6. Services-offline detection (HISTORICAL — fuel_expires-based) ----
        // Per Matt's suggestion: detect actual offline windows by walking
        // structure_fuel_history rows and finding gaps where:
        //
        //   prev_row.fuel_expires < next_row.created_at
        //
        // i.e. between two snapshots, the structure's fuel ran out (the
        // earlier snapshot's projected expiry passed before the next
        // snapshot arrived). The duration of that gap = offline time.
        //
        // Strictly-better than the consumption-table active-days approach:
        // works correctly even when the tracker has only a partial history
        // window (e.g. install started recording 30 days ago but operator
        // picked a 180-day look-back), because we only count gaps WITHIN
        // observed history rather than missing days at the start of the
        // window.
        $offlineByStructure = $this->detectOfflineFromFuelHistory($startDate, $corpScope);
        foreach ($byStructure as &$s) {
            $sid           = $s['structure_id'];
            $offlineHours  = $offlineByStructure[$sid] ?? 0.0;
            $offlineDays   = round($offlineHours / 24.0, 1);
            $s['services_offline_days'] = $offlineDays;
            if ($offlineHours > 0 && $periodDays > 0) {
                $iskPerHour = $s['period_isk'] / max(1, $periodDays * 24);
                $s['services_offline_isk_equivalent'] = $iskPerHour * $offlineHours;
            } else {
                $s['services_offline_isk_equivalent'] = 0.0;
            }
        }
        unset($s);

        // Sort by spend descending so the worst offenders are at the top
        $bySystemList    = array_values(array_reverse(self::sortByPeriodIsk($bySystem)));
        $byStructureList = array_values(array_reverse(self::sortByPeriodIsk($byStructure)));
        $byTypeList      = array_values(array_reverse(self::sortByPeriodIsk($byType)));

        // ---- 5. Daily trend data ----
        // Walk the consumption rows again with a date dimension this time
        // so the trend chart can render daily ISK over the window. Skipped
        // for the empty-corp-scope path (early return above) so this only
        // runs when there's data to summarise.
        $trend = $this->buildDailyTrend($startDate, $corpScope, $prices);

        // ---- 6. Pricing meta (so the page can show "Source: Jita sell") ----
        $pricingMeta = $this->resolvePricingMeta();

        // Optimization summary across all structures combined
        $optimization = [
            'savings_period'    => $optimizationSavingsPeriod,
            'savings_weekly'    => $this->scale($optimizationSavingsPeriod, $periodDays, 7),
            'savings_monthly'   => $this->scale($optimizationSavingsPeriod, $periodDays, 30),
            'savings_yearly'    => $this->scale($optimizationSavingsPeriod, $periodDays, 365),
            'structures_count'  => $structuresOptimizable,
        ];

        // Structure-type breakdown so the page can show "X Upwells / Y POSes
        // (race split) / Z Metenoxes". Helps operators sanity-check that
        // every structure they expect is being counted.
        $breakdown = $this->buildStructureBreakdown($byStructureList);

        return [
            'period_days'         => $periodDays,
            'period_start'        => $startDate->toDateString(),
            'period_end'          => Carbon::now()->toDateString(),
            'projection_basis'    => 'calculator',  // signal: math source is FuelCalculator/PosFuelCalculator
            'totals'              => $totals,
            'by_system'           => $bySystemList,
            'by_structure'        => $byStructureList,
            'by_type'             => $byTypeList,
            'trend'               => $trend,
            'pricing_meta'        => $pricingMeta,
            'cheapest_fuel_block' => $cheapestBlock, // {type_id, type_name, price, all_prices}
            'optimization'        => $optimization,  // potential savings if non-optimal structures switched
            'breakdown'           => $breakdown,     // counts by kind + POS race split
        ];
    }

    /**
     * Per-kind structure summary for the breakdown banner.
     *
     *   upwell_count       : Upwell structures (excluding Metenox)
     *   metenox_count      : Metenox Moon Drills
     *   pos_count          : Player-owned starbases
     *   pos_by_race        : ['caldari' => n, 'minmatar' => n, ...] for POSes
     *   total_count        : sum of the above
     *   substitutable_count: structures whose fuel block is substitutable
     *                        (Upwell + Metenox; POS racial does not count)
     *
     * Walks the already-aggregated by_structure list rather than re-running
     * the queries — this is essentially free.
     */
    private function buildStructureBreakdown(array $byStructureList): array
    {
        $upwellCount  = 0;
        $metenoxCount = 0;
        $posCount     = 0;
        $posByRace    = [
            'caldari'  => 0,
            'minmatar' => 0,
            'amarr'    => 0,
            'gallente' => 0,
            'other'    => 0,
        ];
        $substitutableCount = 0;

        // Reverse-lookup table: racial fuel block typeID → race name
        $blockToRace = [
            4051 => 'caldari',
            4246 => 'minmatar',
            4247 => 'amarr',
            4312 => 'gallente',
        ];

        foreach ($byStructureList as $s) {
            if (!empty($s['is_pos'])) {
                $posCount++;
                $race = $s['pos_race'] ?? 'other';
                if (!isset($posByRace[$race])) {
                    $posByRace[$race] = 0;
                }
                $posByRace[$race]++;
            } elseif ($s['type_id'] === 81826) {
                // Metenox — Upwell structure with type_id=81826
                $metenoxCount++;
                if (!empty($s['fuel_substitutable'])) {
                    $substitutableCount++;
                }
            } else {
                $upwellCount++;
                if (!empty($s['fuel_substitutable'])) {
                    $substitutableCount++;
                }
            }
        }

        return [
            'upwell_count'        => $upwellCount,
            'metenox_count'       => $metenoxCount,
            'pos_count'           => $posCount,
            'pos_by_race'         => $posByRace,
            'total_count'         => $upwellCount + $metenoxCount + $posCount,
            'substitutable_count' => $substitutableCount,
        ];
    }

    /**
     * Build a daily trend payload for the trend chart.
     *
     * Returns an array of [date => YYYY-MM-DD, total_isk => float] for
     * every day in the window, including days with zero consumption (so
     * the chart shows visible gaps when a structure was offline).
     *
     * Uses the same consumption tables as the main aggregate, just
     * grouped by date instead of by structure.
     */
    private function buildDailyTrend(Carbon $startDate, ?array $corpScope, array $prices): array
    {
        // Initialize every date in the window with zero so missing-data
        // days render as a flat zero rather than a gap on the chart.
        $byDate = [];
        $cursor = $startDate->copy();
        $today  = Carbon::now()->startOfDay();
        while ($cursor->lte($today)) {
            $byDate[$cursor->toDateString()] = 0.0;
            $cursor->addDay();
        }

        // Upwell fuel blocks: SUM(actual_daily_consumption) per (date, fuel_block_type)
        if (Schema::hasTable('structure_fuel_consumption') && Schema::hasTable('corporation_structures')) {
            $blockTypeByStructure = $this->resolveCurrentFuelBlockType(
                DB::table('structure_fuel_consumption')
                    ->where('date', '>=', $startDate->toDateString())
                    ->pluck('structure_id')
                    ->unique()
                    ->values()
                    ->all()
            );

            $q = DB::table('structure_fuel_consumption as sfc')
                ->join('corporation_structures as cs', 'sfc.structure_id', '=', 'cs.structure_id')
                ->where('sfc.date', '>=', $startDate->toDateString())
                ->select('sfc.structure_id', 'sfc.date', 'sfc.actual_daily_consumption');
            if ($corpScope !== null) {
                $q->whereIn('cs.corporation_id', $corpScope);
            }
            foreach ($q->get() as $row) {
                $blockTypeId = $blockTypeByStructure[$row->structure_id] ?? null;
                if ($blockTypeId === null) continue;
                $price = $prices[$blockTypeId] ?? null;
                if ($price === null) continue;
                $isk = (float) $row->actual_daily_consumption * $price;
                $date = $row->date;
                if (isset($byDate[$date])) {
                    $byDate[$date] += $isk;
                }
            }
        }

        // POS fuel + strontium + charters: similar but with three columns
        // per row. Need the per-POS racial fuel-block + charter type maps,
        // both pre-resolved here.
        if (Schema::hasTable('starbase_fuel_consumption') && Schema::hasTable('corporation_starbases')) {
            $starbaseRows = DB::table('starbase_fuel_consumption as sfc')
                ->join('corporation_starbases as csb', 'sfc.starbase_id', '=', 'csb.starbase_id')
                ->where('sfc.date', '>=', $startDate->toDateString())
                ->select(
                    'sfc.starbase_id',
                    'sfc.date',
                    'sfc.fuel_daily_consumption',
                    'sfc.strontium_consumption',
                    'sfc.charter_consumption',
                    'csb.type_id as tower_type_id',
                    'sfc.corporation_id'
                );
            if ($corpScope !== null) {
                $starbaseRows->whereIn('sfc.corporation_id', $corpScope);
            }
            $starbaseRowsResolved = $starbaseRows->get();
            $starbaseIds = $starbaseRowsResolved->pluck('starbase_id')->unique()->values()->all();
            $charterByStarbase = $this->resolveLatestCharterType($starbaseIds);

            foreach ($starbaseRowsResolved as $row) {
                $blockType = $this->racialFuelBlockForTower((int) $row->tower_type_id);
                $date = $row->date;
                if (!isset($byDate[$date])) continue;

                if ($blockType !== null && $row->fuel_daily_consumption > 0) {
                    $price = $prices[$blockType] ?? null;
                    if ($price !== null) {
                        $byDate[$date] += (float) $row->fuel_daily_consumption * $price;
                    }
                }
                if ($row->strontium_consumption > 0) {
                    $price = $prices[TypeIdRegistry::STRONTIUM] ?? null;
                    if ($price !== null) {
                        $byDate[$date] += (float) $row->strontium_consumption * $price;
                    }
                }
                if ($row->charter_consumption > 0) {
                    $charterType = $charterByStarbase[$row->starbase_id] ?? null;
                    if ($charterType !== null) {
                        $price = $prices[$charterType] ?? null;
                        if ($price !== null) {
                            $byDate[$date] += (float) $row->charter_consumption * $price;
                        }
                    }
                }
            }
        }

        // Metenox gas: spread evenly across the window (we don't track
        // per-day Metenox state). Same caveat noted on the per-structure
        // collection method — refines when per-day services-offline lands.
        $gasPrice = $prices[TypeIdRegistry::MAGMATIC_GAS] ?? null;
        if ($gasPrice !== null) {
            $metenoxQ = DB::table('corporation_structures as cs')
                ->where('cs.type_id', 81826)
                ->whereNotIn('cs.state', ['low_power', 'unanchoring', 'unanchored', 'anchoring']);
            if ($corpScope !== null) {
                $metenoxQ->whereIn('cs.corporation_id', $corpScope);
            }
            $metenoxCount  = $metenoxQ->count();
            $gasPerDayPerMetenox = 200 * 24; // 4800
            $dailyGasIsk = $metenoxCount * $gasPerDayPerMetenox * $gasPrice;
            foreach ($byDate as $date => $isk) {
                $byDate[$date] += $dailyGasIsk;
            }
        }

        // Reshape to a flat array sorted by date, with rounded floats so
        // the JSON payload sent to the chart isn't unnecessarily huge.
        $out = [];
        foreach ($byDate as $date => $isk) {
            $out[] = [
                'date'      => $date,
                'total_isk' => round($isk, 2),
            ];
        }
        return $out;
    }

    // ===================================================================
    // Data collection
    // ===================================================================

    /**
     * Walk every Upwell structure (excluding Metenox, handled separately)
     * and project its fuel-block need for the period using FuelCalculator's
     * active-services-based hourly rate. Same code path the Logistics
     * Report uses.
     *
     * Each row tracks BOTH what the structure is currently consuming
     * (resolved from corporation_assets fuel-bay contents) AND what the
     * cheapest typeID is right now, so the page can surface the savings
     * opportunity per structure.
     *
     * Skips structures in low_power / unanchoring / anchoring states since
     * those aren't actively consuming.
     */
    private function collectUpwellProjection(?array $corpScope, int $periodDays, ?array $cheapestBlock): array
    {
        if (!Schema::hasTable('corporation_structures')) {
            return [];
        }
        // No prices for any block type — can't project cost. Returning
        // empty here means the caller's cheapest-block summary card will
        // surface the price-cache miss for the operator.
        if ($cheapestBlock === null) {
            return [];
        }

        $query = DB::table('corporation_structures as cs')
            ->whereNotNull('cs.fuel_expires')
            ->where('cs.type_id', '!=', 81826) // Metenox handled separately
            ->whereNotIn('cs.state', ['low_power', 'unanchoring', 'unanchored', 'anchoring'])
            ->leftJoin('universe_structures as us', 'cs.structure_id', '=', 'us.structure_id')
            ->leftJoin('corporation_infos as ci', 'cs.corporation_id', '=', 'ci.corporation_id')
            ->leftJoin('invTypes as it', 'cs.type_id', '=', 'it.typeID')
            ->leftJoin('mapDenormalize as md', 'cs.system_id', '=', 'md.itemID')
            ->select(
                'cs.structure_id',
                'cs.corporation_id',
                'cs.type_id as structure_type_id',
                'cs.system_id',
                'us.name as us_name',
                'ci.name as corp_name',
                'it.typeName as structure_type_name',
                'md.itemName as system_name'
            );

        if ($corpScope !== null) {
            $query->whereIn('cs.corporation_id', $corpScope);
        }

        $structures = $query->get();
        if ($structures->isEmpty()) {
            return [];
        }

        // Pre-resolve current fuel-bay contents per structure in one batch
        // (avoids N+1 queries when computing per-structure rows).
        $structureIds = $structures->pluck('structure_id')->all();
        $currentFuelByStructure = $this->resolveCurrentFuelBlockType($structureIds);

        $rows = [];
        foreach ($structures as $r) {
            // FuelCalculator returns the active-services rate (or zero if
            // no services online). We use 'hourly' and multiply ourselves
            // so periodDays maps cleanly.
            $hourly = (float) FuelCalculator::getFuelRequirement(
                (int) $r->structure_type_id,
                (int) $r->structure_id,
                'hourly'
            );
            if ($hourly <= 0) continue;

            // Default to the cheapest type when the bay is empty
            // (we don't know what the operator will refill with, so we
            // assume best case for the projection).
            $currentTypeId = $currentFuelByStructure[$r->structure_id] ?? $cheapestBlock['type_id'];

            $rows[] = [
                'structure_id'           => (int) $r->structure_id,
                'structure_name'         => $r->us_name ?: ('Structure #' . $r->structure_id),
                'structure_type_id'      => $r->structure_type_id ? (int) $r->structure_type_id : null,
                'structure_type_name'    => $r->structure_type_name,
                'corporation_id'         => (int) $r->corporation_id,
                'corporation_name'       => $r->corp_name,
                'system_id'              => $r->system_id ? (int) $r->system_id : null,
                'system_name'            => $r->system_name,
                'type_id'                => $currentTypeId,                            // ACTUAL current fuel
                'type_name'              => TypeIdRegistry::FUEL_BLOCK_NAMES[$currentTypeId] ?? "Type #{$currentTypeId}",
                'optimal_type_id'        => $cheapestBlock['type_id'],                  // What the page suggests
                'optimal_type_name'      => $cheapestBlock['type_name'],
                'quantity'               => (int) round($hourly * 24 * $periodDays),
                'is_pos'                 => false,
                'fuel_substitutable'     => true,                                       // Upwell can switch
                'has_bay_contents'       => isset($currentFuelByStructure[$r->structure_id]),
            ];
        }
        return $rows;
    }

    /**
     * Walk every active Metenox and emit TWO rows per structure:
     *   1. Fuel-block side: 5 blocks/hour × 24 × periodDays, priced at
     *      cheapest fuel block (Metenox can substitute the same way Upwell can).
     *   2. Magmatic-gas side: 200 gas/hour × 24 × periodDays, fixed type 81143.
     *
     * Skips low-power / unanchoring Metenoxes.
     */
    private function collectMetenoxProjection(?array $corpScope, int $periodDays, ?array $cheapestBlock): array
    {
        if (!Schema::hasTable('corporation_structures')) {
            return [];
        }

        $query = DB::table('corporation_structures as cs')
            ->where('cs.type_id', 81826)
            ->whereNotIn('cs.state', ['low_power', 'unanchoring', 'unanchored', 'anchoring'])
            ->leftJoin('universe_structures as us', 'cs.structure_id', '=', 'us.structure_id')
            ->leftJoin('corporation_infos as ci', 'cs.corporation_id', '=', 'ci.corporation_id')
            ->leftJoin('mapDenormalize as md', 'cs.system_id', '=', 'md.itemID')
            ->select(
                'cs.structure_id',
                'cs.corporation_id',
                'cs.system_id',
                'us.name as us_name',
                'ci.name as corp_name',
                'md.itemName as system_name'
            );

        if ($corpScope !== null) {
            $query->whereIn('cs.corporation_id', $corpScope);
        }

        $structures = $query->get();
        if ($structures->isEmpty()) {
            return [];
        }

        // Same current-fuel resolution as Upwell — Metenox can substitute
        // among the 4 fuel block types.
        $structureIds = $structures->pluck('structure_id')->all();
        $currentFuelByStructure = $this->resolveCurrentFuelBlockType($structureIds);

        $rows  = [];
        $hours = 24 * $periodDays;
        foreach ($structures as $r) {
            $base = [
                'structure_id'        => (int) $r->structure_id,
                'structure_name'      => $r->us_name ?: ('Metenox #' . $r->structure_id),
                'structure_type_id'   => 81826,
                'structure_type_name' => 'Metenox Moon Drill',
                'corporation_id'      => (int) $r->corporation_id,
                'corporation_name'    => $r->corp_name,
                'system_id'           => $r->system_id ? (int) $r->system_id : null,
                'system_name'         => $r->system_name,
                'is_pos'              => false,
            ];

            // Fuel-block side (5 blocks/hour, current type if known else cheapest)
            if ($cheapestBlock !== null) {
                $currentTypeId = $currentFuelByStructure[$r->structure_id] ?? $cheapestBlock['type_id'];
                $rows[] = $base + [
                    'type_id'             => $currentTypeId,
                    'type_name'           => TypeIdRegistry::FUEL_BLOCK_NAMES[$currentTypeId] ?? "Type #{$currentTypeId}",
                    'optimal_type_id'     => $cheapestBlock['type_id'],
                    'optimal_type_name'   => $cheapestBlock['type_name'],
                    'quantity'            => (int) round(5 * $hours),
                    'fuel_substitutable'  => true,
                    'has_bay_contents'    => isset($currentFuelByStructure[$r->structure_id]),
                ];
            }
            // Magmatic-gas side (200 gas/hour, fixed type 81143 — no substitute)
            $rows[] = $base + [
                'type_id'             => TypeIdRegistry::MAGMATIC_GAS,
                'type_name'           => 'Magmatic Gas',
                'quantity'            => (int) round(200 * $hours),
                'fuel_substitutable'  => false,
            ];
        }
        return $rows;
    }

    /**
     * Walk every active POS and project fuel-block + (if high-sec) charter
     * needs for the period using PosFuelCalculator's static-data-based
     * hourly rates.
     *
     * Fuel block is priced at the racial typeID since POSes can't substitute
     * (a Caldari tower must consume Hydrogen). Charters are priced at the
     * actual charter typeID currently in the POS's fuel bay.
     *
     * Strontium is intentionally NOT included in the projection because
     * it's only consumed during reinforcement — irregular and unpredictable.
     * Operators stockpile strontium as a defensive reserve, not a recurring
     * cost. Showing zero strontium ISK in the projection reflects this.
     */
    private function collectPosProjection(?array $corpScope, int $periodDays): array
    {
        if (!Schema::hasTable('corporation_starbases')) {
            return [];
        }

        // No state filter on corporation_starbases. The reasoning:
        //
        // 1. SeAT stores the state as a STRING ('online', 'reinforced',
        //    'offline', 'onlining', 'unanchored', 'unanchoring' — see
        //    TrackPosesFuel::convertStateToInteger). My previous version
        //    used integer values which never matched.
        //
        // 2. The string version still wasn't surfacing POSes for some
        //    operators — possibly because of case differences, leading/
        //    trailing whitespace, or states outside the documented set.
        //
        // 3. The existing TrackPosesFuel::handle() — which is the
        //    production code path for POS fuel tracking — does NOT filter
        //    by state at all. It just joins to invTypes where groupID=365
        //    (Control Tower group) and lets all POSes through.
        //
        // Match that convention. Filter only by the structure type being
        // a Control Tower; let all POSes in corporation_starbases come
        // through regardless of state. An operationally-offline POS will
        // still produce a projection (what it would cost to bring back
        // online for the period), which is the right answer for
        // 'plan my fuel budget for the next 90 days' use cases.
        $query = DB::table('corporation_starbases as csb')
            ->join('invTypes as it', 'csb.type_id', '=', 'it.typeID')
            ->leftJoin('corporation_infos as ci', 'csb.corporation_id', '=', 'ci.corporation_id')
            ->leftJoin('mapDenormalize as md', 'csb.system_id', '=', 'md.itemID')
            ->where('it.groupID', 365) // Control Tower group, matches TrackPosesFuel
            ->select(
                'csb.starbase_id',
                'csb.corporation_id',
                'csb.type_id',
                'csb.system_id',
                'it.typeName as tower_type_name',
                'ci.name as corp_name',
                'md.itemName as system_name',
                'md.security as system_security'
            );

        if ($corpScope !== null) {
            $query->whereIn('csb.corporation_id', $corpScope);
        }

        $towers = $query->get();
        if ($towers->isEmpty()) {
            return [];
        }

        // Resolve charter types per POS (latest in fuel bay) so we can
        // price each at the actual faction charter the POS uses.
        $starbaseIds       = $towers->pluck('starbase_id')->all();
        $charterByStarbase = $this->resolveLatestCharterType($starbaseIds);

        $rows  = [];
        $hours = 24 * $periodDays;
        foreach ($towers as $r) {
            $towerTypeId = (int) $r->type_id;
            $blockType   = $this->racialFuelBlockForTower($towerTypeId);
            $reqs        = PosFuelCalculator::getStaticFuelRequirements($towerTypeId);
            // PosFuelCalculator::getStaticFuelRequirements returns the rate
            // under 'fuel_per_hour' (also 'actual_fuel_rate'), NOT 'hourly'.
            // Previous version read 'hourly' which is always undefined →
            // defaulted to 0 → if-guard below failed → POS rows silently
            // dropped from the projection. Confirmed via tinker against
            // Matt's tower 27609 which returned fuel_per_hour=12.8.
            $hourlyFuel  = (float) ($reqs['fuel_per_hour'] ?? $reqs['actual_fuel_rate'] ?? 0);

            $base = [
                'structure_id'        => (int) $r->starbase_id,
                'structure_name'      => $this->posDisplayName($r),
                'structure_type_id'   => $towerTypeId ?: null,
                'structure_type_name' => $r->tower_type_name,
                'corporation_id'      => (int) $r->corporation_id,
                'corporation_name'    => $r->corp_name,
                'system_id'           => $r->system_id ? (int) $r->system_id : null,
                'system_name'         => $r->system_name,
                'is_pos'              => true,
            ];

            // Fuel-block side (racial, can't substitute)
            if ($blockType !== null && $hourlyFuel > 0) {
                $rows[] = $base + [
                    'type_id'             => $blockType,
                    'type_name'           => TypeIdRegistry::FUEL_BLOCK_NAMES[$blockType] ?? "Type #{$blockType}",
                    'quantity'            => (int) round($hourlyFuel * $hours),
                    'fuel_substitutable'  => false, // POS racial — can't switch
                ];
            }

            // Charter side (high-sec only, 1 charter / hour)
            $isHighSec = $r->system_security !== null
                && (float) $r->system_security >= PosFuelCalculator::HIGH_SEC_THRESHOLD;
            if ($isHighSec) {
                $charterType = $charterByStarbase[$r->starbase_id] ?? null;
                if ($charterType !== null) {
                    $rows[] = $base + [
                        'type_id'             => $charterType,
                        'type_name'           => TypeIdRegistry::CHARTER_NAMES[$charterType] ?? "Charter #{$charterType}",
                        'quantity'            => (int) round(1 * $hours),
                        'fuel_substitutable'  => false,
                    ];
                }
            }

            // Strontium intentionally omitted (reinforcement-only; not a
            // recurring cost).
        }
        return $rows;
    }

    /**
     * Pick the cheapest of the four fuel-block typeIDs from the price map.
     * Thin wrapper around TypeIdRegistry::cheapestFuelBlock for backwards
     * compat with existing callsites in this service.
     */
    private function pickCheapestFuelBlock(array $prices): ?array
    {
        return TypeIdRegistry::cheapestFuelBlock($prices);
    }

    /**
     * Per-structure active days (distinct dates with a consumption row)
     * across the look-back window. Used by services-offline detection
     * to surface "tracked X of Y days" without needing to re-walk the
     * consumption tables a second time. Keyed by structure_id (Upwell)
     * and starbase_id (POS) — same numeric ID space, no collisions.
     */
    /**
     * Detect actual offline windows per structure by walking
     * structure_fuel_history (and starbase_fuel_history for POS) rows
     * within the look-back window and finding gaps where:
     *
     *   prev_row.fuel_expires < next_row.created_at
     *
     * For each such pair, the offline duration is:
     *
     *   offline_seconds = next_row.created_at - prev_row.fuel_expires
     *
     * Only count gaps longer than 1 hour to avoid false positives from
     * tracker scheduling jitter — a snapshot recorded 5 minutes after
     * the previous fuel_expires probably means "just refueled, no real
     * outage" rather than "5-minute outage."
     *
     * Returns [structure_id => total_offline_hours] for the period.
     * Structure IDs not in the result had zero gaps (or no history at all).
     *
     * Strictly more accurate than the consumption-active-days approach
     * because it uses authoritative fuel-state snapshots and only counts
     * gaps INSIDE observed history (not gaps before the tracker started).
     */
    private function detectOfflineFromFuelHistory(Carbon $startDate, ?array $corpScope): array
    {
        $offlineHoursByStructure = [];
        $minOfflineSeconds = 3600; // ignore gaps shorter than 1 hour

        // ---- Upwell side (structure_fuel_history) ----
        if (Schema::hasTable('structure_fuel_history') && Schema::hasTable('corporation_structures')) {
            $q = DB::table('structure_fuel_history as sfh')
                ->join('corporation_structures as cs', 'sfh.structure_id', '=', 'cs.structure_id')
                ->where('sfh.created_at', '>=', $startDate)
                ->whereNotNull('sfh.fuel_expires')
                ->select('sfh.structure_id', 'sfh.created_at', 'sfh.fuel_expires')
                ->orderBy('sfh.structure_id')
                ->orderBy('sfh.created_at');
            if ($corpScope !== null) {
                $q->whereIn('cs.corporation_id', $corpScope);
            }
            $rows = $q->get();

            // Walk consecutive rows per structure, accumulate gaps
            $bySid = $rows->groupBy('structure_id');
            foreach ($bySid as $sid => $structureRows) {
                $offlineSeconds = 0;
                $prev = null;
                foreach ($structureRows as $row) {
                    if ($prev !== null) {
                        $prevExpires = strtotime($prev->fuel_expires);
                        $thisCreated = strtotime($row->created_at);
                        // Gap = how long after prev's projected expiry
                        // did this snapshot arrive? Negative means the
                        // structure was still fueled at this snapshot.
                        $gap = $thisCreated - $prevExpires;
                        if ($gap > $minOfflineSeconds) {
                            $offlineSeconds += $gap;
                        }
                    }
                    $prev = $row;
                }
                if ($offlineSeconds > 0) {
                    $offlineHoursByStructure[(int) $sid] = $offlineSeconds / 3600.0;
                }
            }
        }

        // ---- POS side (starbase_fuel_history) ----
        // Same pattern. starbase_fuel_history.estimated_fuel_expiry plays
        // the role of fuel_expires (per migration 000007 column name).
        if (Schema::hasTable('starbase_fuel_history')) {
            $q = DB::table('starbase_fuel_history')
                ->where('created_at', '>=', $startDate)
                ->whereNotNull('estimated_fuel_expiry')
                ->select('starbase_id', 'corporation_id', 'created_at', 'estimated_fuel_expiry')
                ->orderBy('starbase_id')
                ->orderBy('created_at');
            if ($corpScope !== null) {
                $q->whereIn('corporation_id', $corpScope);
            }
            $rows = $q->get();

            $bySid = $rows->groupBy('starbase_id');
            foreach ($bySid as $sid => $starbaseRows) {
                $offlineSeconds = 0;
                $prev = null;
                foreach ($starbaseRows as $row) {
                    if ($prev !== null) {
                        $prevExpires = strtotime($prev->estimated_fuel_expiry);
                        $thisCreated = strtotime($row->created_at);
                        $gap = $thisCreated - $prevExpires;
                        if ($gap > $minOfflineSeconds) {
                            $offlineSeconds += $gap;
                        }
                    }
                    $prev = $row;
                }
                if ($offlineSeconds > 0) {
                    $offlineHoursByStructure[(int) $sid] = $offlineSeconds / 3600.0;
                }
            }
        }

        return $offlineHoursByStructure;
    }

    private function detectActiveDaysFromConsumption(Carbon $startDate, ?array $corpScope): array
    {
        $out = [];

        if (Schema::hasTable('structure_fuel_consumption') && Schema::hasTable('corporation_structures')) {
            $q = DB::table('structure_fuel_consumption as sfc')
                ->join('corporation_structures as cs', 'sfc.structure_id', '=', 'cs.structure_id')
                ->where('sfc.date', '>=', $startDate->toDateString())
                ->select('sfc.structure_id', DB::raw('COUNT(DISTINCT sfc.date) as active'))
                ->groupBy('sfc.structure_id');
            if ($corpScope !== null) {
                $q->whereIn('cs.corporation_id', $corpScope);
            }
            foreach ($q->get() as $r) {
                $out[(int) $r->structure_id] = (int) $r->active;
            }
        }

        if (Schema::hasTable('starbase_fuel_consumption')) {
            $q = DB::table('starbase_fuel_consumption')
                ->where('date', '>=', $startDate->toDateString())
                ->select('starbase_id', DB::raw('COUNT(DISTINCT date) as active'))
                ->groupBy('starbase_id');
            if ($corpScope !== null) {
                $q->whereIn('corporation_id', $corpScope);
            }
            foreach ($q->get() as $r) {
                $out[(int) $r->starbase_id] = (int) $r->active;
            }
        }

        return $out;
    }

    // ===================================================================
    // LEGACY COLLECTORS (consumption-table based) — kept for reference
    // until the new calculator-based path is verified in production.
    // Currently unreachable from buildEconomics. Safe to delete in a
    // follow-up commit.
    // ===================================================================

    private function collectUpwellBlockConsumption(Carbon $startDate, ?array $corpScope): array
    {
        if (!Schema::hasTable('structure_fuel_consumption') || !Schema::hasTable('corporation_structures')) {
            return [];
        }

        $query = DB::table('structure_fuel_consumption as sfc')
            ->join('corporation_structures as cs', 'sfc.structure_id', '=', 'cs.structure_id')
            ->leftJoin('universe_structures as us', 'cs.structure_id', '=', 'us.structure_id')
            ->leftJoin('corporation_infos as ci', 'cs.corporation_id', '=', 'ci.corporation_id')
            ->leftJoin('invTypes as it', 'cs.type_id', '=', 'it.typeID')
            ->leftJoin('mapDenormalize as md', 'cs.system_id', '=', 'md.itemID')
            ->where('sfc.date', '>=', $startDate->toDateString())
            ->select(
                'sfc.structure_id',
                'sfc.actual_daily_consumption',
                'cs.corporation_id',
                'cs.type_id as structure_type_id',
                'cs.system_id',
                'us.name as us_name',
                'ci.name as corp_name',
                'it.typeName as structure_type_name',
                'md.itemName as system_name'
            );

        if ($corpScope !== null) {
            $query->whereIn('cs.corporation_id', $corpScope);
        }

        $perStructure = $query->get()->groupBy('structure_id');

        // Preload the latest fuel-bay asset per structure to learn which
        // fuel block type each structure currently consumes. ESI data is
        // cached so this is cheap, but batch-resolve to one query.
        $structureIds = $perStructure->keys()->all();
        $blockTypeByStructure = $this->resolveCurrentFuelBlockType($structureIds);

        $rows = [];
        foreach ($perStructure as $structureId => $rowsForStructure) {
            $first = $rowsForStructure->first();
            $totalQty = (int) round($rowsForStructure->sum('actual_daily_consumption'));
            if ($totalQty === 0) continue;

            $blockTypeId = $blockTypeByStructure[$structureId] ?? null;
            if ($blockTypeId === null) {
                // Skip — without knowing the fuel type we can't price the row.
                // Could fall back to "average fuel block price" but that hides
                // mis-stocked structures from the report which is the opposite
                // of what we want. Better to omit and surface in a caveat.
                continue;
            }

            // Active days = unique dates with a consumption row in the
            // window. Missing days = structure was either offline (low-power
            // due to fuel exhaustion) or the tracker had a gap. Both cases
            // are surfaced as "services_offline_days" on the per-structure
            // breakdown — the operator interprets the gap.
            $activeDays = $rowsForStructure->pluck('date')->unique()->count();

            $rows[] = [
                'structure_id'        => (int) $structureId,
                'structure_name'      => $first->us_name ?: "Structure #{$structureId}",
                'structure_type_id'   => $first->structure_type_id ? (int) $first->structure_type_id : null,
                'structure_type_name' => $first->structure_type_name,
                'corporation_id'      => (int) $first->corporation_id,
                'corporation_name'    => $first->corp_name,
                'system_id'           => $first->system_id ? (int) $first->system_id : null,
                'system_name'         => $first->system_name,
                'type_id'             => $blockTypeId, // FUEL block type, not structure type
                'type_name'           => TypeIdRegistry::FUEL_BLOCK_NAMES[$blockTypeId] ?? "Type #{$blockTypeId}",
                'quantity'            => $totalQty,
                'active_days'         => $activeDays,
                'is_pos'              => false,
            ];
        }
        return $rows;
    }

    /**
     * For each Metenox structure currently online, count days within the
     * period that it was active and multiply by the fixed 200/hour gas
     * consumption rate. We don't currently track per-day "was the Metenox
     * online" — for Phase A we use the simple model "active for the whole
     * window if the structure currently exists and isn't low-power".
     *
     * If the structure was low-power for part of the window, this will
     * over-estimate. Worth refining in a later pass once services-offline
     * detection lands.
     */
    private function collectMetenoxGasConsumption(Carbon $startDate, ?array $corpScope): array
    {
        if (!Schema::hasTable('corporation_structures')) {
            return [];
        }

        $query = DB::table('corporation_structures as cs')
            ->leftJoin('universe_structures as us', 'cs.structure_id', '=', 'us.structure_id')
            ->leftJoin('corporation_infos as ci', 'cs.corporation_id', '=', 'ci.corporation_id')
            ->leftJoin('invTypes as it', 'cs.type_id', '=', 'it.typeID')
            ->leftJoin('mapDenormalize as md', 'cs.system_id', '=', 'md.itemID')
            ->where('cs.type_id', 81826) // Metenox Moon Drill
            ->whereNotIn('cs.state', ['low_power', 'unanchoring', 'unanchored', 'anchoring'])
            ->select(
                'cs.structure_id',
                'cs.corporation_id',
                'cs.type_id as structure_type_id',
                'cs.system_id',
                'us.name as us_name',
                'ci.name as corp_name',
                'it.typeName as structure_type_name',
                'md.itemName as system_name'
            );

        if ($corpScope !== null) {
            $query->whereIn('cs.corporation_id', $corpScope);
        }

        $rows = [];
        $periodDays = (int) round(Carbon::now()->diffInDays($startDate, true));
        $gasPerDay  = 200 * 24; // 4800 gas / day per active Metenox

        foreach ($query->get() as $r) {
            // Phase A approximation: Metenox active for 100% of the window
            // because we don't track per-day Metenox state. Refine when
            // per-day services-offline detection lands across the board.
            $rows[] = [
                'structure_id'        => (int) $r->structure_id,
                'structure_name'      => $r->us_name ?: "Metenox #{$r->structure_id}",
                'structure_type_id'   => 81826,
                'structure_type_name' => $r->structure_type_name ?: 'Metenox Moon Drill',
                'corporation_id'      => (int) $r->corporation_id,
                'corporation_name'    => $r->corp_name,
                'system_id'           => $r->system_id ? (int) $r->system_id : null,
                'system_name'         => $r->system_name,
                'type_id'             => TypeIdRegistry::MAGMATIC_GAS,
                'type_name'           => 'Magmatic Gas',
                'quantity'            => $gasPerDay * $periodDays,
                'active_days'         => $periodDays,
                'is_pos'              => false,
            ];
        }
        return $rows;
    }

    /**
     * Walks starbase_fuel_consumption for fuel-blocks, strontium, and
     * charters per POS over the period. POS table includes corporation_id
     * directly, so corp scoping is a single WHERE. Block type is derived
     * from the racial control-tower → racial fuel-block mapping.
     */
    private function collectPosConsumption(Carbon $startDate, ?array $corpScope): array
    {
        if (!Schema::hasTable('starbase_fuel_consumption') || !Schema::hasTable('corporation_starbases')) {
            return [];
        }

        $query = DB::table('starbase_fuel_consumption as sfc')
            ->join('corporation_starbases as csb', 'sfc.starbase_id', '=', 'csb.starbase_id')
            ->leftJoin('corporation_infos as ci', 'csb.corporation_id', '=', 'ci.corporation_id')
            ->leftJoin('invTypes as it', 'csb.type_id', '=', 'it.typeID')
            ->leftJoin('mapDenormalize as md', 'csb.system_id', '=', 'md.itemID')
            ->where('sfc.date', '>=', $startDate->toDateString())
            ->select(
                'sfc.starbase_id',
                'sfc.corporation_id',
                'sfc.fuel_daily_consumption',
                'sfc.strontium_consumption',
                'sfc.charter_consumption',
                'csb.type_id as tower_type_id',
                'csb.system_id',
                'ci.name as corp_name',
                'it.typeName as tower_type_name',
                'md.itemName as system_name'
            );

        if ($corpScope !== null) {
            $query->whereIn('sfc.corporation_id', $corpScope);
        }

        $perStarbase = $query->get()->groupBy('starbase_id');

        // Preload the latest charter type per POS (so we can price using
        // the actual charter the POS uses, not a faction-average).
        $starbaseIds = $perStarbase->keys()->all();
        $charterByStarbase = $this->resolveLatestCharterType($starbaseIds);

        $rows = [];
        foreach ($perStarbase as $starbaseId => $rowsForStarbase) {
            $first = $rowsForStarbase->first();

            $blockType = $this->racialFuelBlockForTower((int) $first->tower_type_id);

            $fuelQty       = (int) round($rowsForStarbase->sum('fuel_daily_consumption'));
            $strontiumQty  = (int) round($rowsForStarbase->sum('strontium_consumption'));
            $charterQty    = (int) round($rowsForStarbase->sum('charter_consumption'));

            // Active-days for POS: same logic as Upwell (count distinct
            // dates with a consumption row). starbase_fuel_consumption stops
            // recording when a POS is offline so missing dates = offline.
            $activeDays = $rowsForStarbase->pluck('date')->unique()->count();

            $base = [
                'structure_id'        => (int) $starbaseId,
                'structure_name'      => $this->posDisplayName($first),
                'structure_type_id'   => $first->tower_type_id ? (int) $first->tower_type_id : null,
                'structure_type_name' => $first->tower_type_name,
                'corporation_id'      => (int) $first->corporation_id,
                'corporation_name'    => $first->corp_name,
                'system_id'           => $first->system_id ? (int) $first->system_id : null,
                'system_name'         => $first->system_name,
                'active_days'         => $activeDays,
                'is_pos'              => true,
            ];

            if ($blockType !== null && $fuelQty > 0) {
                $rows[] = $base + [
                    'type_id'   => $blockType,
                    'type_name' => TypeIdRegistry::FUEL_BLOCK_NAMES[$blockType] ?? "Type #{$blockType}",
                    'quantity'  => $fuelQty,
                ];
            }

            if ($strontiumQty > 0) {
                $rows[] = $base + [
                    'type_id'   => TypeIdRegistry::STRONTIUM,
                    'type_name' => 'Strontium Clathrates',
                    'quantity'  => $strontiumQty,
                ];
            }

            if ($charterQty > 0) {
                $charterTypeId = $charterByStarbase[$starbaseId] ?? null;
                if ($charterTypeId !== null) {
                    $rows[] = $base + [
                        'type_id'   => $charterTypeId,
                        'type_name' => TypeIdRegistry::CHARTER_NAMES[$charterTypeId] ?? "Charter #{$charterTypeId}",
                        'quantity'  => $charterQty,
                    ];
                }
            }
        }
        return $rows;
    }

    // ===================================================================
    // Helpers
    // ===================================================================

    /**
     * Resolve the fuel-block typeID currently in each structure's fuel bay.
     * Returns [structure_id => typeID]. Structures with no fuel-bay asset
     * are omitted from the result.
     */
    private function resolveCurrentFuelBlockType(array $structureIds): array
    {
        if (empty($structureIds) || !Schema::hasTable('corporation_assets')) {
            return [];
        }
        $blockTypeIds = array_keys(TypeIdRegistry::FUEL_BLOCK_NAMES);

        $rows = DB::table('corporation_assets')
            ->whereIn('location_id', $structureIds)
            ->where('location_flag', 'StructureFuel')
            ->whereIn('type_id', $blockTypeIds)
            ->select('location_id', 'type_id', DB::raw('SUM(quantity) as qty'))
            ->groupBy('location_id', 'type_id')
            ->get();

        // Pick the dominant block per structure (highest quantity wins
        // when a corp has more than one block type stocked).
        $byStructure = [];
        foreach ($rows as $r) {
            $sid = (int) $r->location_id;
            $tid = (int) $r->type_id;
            $qty = (int) $r->qty;
            if (!isset($byStructure[$sid]) || $qty > $byStructure[$sid]['qty']) {
                $byStructure[$sid] = ['type_id' => $tid, 'qty' => $qty];
            }
        }
        $out = [];
        foreach ($byStructure as $sid => $row) {
            $out[$sid] = $row['type_id'];
        }
        return $out;
    }

    /**
     * Resolve the latest charter typeID per POS from corporation_assets
     * (POS keeps charters in the StructureFuel location_flag too).
     */
    private function resolveLatestCharterType(array $starbaseIds): array
    {
        if (empty($starbaseIds) || !Schema::hasTable('corporation_assets')) {
            return [];
        }
        $charterTypeIds = array_keys(TypeIdRegistry::CHARTER_NAMES);

        $rows = DB::table('corporation_assets')
            ->whereIn('location_id', $starbaseIds)
            ->whereIn('type_id', $charterTypeIds)
            ->select('location_id', 'type_id', DB::raw('SUM(quantity) as qty'))
            ->groupBy('location_id', 'type_id')
            ->get();

        $byStarbase = [];
        foreach ($rows as $r) {
            $sid = (int) $r->location_id;
            $tid = (int) $r->type_id;
            $qty = (int) $r->qty;
            if (!isset($byStarbase[$sid]) || $qty > $byStarbase[$sid]['qty']) {
                $byStarbase[$sid] = ['type_id' => $tid, 'qty' => $qty];
            }
        }
        $out = [];
        foreach ($byStarbase as $sid => $row) {
            $out[$sid] = $row['type_id'];
        }
        return $out;
    }

    /**
     * Map a control-tower typeID to its racial fuel-block typeID.
     * Thin wrapper around TypeIdRegistry::racialFuelForTower for backwards
     * compat with existing callsites in this service. New code should
     * call TypeIdRegistry directly.
     */
    private function racialFuelBlockForTower(int $towerTypeId): ?int
    {
        return TypeIdRegistry::racialFuelForTower($towerTypeId);
    }

    private function posDisplayName($row): string
    {
        $type = $row->tower_type_name ?: 'Control Tower';
        $sys  = $row->system_name ?: ('System #' . ($row->system_id ?? '?'));
        return "{$type} in {$sys}";
    }

    /**
     * Scale a period total to the named timeframes in the banner cards.
     * Yearly is always linear extrapolation from the period — note that
     * a 90-day window can produce a noisier yearly estimate than a 365-day
     * window; the page surfaces which window is selected.
     */
    private function scaleTotals(float $periodIsk, int $periodDays): array
    {
        return [
            'period_isk'    => $periodIsk,
            'weekly_isk'    => $this->scale($periodIsk, $periodDays, 7),
            'monthly_isk'   => $this->scale($periodIsk, $periodDays, 30),
            'quarterly_isk' => $this->scale($periodIsk, $periodDays, 90),
            'yearly_isk'    => $this->scale($periodIsk, $periodDays, 365),
        ];
    }

    private function scale(float $periodIsk, int $periodDays, int $targetDays): float
    {
        if ($periodDays === 0) return 0.0;
        return $periodIsk * ($targetDays / $periodDays);
    }

    /**
     * Pricing meta for the page header so the operator sees which market
     * + price-type is being used (and whether an admin overrode the
     * plugin default in MC).
     */
    private function resolvePricingMeta(): array
    {
        $meta = [
            'available'        => ManagerCoreIntegration::isPricingAvailable(),
            'market'           => null,
            'price_type'       => null,
            'admin_overridden' => false,
        ];
        if (!$meta['available']) {
            return $meta;
        }
        try {
            if (class_exists('\ManagerCore\Models\PricingPreference')) {
                $pref = \ManagerCore\Models\PricingPreference::forPlugin(self::PLUGIN_KEY);
                if ($pref) {
                    $meta['market']           = $pref->market;
                    $meta['price_type']       = $pref->price_type;
                    $meta['admin_overridden'] = (bool) $pref->admin_overridden;
                }
            }
        } catch (\Throwable $e) {
            // table may not exist in early MC versions
        }
        return $meta;
    }

    private function emptyPayload(int $periodDays): array
    {
        return [
            'period_days'    => $periodDays,
            'period_start'   => Carbon::now()->subDays($periodDays)->toDateString(),
            'period_end'     => Carbon::now()->toDateString(),
            'effective_days' => 0,
            'totals'         => [
                'period_isk'    => 0.0,
                'weekly_isk'    => 0.0,
                'monthly_isk'   => 0.0,
                'quarterly_isk' => 0.0,
                'yearly_isk'    => 0.0,
            ],
            'by_system'    => [],
            'by_structure' => [],
            'by_type'      => [],
            'trend'        => [],
            'pricing_meta' => $this->resolvePricingMeta(),
            'cheapest_fuel_block' => null,
            'optimization' => [
                'savings_period'   => 0.0,
                'savings_weekly'   => 0.0,
                'savings_monthly'  => 0.0,
                'savings_yearly'   => 0.0,
                'structures_count' => 0,
            ],
            'breakdown' => [
                'upwell_count'        => 0,
                'metenox_count'       => 0,
                'pos_count'           => 0,
                'pos_by_race'         => ['caldari' => 0, 'minmatar' => 0, 'amarr' => 0, 'gallente' => 0, 'other' => 0],
                'total_count'         => 0,
                'substitutable_count' => 0,
            ],
        ];
    }

    private static function sortByPeriodIsk(array $rows): array
    {
        usort($rows, fn($a, $b) => $a['period_isk'] <=> $b['period_isk']);
        return $rows;
    }
}
