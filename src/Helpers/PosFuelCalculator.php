<?php

namespace StructureManager\Helpers;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * POS (Player Owned Starbase) Fuel Calculator
 * 
 * Handles fuel consumption calculations for legacy Control Towers
 * Includes support for faction tower fuel efficiency bonuses
 */
class PosFuelCalculator
{
    /**
     * Fuel block type IDs.
     * @deprecated use TypeIdRegistry::FUEL_BLOCK_NAMES
     */
    const FUEL_BLOCKS = TypeIdRegistry::FUEL_BLOCK_NAMES;

    /**
     * Strontium Clathrates type ID.
     * @deprecated use TypeIdRegistry::STRONTIUM
     */
    const STRONTIUM = TypeIdRegistry::STRONTIUM;

    /**
     * Starbase Charter type IDs.
     * Required in high-sec space (truesec >= 0.45, displays as >= 0.5) at 1 per hour.
     * Charter type depends on system sovereignty/faction.
     * @deprecated use TypeIdRegistry::CHARTER_NAMES
     */
    const CHARTER_TYPES = TypeIdRegistry::CHARTER_NAMES;
    
    /**
     * High-sec security threshold for charter requirement.
     *
     * SeAT's mapDenormalize.security column stores the raw truesec (unrounded
     * float, e.g. 0.459 for Tasabeshi). EVE rounds truesec >= 0.45 up to a
     * displayed 0.5, and those systems ARE high-sec — CONCORD responds and
     * starbase charters are required. So the comparator must be 0.45, not 0.5:
     * a 0.5 threshold would wrongly flag ~40-50 real high-sec systems (truesec
     * 0.45-0.499) as low-sec, suppressing their charter alerts.
     */
    const HIGH_SEC_THRESHOLD = 0.45;
    
    /**
     * Strontium status thresholds — DEFAULTS only. These constants are
     * fallback values when no `pos_strontium_*_hours` setting is configured.
     * NEW CODE SHOULD CALL FuelThresholds::posStrontium*() instead — those
     * methods read the per-install setting with these as fallback.
     *
     * The constants are kept for older internal callers that pass them as
     * arguments to recommendation calculations (e.g. `* STRONTIUM_GOOD_HOURS`
     * for a "minimum recommended stockpile" math expression). For status
     * determination, always use the FuelThresholds methods.
     *
     * STRONTIUM_GOOD_HOURS specifically is a logistics TARGET (recommended
     * minimum stockpile to maintain), not an alert threshold. Status uses
     * 3-tier (critical / warning / good); "fair" is not surfaced.
     */
    const STRONTIUM_CRITICAL_HOURS = \StructureManager\Helpers\FuelThresholds::POS_STRONTIUM_CRITICAL_HOURS_DEFAULT;
    const STRONTIUM_WARNING_HOURS  = \StructureManager\Helpers\FuelThresholds::POS_STRONTIUM_WARNING_HOURS_DEFAULT;
    const STRONTIUM_GOOD_HOURS     = \StructureManager\Helpers\FuelThresholds::POS_STRONTIUM_GOOD_HOURS_DEFAULT;
    
    /**
     * CRITICAL: Faction tower fuel modifiers
     * CORRECTED VALUES based on EVE SDE invControlTowerResources table
     *
     * IMPORTANT: Community docs claiming 25%/50% bonuses are WRONG!
     * Actual bonuses verified from game database:
     * - Faction towers: 10% fuel reduction (0.9 modifier)
     * - Officer towers: 20% fuel reduction (0.8 modifier)
     *
     * Source: invControlTowerResources fuel quantities
     * - T1 Small: 10/hour, Faction: 9/hour, Officer: 8/hour
     * - T1 Medium: 20/hour, Faction: 18/hour, Officer: 16/hour
     * - T1 Large: 40/hour, Faction: 36/hour, Officer: 32/hour
     *
     * @deprecated The same data lives in TypeIdRegistry::POS_TOWERS now,
     * with size + faction-tier metadata alongside the modifier. New code
     * should call TypeIdRegistry::posTowerModifier($typeId) which returns
     * the same modifier and falls back to 1.0 for unknown towers (vs the
     * `?? 1.0` pattern callers currently scatter at every callsite).
     */
    const FACTION_FUEL_MODIFIERS = [
        // T1 Towers - NO BONUS (1.0 = 100% fuel consumption)
        12235 => 1.0,  // Amarr Control Tower (Large)
        16213 => 1.0,  // Caldari Control Tower (Large)
        12236 => 1.0,  // Gallente Control Tower (Large)
        16214 => 1.0,  // Minmatar Control Tower (Large)
        20059 => 1.0,  // Amarr Control Tower Medium
        20060 => 1.0,  // Amarr Control Tower Small
        20061 => 1.0,  // Caldari Control Tower Medium
        20062 => 1.0,  // Caldari Control Tower Small
        20063 => 1.0,  // Gallente Control Tower Medium
        20064 => 1.0,  // Gallente Control Tower Small
        20065 => 1.0,  // Minmatar Control Tower Medium
        20066 => 1.0,  // Minmatar Control Tower Small
        
        // FACTION TOWERS - 10% REDUCTION (0.9 modifier)
        // Small Faction Towers
        27610 => 0.9,  // Angel Control Tower Small
        27592 => 0.9,  // Blood Control Tower Small
        27598 => 0.9,  // Guristas Control Tower Small
        27784 => 0.9,  // Sansha Control Tower Small
        27604 => 0.9,  // Serpentis Control Tower Small
        
        // Medium Faction Towers
        27607 => 0.9,  // Angel Control Tower Medium
        27589 => 0.9,  // Blood Control Tower Medium
        27595 => 0.9,  // Guristas Control Tower Medium
        27782 => 0.9,  // Sansha Control Tower Medium
        27601 => 0.9,  // Serpentis Control Tower Medium
        
        // Large Faction Towers
        27539 => 0.9,  // Angel Control Tower (Large)
        27530 => 0.9,  // Blood Control Tower (Large)
        27533 => 0.9,  // Guristas Control Tower (Large)
        27780 => 0.9,  // Sansha Control Tower (Large)
        27536 => 0.9,  // Serpentis Control Tower (Large)
        
        // OFFICER TOWERS - 20% REDUCTION (0.8 modifier)
        // Small Officer Towers
        27594 => 0.8,  // Dark Blood Control Tower Small
        27612 => 0.8,  // Domination Control Tower Small
        27600 => 0.8,  // Dread Guristas Control Tower Small
        27606 => 0.8,  // Shadow Control Tower Small
        27790 => 0.8,  // True Sansha Control Tower Small
        
        // Medium Officer Towers
        27591 => 0.8,  // Dark Blood Control Tower Medium
        27609 => 0.8,  // Domination Control Tower Medium
        27597 => 0.8,  // Dread Guristas Control Tower Medium
        27603 => 0.8,  // Shadow Control Tower Medium
        27788 => 0.8,  // True Sansha Control Tower Medium
        
        // Large Officer Towers
        27532 => 0.8,  // Dark Blood Control Tower (Large)
        27540 => 0.8,  // Domination Control Tower (Large)
        27535 => 0.8,  // Dread Guristas Control Tower (Large)
        27538 => 0.8,  // Shadow Control Tower (Large)
        27786 => 0.8,  // True Sansha Control Tower (Large)
    ];
    
    /**
     * Get fuel consumption rates for a tower type
     * 
     * @param int $towerTypeId
     * @param float|null $systemSecurity Optional system security level for charter calculation
     * @return array
     */
    public static function getFuelConsumptionRate($towerTypeId, $systemSecurity = null)
    {
        // Effective consumption per cycle, via the registry (SDE first, its
        // hardcoded table as fallback).
        //
        // This value ALREADY has the faction bonus in it. The SDE ladder is
        // 40/36/32 for a large tower across T1/faction/officer, and CCP's own
        // client shows the same figure as "Quantity (per cycle)". Multiplying
        // it by FACTION_FUEL_MODIFIERS again, as this method used to, applied
        // the bonus twice: an officer tower read 25.6 instead of 32, which
        // understated burn and so overstated days remaining, pushing low-fuel
        // alerts late by up to a day and a half.
        $actualFuelRate = TypeIdRegistry::posTowerHourlyRate((int) $towerTypeId) ?? 0;

        // Strontium per reinforced cycle. Flat by hull size, no faction bonus.
        $strontiumRate = TypeIdRegistry::posTowerStrontiumRate((int) $towerTypeId) ?? 0;

        // The modifier is still needed for the display fields below, but it is
        // no longer applied to any rate.
        $fuelModifier = self::FACTION_FUEL_MODIFIERS[$towerTypeId] ?? 1.0;

        // What the same hull would burn without a faction bonus, used by the
        // savings comparison. This is the T1 rate for the size, NOT the tower's
        // own consumption; reading it off the SDE (as this used to) returned
        // the already-bonused figure and made the saving compute as zero.
        $size = TypeIdRegistry::POS_TOWERS[$towerTypeId]['size'] ?? null;
        $baseFuelRate = $size !== null
            ? (TypeIdRegistry::POS_BASE_FUEL_RATES[$size] ?? $actualFuelRate)
            : $actualFuelRate;

        // Check if charters are required (high-sec only)
        $requiresCharters = $systemSecurity !== null && $systemSecurity >= self::HIGH_SEC_THRESHOLD;
        $chartersPerHour = $requiresCharters ? 1 : 0;
        
        // Calculate recommended strontium amounts
        // Critical threshold: 6 hours (absolute minimum for quick response)
        // Warning threshold: 12 hours (allows time to respond)
        // Good threshold: 24 hours (provides full day coverage)
        // Recommended: 48 hours (weekend coverage)
        // Optimal: 36 hours. The ceiling is the strontium bay, not a hauling
        // limit: SDE attribute 1233 gives 25,000 m3 on a medium hull, and at
        // 3 m3 a unit that is 8,333 units, roughly 41 hours at 200 per cycle.
        $minStrontium = $strontiumRate * self::STRONTIUM_GOOD_HOURS;  // 24 hours minimum
        $recommendedStrontium = $strontiumRate * 48;  // 48 hours recommended
        $optimalStrontium = $strontiumRate * 36;      // 36 hours optimal
        
        return [
            'base_fuel_per_hour' => $baseFuelRate ?? 0,
            'fuel_modifier' => $fuelModifier,
            'fuel_per_hour' => $actualFuelRate ?? 0,
            'fuel_per_day' => round($actualFuelRate * 24),
            'fuel_per_month' => round($actualFuelRate * 24 * 30),
            'fuel_reduction_percent' => round((1 - $fuelModifier) * 100, 0),
            'strontium_for_reinforced' => $strontiumRate ?? 0,
            'strontium_min' => $minStrontium ?? 0,
            'strontium_recommended' => $recommendedStrontium ?? 0,
            'strontium_optimal' => $optimalStrontium ?? 0,
            'has_fuel_bonus' => $fuelModifier < 1.0,
            'bonus_type' => self::getBonusType($fuelModifier),
            'requires_charters' => $requiresCharters,
            'charters_per_hour' => $chartersPerHour,
            'charters_per_day' => $chartersPerHour * 24,
            'charters_per_month' => $chartersPerHour * 24 * 30,
            'system_security' => $systemSecurity,
        ];
    }
    
    /**
     * NOTE: getFuelModifierFromDatabase() lived here and always returned
     * null, with a TODO to find the dgmTypeAttributes attribute ID for the
     * faction fuel modifier. That search is moot: invControlTowerResources
     * already stores the bonused per-cycle quantity (40/36/32 for a large
     * hull), so there is no separate modifier to look up and applying one
     * would double-count the bonus. The modifier survives in
     * FACTION_FUEL_MODIFIERS purely for the display fields.
     */
    
    /**
     * Get human-readable bonus type
     * 
     * @param float $modifier
     * @return string
     */
    private static function getBonusType($modifier)
    {
        if ($modifier >= 1.0) {
            return 'None (T1 Tower)';
        } elseif ($modifier == 0.8) {
            return 'Officer Tower (20% reduction)';
        } elseif ($modifier == 0.9) {
            return 'Faction Tower (10% reduction)';
        } else {
            $reduction = round((1 - $modifier) * 100, 0);
            return "Custom ({$reduction}% reduction)";
        }
    }
    
    /**
     * Calculate days remaining based on current fuel
     * 
     * @param int $towerTypeId
     * @param int $currentFuelBlocks
     * @param int $currentStrontium
     * @param int $currentCharters
     * @param float|null $systemSecurity
     * @return array
     */
    public static function calculateDaysRemaining($towerTypeId, $currentFuelBlocks, $currentStrontium = null, $currentCharters = 0, $systemSecurity = null)
    {
        $rates = self::getFuelConsumptionRate($towerTypeId, $systemSecurity);

        // POS fuel mechanics: a POS pulls fuel at the start of each hour cycle.
        // Previously this method added +1 hour to the raw (blocks / rate) estimate
        // on the theory that the current cycle is still running. In practice, the
        // POS could be anywhere in the hour, so +1 was the *maximum* correction —
        // which overstated remaining time in the UNSAFE direction (real empty time
        // arrives up to an hour before the displayed time).
        //
        // Report the conservative estimate (no cushion) so refuel planning is safe:
        // if the UI says 19h remaining, the POS WILL still be up in 19h. The worst
        // case is that it stays online slightly longer than displayed, which is
        // safe. Sub-hour precision is preserved in the decimal.
        // Whole cycles only. A tower pulls a full cycle's fuel or none at
        // all, so a remainder too small to buy the next cycle is stranded and
        // must not be reported as a fraction of an hour. 184 blocks at 16 per
        // cycle is 11 hours with 8 blocks left over, which is exactly what the
        // in-game Processes tab shows.
        $fuelHours = $rates['fuel_per_hour'] > 0
            ? floor($currentFuelBlocks / $rates['fuel_per_hour'])
            : 0;
        $fuelDays = round($fuelHours / 24, 2);

        // If charters are required, calculate charter days with same conservative logic
        $charterDays = null;
        if ($rates['requires_charters'] && $rates['charters_per_hour'] > 0) {
            $charterHours = $currentCharters / $rates['charters_per_hour'];
            $charterDays = round($charterHours / 24, 2);
        }
        
        // The limiting factor is whichever runs out first
        if ($charterDays !== null && $charterDays < $fuelDays) {
            $actualDays = $charterDays;
            $limitingFactor = 'charters';
        } else {
            $actualDays = $fuelDays;
            $limitingFactor = 'fuel';
        }
        
        $strontiumDays = null;
        if ($currentStrontium !== null && $rates['strontium_for_reinforced'] > 0) {
            // Strontium is consumed during reinforced mode only
            // This calculation shows how long reinforced mode could last
            // Whole cycles only, same reason as fuel above.
            $strontiumDays = round(floor($currentStrontium / $rates['strontium_for_reinforced']) / 24, 1);
        }
        
        return [
            'fuel_days' => $fuelDays,
            'charter_days' => $charterDays,
            'strontium_days' => $strontiumDays,
            'actual_days' => $actualDays,
            'limiting_factor' => $limitingFactor,
            'fuel_runs_out' => Carbon::now()->addDays($actualDays),
            'current_fuel_blocks' => $currentFuelBlocks,
            'current_charters' => $currentCharters,
            'current_strontium' => $currentStrontium,
            'fuel_per_hour' => $rates['fuel_per_hour'],
            'fuel_per_day' => $rates['fuel_per_day'],
            'charters_per_hour' => $rates['charters_per_hour'],
            'requires_charters' => $rates['requires_charters'],
        ];
    }
    
    /**
     * Get all POS towers for a corporation with fuel status
     * 
     * @param int $corporationId
     * @return \Illuminate\Support\Collection
     */
    public static function getCorporationPosStatus($corporationId)
    {
        $poses = DB::table('corporation_starbases as cs')
            ->join('invTypes as it', 'cs.type_id', '=', 'it.typeID')
            ->leftJoin('mapDenormalize as md', 'cs.system_id', '=', 'md.itemID')
            ->where('cs.corporation_id', $corporationId)
            ->where('it.groupID', 365) // Control Tower group
            ->select(
                'cs.*',
                'it.typeName as tower_type',
                'md.itemName as system_name',
                'md.security as system_security'
            )
            ->get();
        
        foreach ($poses as $pos) {
            // Get current fuel
            $fuelData = DB::table('corporation_starbase_fuels')
                ->where('starbase_id', $pos->starbase_id)
                ->where('corporation_id', $corporationId)
                ->whereIn('type_id', array_keys(self::FUEL_BLOCKS))
                ->sum('quantity');
            
            $strontiumData = DB::table('corporation_starbase_fuels')
                ->where('starbase_id', $pos->starbase_id)
                ->where('corporation_id', $corporationId)
                ->where('type_id', self::STRONTIUM)
                ->value('quantity');
            
            // Get current charters (if in high-sec)
            $charterData = DB::table('corporation_starbase_fuels')
                ->where('starbase_id', $pos->starbase_id)
                ->where('corporation_id', $corporationId)
                ->whereIn('type_id', array_keys(self::CHARTER_TYPES))
                ->sum('quantity');
            
            // Get fuel consumption rates (pass system security for charter calculation)
            $rates = self::getFuelConsumptionRate($pos->type_id, $pos->system_security);
            
            // Get detailed strontium status
            $strontiumStatus = self::getStrontiumStatus($pos->type_id, $strontiumData ?? 0);
            
            // Calculate days remaining
            $daysRemaining = self::calculateDaysRemaining(
                $pos->type_id,
                $fuelData ?? 0,
                $strontiumData,
                $charterData,
                $pos->system_security
            );
            
            // Add calculated data to POS object
            $pos->current_fuel_blocks = $fuelData ?? 0;
            $pos->current_strontium = $strontiumData ?? 0;
            $pos->current_charters = $charterData ?? 0;
            $pos->fuel_per_hour = $rates['fuel_per_hour'];
            $pos->fuel_per_day = $rates['fuel_per_day'];
            $pos->days_remaining = $daysRemaining['fuel_days'];
            $pos->fuel_runs_out = $daysRemaining['fuel_runs_out'];
            $pos->has_fuel_bonus = $rates['has_fuel_bonus'];
            $pos->fuel_bonus_type = $rates['bonus_type'];
            $pos->fuel_reduction_percent = $rates['fuel_reduction_percent'];
            $pos->requires_charters = $rates['requires_charters'];
            $pos->charters_per_hour = $rates['charters_per_hour'];
            $pos->space_type = $pos->system_security >= self::HIGH_SEC_THRESHOLD ? 'High-Sec' : 
                              ($pos->system_security > 0 ? 'Low-Sec' : 'Null-Sec');
            
            // Analyze strontium levels and add warnings
            $strontiumAnalysis = self::analyzeStrontium($pos->type_id, $strontiumData ?? 0);
            $pos->strontium_analysis = $strontiumAnalysis;
            $pos->strontium_warning_level = $strontiumAnalysis['warning_level'];
            $pos->strontium_warning_message = $strontiumAnalysis['warning_message'];
            $pos->strontium_reinforced_timer = $strontiumAnalysis['reinforced_timer_formatted'] ?? null;
            $pos->strontium_is_critical = $strontiumAnalysis['is_critical'] ?? false;
            $pos->strontium_is_empty = $strontiumAnalysis['is_empty'] ?? false;
            
            // Add strontium status
            $pos->strontium_status = $strontiumStatus['status'];
            $pos->strontium_severity = $strontiumStatus['severity'];
            $pos->strontium_message = $strontiumStatus['message'];
            $pos->strontium_warning = $strontiumStatus['warning'];
            $pos->reinforcement_timer = $strontiumStatus['formatted_timer'];
            $pos->reinforcement_hours = $strontiumStatus['hours_available'];
            $pos->strontium_needs_restocking = $strontiumStatus['needs_restocking'];
            $pos->strontium_amount_needed = $strontiumStatus['amount_needed'];
        }
        
        return $poses;
    }
    
    /**
     * Calculate fuel savings from using faction towers
     * 
     * @param int $towerTypeId
     * @param int $days Number of days to calculate savings for
     * @return array
     */
    public static function calculateFuelSavings($towerTypeId, $days = 30)
    {
        $rates = self::getFuelConsumptionRate($towerTypeId);
        
        if (!$rates['has_fuel_bonus']) {
            return [
                'has_savings' => false,
                'message' => 'This is a T1 tower with no fuel bonus',
            ];
        }
        
        $actualConsumption = $rates['fuel_per_day'] * $days;
        $t1Consumption = $rates['base_fuel_per_hour'] * 24 * $days;
        $savings = $t1Consumption - $actualConsumption;
        
        return [
            'has_savings' => true,
            'days' => $days,
            'actual_consumption' => round($actualConsumption),
            't1_consumption' => round($t1Consumption),
            'fuel_blocks_saved' => round($savings),
            'fuel_reduction_percent' => $rates['fuel_reduction_percent'],
            'bonus_type' => $rates['bonus_type'],
        ];
    }
    
    /**
     * Get fuel type name for a tower
     * 
     * @param int $towerTypeId
     * @return string
     */
    public static function getFuelTypeName($towerTypeId)
    {
        $fuelTypeId = DB::table('invControlTowerResources')
            ->where('controlTowerTypeID', $towerTypeId)
            ->whereIn('resourceTypeID', array_keys(self::FUEL_BLOCKS))
            ->where('purpose', 1)
            ->value('resourceTypeID');
        
        return self::FUEL_BLOCKS[$fuelTypeId] ?? 'Unknown Fuel Type';
    }
    
    /**
     * Get detailed strontium status for a POS
     * 
     * @param int $towerTypeId
     * @param int $currentStrontium
     * @return array
     */
    public static function getStrontiumStatus($towerTypeId, $currentStrontium)
    {
        $rates = self::getFuelConsumptionRate($towerTypeId);
        $strontiumPerHour = $rates['strontium_for_reinforced'];

        // Guard against DivisionByZeroError when the SDE has no strontium row for
        // this tower type. Also handles null (from ->value() missing rows).
        if (empty($strontiumPerHour)) {
            return [
                'has_strontium' => $currentStrontium > 0,
                'current_amount' => $currentStrontium,
                'consumption_per_hour' => 0,
                'hours_available' => 0,
                'status' => 'unknown',
                'severity' => 'secondary',
                'message' => 'Unable to determine strontium requirements',
            ];
        }

        // Calculate reinforcement timer
        // Whole reinforced cycles only: a leftover under one cycle buys no
        // extra reinforcement time.
        $hoursAvailable = floor($currentStrontium / $strontiumPerHour);
        $days = floor($hoursAvailable / 24);
        $hours = floor($hoursAvailable % 24);
        $minutes = round(($hoursAvailable - floor($hoursAvailable)) * 60);
        
        // Determine status
        $status = 'good';
        $severity = 'success';
        $message = '';
        $warning = null;
        
        if ($currentStrontium == 0) {
            $status = 'critical';
            $severity = 'danger';
            $message = 'NO STRONTIUM! Tower has NO reinforcement timer and can be destroyed immediately!';
            $warning = 'CRITICAL: Load strontium immediately to enable reinforcement protection!';
        } elseif ($hoursAvailable < self::STRONTIUM_CRITICAL_HOURS) {
            $status = 'critical';
            $severity = 'danger';
            $message = 'CRITICAL: Less than ' . self::STRONTIUM_CRITICAL_HOURS . ' hours reinforcement timer!';
            $warning = 'Add strontium immediately! Tower is vulnerable!';
        } elseif ($hoursAvailable < self::STRONTIUM_WARNING_HOURS) {
            // Use 'warning' (not 'low') to match the vocabulary used by
            // NotifyPosLowFuel::determineStrontiumStatus and the rest of the
            // plugin. The pos/index.blade.php JS handles both 'low' and
            // 'warning' for backwards compat, but new code emits 'warning'.
            $status = 'warning';
            $severity = 'warning';
            $message = 'WARNING: Less than ' . self::STRONTIUM_WARNING_HOURS . ' hours reinforcement timer.';
            $warning = 'Add strontium soon to maintain proper defense.';
        } else {
            // 3-tier status (critical / warning / good) matches NotifyPosLowFuel.
            // The previous 4-tier model with 'fair' between warning and good
            // was never consistently rendered by the views (pos/index
            // collapsed it to 'good' anyway), so it added complexity without
            // user-visible benefit.
            $status = 'good';
            $severity = 'success';
            $message = 'GOOD: Reinforcement timer is adequate.';
            // Soft logistics nudge if below the recommended stockpile target,
            // but the tier itself stays 'good'.
            if ($hoursAvailable < self::STRONTIUM_GOOD_HOURS) {
                $warning = 'Consider topping up to ~' . self::STRONTIUM_GOOD_HOURS . ' hours for better coverage.';
            }
        }
        
        return [
            'has_strontium' => $currentStrontium > 0,
            'current_amount' => $currentStrontium,
            'consumption_per_hour' => $strontiumPerHour,
            'hours_available' => round($hoursAvailable, 1),
            'days' => $days,
            'hours' => $hours,
            'minutes' => $minutes,
            'formatted_timer' => self::formatReinforcementTimer($days, $hours, $minutes),
            'status' => $status,
            'severity' => $severity,
            'message' => $message,
            'warning' => $warning,
            'min_recommended' => $rates['strontium_min'],
            'recommended' => $rates['strontium_recommended'],
            'optimal' => $rates['strontium_optimal'],
            'needs_restocking' => $currentStrontium < $rates['strontium_min'],
            'amount_needed' => max(0, $rates['strontium_recommended'] - $currentStrontium),
        ];
    }
    
    /**
     * Format reinforcement timer for display
     * 
     * @param int $days
     * @param int $hours
     * @param int $minutes
     * @return string
     */
    private static function formatReinforcementTimer($days, $hours, $minutes)
    {
        $parts = [];
        
        if ($days > 0) {
            $parts[] = $days . 'd';
        }
        if ($hours > 0 || $days > 0) {
            $parts[] = $hours . 'h';
        }
        $parts[] = $minutes . 'm';
        
        return implode(' ', $parts);
    }
    
    /**
     * Check if a tower type is a faction tower
     * 
     * @param int $towerTypeId
     * @return bool
     */
    public static function isFactionTower($towerTypeId)
    {
        $modifier = self::FACTION_FUEL_MODIFIERS[$towerTypeId] ?? 1.0;
        return $modifier < 1.0;
    }
    
    /**
     * Analyze strontium levels and provide warnings
     * 
     * @param int $towerTypeId
     * @param int $currentStrontium
     * @return array
     */
    public static function analyzeStrontium($towerTypeId, $currentStrontium)
    {
        // Get strontium consumption rate
        $strontiumRate = DB::table('invControlTowerResources')
            ->where('controlTowerTypeID', $towerTypeId)
            ->where('resourceTypeID', self::STRONTIUM)
            ->where('purpose', 4) // Reinforced mode
            ->value('quantity');
        
        if (!$strontiumRate || $strontiumRate == 0) {
            return [
                'has_strontium_data' => false,
                'error' => 'Cannot determine strontium consumption rate for this tower type',
            ];
        }
        
        // Calculate reinforced timer duration
        $reinforcedHours = $currentStrontium > 0 ? floor($currentStrontium / $strontiumRate) : 0;
        $reinforcedMinutes = ($reinforcedHours - floor($reinforcedHours)) * 60;
        
        // Determine warning level
        $warningLevel = 'none';
        $warningMessage = null;
        $recommendation = null;
        
        if ($currentStrontium == 0) {
            $warningLevel = 'critical';
            $warningMessage = 'NO STRONTIUM! Tower cannot reinforce if attacked - immediate destruction risk!';
            $recommendation = 'Add ' . ($strontiumRate * self::STRONTIUM_GOOD_HOURS) . ' strontium immediately (' . self::STRONTIUM_GOOD_HOURS . ' hours minimum)';
        } elseif ($reinforcedHours < self::STRONTIUM_CRITICAL_HOURS) {
            $warningLevel = 'critical';
            $warningMessage = 'CRITICAL: Less than ' . self::STRONTIUM_CRITICAL_HOURS . ' hours of reinforced time remaining!';
            $recommendation = 'Add ' . ceil($strontiumRate * (self::STRONTIUM_GOOD_HOURS - $reinforcedHours)) . ' strontium to reach ' . self::STRONTIUM_GOOD_HOURS . ' hour minimum';
        } elseif ($reinforcedHours < self::STRONTIUM_WARNING_HOURS) {
            $warningLevel = 'warning';
            $warningMessage = 'WARNING: Less than ' . self::STRONTIUM_WARNING_HOURS . ' hours of reinforced time remaining';
            $recommendation = 'Add ' . ceil($strontiumRate * (self::STRONTIUM_GOOD_HOURS - $reinforcedHours)) . ' strontium to reach ' . self::STRONTIUM_GOOD_HOURS . ' hour minimum';
        } elseif ($reinforcedHours < self::STRONTIUM_GOOD_HOURS) {
            $warningLevel = 'info';
            $warningMessage = 'Below recommended minimum (' . self::STRONTIUM_GOOD_HOURS . ' hours)';
            $recommendation = 'Consider adding ' . ceil($strontiumRate * (self::STRONTIUM_GOOD_HOURS - $reinforcedHours)) . ' strontium to reach ' . self::STRONTIUM_GOOD_HOURS . ' hour minimum';
        } else {
            $warningLevel = 'good';
            $warningMessage = null;
            $recommendation = null;
        }
        
        return [
            'has_strontium_data' => true,
            'current_strontium' => $currentStrontium,
            'strontium_per_hour' => $strontiumRate,
            'reinforced_hours' => floor($reinforcedHours),
            'reinforced_minutes' => round($reinforcedMinutes),
            'reinforced_hours_decimal' => round($reinforcedHours, 2),
            'reinforced_timer_formatted' => floor($reinforcedHours) . 'h ' . round($reinforcedMinutes) . 'm',
            'warning_level' => $warningLevel, // none, info, warning, critical
            'warning_message' => $warningMessage,
            'recommendation' => $recommendation,
            'is_empty' => $currentStrontium == 0,
            'is_critical' => $reinforcedHours < self::STRONTIUM_CRITICAL_HOURS,
            'is_low' => $reinforcedHours < self::STRONTIUM_WARNING_HOURS,
            'is_below_recommended' => $reinforcedHours < self::STRONTIUM_GOOD_HOURS,
            'recommended_minimum' => $strontiumRate * self::STRONTIUM_GOOD_HOURS,
            'strontium_needed_for_recommended' => max(0, ceil(($strontiumRate * self::STRONTIUM_GOOD_HOURS) - $currentStrontium)),
        ];
    }
    
    /**
     * Fuel requirements for a tower type across fixed periods.
     *
     * Takes its rate from the same place as getFuelConsumptionRate(), which is
     * the point: these two used to disagree by exactly the faction bonus. This
     * one read invControlTowerResources.quantity and then multiplied by
     * FACTION_FUEL_MODIFIERS, but the SDE quantity already has the bonus in
     * it, so an officer medium hull came out at 12.8 blocks an hour instead of
     * 16. The Critical Alerts page quoted a weekly requirement a fifth under
     * what the tower really eats, and the Fuel Economics projection
     * under-budgeted the ISK to match.
     *
     * @param int $towerTypeId
     * @return array
     */
    public static function getStaticFuelRequirements($towerTypeId)
    {
        $towerTypeId = (int) $towerTypeId;

        // Effective per-cycle consumption, bonus included. Do not apply
        // FACTION_FUEL_MODIFIERS to this.
        $actualFuelRate = TypeIdRegistry::posTowerHourlyRate($towerTypeId) ?? 0;
        $size           = TypeIdRegistry::posTowerSize($towerTypeId);
        $fuelModifier   = TypeIdRegistry::posTowerModifier($towerTypeId);

        // What the same hull would burn with no faction bonus, for the bonus
        // badge in the UI. Not the tower's own consumption.
        $baseFuelRate = $size !== null
            ? (TypeIdRegistry::POS_BASE_FUEL_RATES[$size] ?? $actualFuelRate)
            : $actualFuelRate;

        $hourly  = $actualFuelRate;
        $daily   = $actualFuelRate * 24;
        $weekly  = $actualFuelRate * 24 * 7;   // 168 hours
        $monthly = $actualFuelRate * 24 * 30;  // 720 hours

        return [
            'tower_type_id'    => $towerTypeId,
            'tower_size'       => $size !== null ? ucfirst($size) : 'Unknown',
            'faction_type'     => TypeIdRegistry::posTowerFaction($towerTypeId) ?? 'T1',
            'base_fuel_rate'   => $baseFuelRate,
            'fuel_modifier'    => $fuelModifier,
            'actual_fuel_rate' => $actualFuelRate,
            'fuel_per_hour'    => round($hourly, 1),
            'fuel_per_day'     => round($daily, 1),
            'fuel_per_week'    => round($weekly, 0),
            'fuel_per_month'   => round($monthly, 0),
            'volume_per_week'  => round($weekly * 5, 0),   // 5 m3 per block
            'volume_per_month' => round($monthly * 5, 0),
        ];
    }
}
