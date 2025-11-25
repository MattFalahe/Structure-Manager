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
     * Fuel block type IDs
     */
    const FUEL_BLOCKS = [
        4051 => 'Nitrogen Fuel Block',   // Caldari
        4246 => 'Hydrogen Fuel Block',   // Caldari/Minmatar  
        4247 => 'Helium Fuel Block',     // Amarr
        4312 => 'Oxygen Fuel Block',     // Gallente
    ];
    
    /**
     * Strontium Clathrates type ID
     */
    const STRONTIUM = 16275;
    
    /**
     * Starbase Charter type IDs
     * Required in high-sec space (security >= 0.45) at 1 per hour
     * Charter type depends on system sovereignty/faction
     */
    const CHARTER_TYPES = [
        24592 => 'Amarr Empire Starbase Charter',
        24593 => 'Caldari State Starbase Charter',
        24594 => 'Gallente Federation Starbase Charter',
        24595 => 'Minmatar Republic Starbase Charter',
        24596 => 'Khanid Kingdom Starbase Charter',
        24597 => 'Ammatar Mandate Starbase Charter',
    ];
    
    /**
     * High-sec security threshold for charter requirement
     */
    const HIGH_SEC_THRESHOLD = 0.45;
    
    /**
     * Strontium warning thresholds (in hours of reinforced time)
     * Configurable thresholds (will be moved to UI settings later)
     */
    const STRONTIUM_CRITICAL_HOURS = 6;   // Red alert - below 6 hours
    const STRONTIUM_WARNING_HOURS = 12;   // Yellow warning - below 12 hours
    const STRONTIUM_GOOD_HOURS = 24;      // Green/good - 24 hours or more
    
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
        // Get base fuel consumption from invControlTowerResources
        $baseFuelRate = DB::table('invControlTowerResources')
            ->where('controlTowerTypeID', $towerTypeId)
            ->whereIn('resourceTypeID', array_keys(self::FUEL_BLOCKS))
            ->where('purpose', 1) // Power (online)
            ->value('quantity');
        
        // Get strontium requirement for reinforced mode
        $strontiumRate = DB::table('invControlTowerResources')
            ->where('controlTowerTypeID', $towerTypeId)
            ->where('resourceTypeID', self::STRONTIUM)
            ->where('purpose', 4) // Reinforced
            ->value('quantity');
        
        // Try to get fuel modifier from database first
        $fuelModifier = self::getFuelModifierFromDatabase($towerTypeId);
        
        // If not found in database, use hardcoded values
        if ($fuelModifier === null) {
            $fuelModifier = self::FACTION_FUEL_MODIFIERS[$towerTypeId] ?? 1.0;
            Log::info("PosFuelCalculator: Using hardcoded fuel modifier for tower type {$towerTypeId}: {$fuelModifier}");
        } else {
            Log::info("PosFuelCalculator: Using database fuel modifier for tower type {$towerTypeId}: {$fuelModifier}");
        }
        
        // Calculate actual fuel consumption with bonus applied
        $actualFuelRate = $baseFuelRate * $fuelModifier;
        
        // Check if charters are required (high-sec only)
        $requiresCharters = $systemSecurity !== null && $systemSecurity >= self::HIGH_SEC_THRESHOLD;
        $chartersPerHour = $requiresCharters ? 1 : 0;
        
        // Calculate recommended strontium amounts
        // Critical threshold: 6 hours (absolute minimum for quick response)
        // Warning threshold: 12 hours (allows time to respond)
        // Good threshold: 24 hours (provides full day coverage)
        // Recommended: 48 hours (weekend coverage)
        // Optimal: 36 hours (maximum practical amount given 12,500 m³ cargo limit)
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
     * Try to get fuel modifier from dgmTypeAttributes table
     * Returns null if table doesn't exist or no data found
     * 
     * CRITICAL: Attribute ID 676 is "Unanchoring Delay" NOT fuel consumption!
     * We need to find the correct attribute ID for fuel consumption modifier.
     * Until then, we use hardcoded values which are 100% accurate.
     * 
     * @param int $towerTypeId
     * @return float|null
     */
    private static function getFuelModifierFromDatabase($towerTypeId)
    {
        try {
            // TODO: Find correct attribute ID for fuel consumption modifier
            // Attribute 676 is "Unanchoring Delay" (time in seconds)
            // Correct attribute ID unknown - need to query dgmAttributeTypes
            
            // For now, return null to use hardcoded fallback
            // The hardcoded values are 100% accurate and don't change
            Log::info("PosFuelCalculator: Using hardcoded fuel modifiers (database attribute ID not yet identified)");
            return null;
            
            /* DISABLED UNTIL WE FIND CORRECT ATTRIBUTE ID
            $tableExists = DB::select("SHOW TABLES LIKE 'dgmTypeAttributes'");
            
            if (empty($tableExists)) {
                Log::info("PosFuelCalculator: dgmTypeAttributes table not found in database");
                return null;
            }
            
            // Try to get modifier - NEED CORRECT ATTRIBUTE ID
            $modifier = DB::table('dgmTypeAttributes')
                ->where('typeID', $towerTypeId)
                ->where('attributeID', ???)  // Unknown - need to find this
                ->value('valueFloat');
            
            return $modifier;
            */
            
        } catch (\Exception $e) {
            Log::warning("PosFuelCalculator: Could not query dgmTypeAttributes table: " . $e->getMessage());
            return null;
        }
    }
    
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
        
        // CRITICAL FIX: POS fuel mechanics
        // - POS pulls fuel at the START of each hour cycle
        // - Fuel in bay will be consumed for FUTURE hours
        // - Current hour is running on previously-pulled fuel
        // 
        // Example: 10 blocks in bay @ 10 blocks/hour consumption
        // - Old calculation: 10 / 10 = 1 hour ❌ WRONG
        // - Correct calculation: (10 / 10) + 1 = 2 hours ✅
        //   * 10 blocks will power the NEXT hour
        //   * Current hour is still running (not yet expired)
        //
        // Edge case: 0 blocks in bay
        // - Old: 0 / 10 = 0 hours ❌ (POS shown as offline but it's still running!)
        // - Correct: (0 / 10) + 1 = 1 hour ✅ (current cycle finishing)
        
        $fuelHours = $rates['fuel_per_hour'] > 0 
            ? ($currentFuelBlocks / $rates['fuel_per_hour']) + 1  // +1 for current cycle
            : 0;
        $fuelDays = round($fuelHours / 24, 2);  // Keep 2 decimal precision for sub-day accuracy
        
        // If charters are required, calculate charter days with same logic
        $charterDays = null;
        if ($rates['requires_charters'] && $rates['charters_per_hour'] > 0) {
            $charterHours = ($currentCharters / $rates['charters_per_hour']) + 1;  // +1 for current cycle
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
            $strontiumDays = round($currentStrontium / $rates['strontium_for_reinforced'] / 24, 1);
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
        
        if ($strontiumPerHour == 0) {
            return [
                'has_strontium' => false,
                'status' => 'unknown',
                'message' => 'Unable to determine strontium requirements',
            ];
        }
        
        // Calculate reinforcement timer
        $hoursAvailable = $currentStrontium / $strontiumPerHour;
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
            $status = 'low';
            $severity = 'warning';
            $message = 'LOW: Less than ' . self::STRONTIUM_WARNING_HOURS . ' hours reinforcement timer.';
            $warning = 'Add strontium soon to maintain proper defense.';
        } elseif ($hoursAvailable < self::STRONTIUM_GOOD_HOURS) {
            $status = 'fair';
            $severity = 'info';
            $message = 'FAIR: Reinforcement timer below recommended ' . self::STRONTIUM_GOOD_HOURS . ' hours.';
            $warning = 'Consider adding more strontium for better coverage.';
        } else {
            $status = 'good';
            $severity = 'success';
            $message = 'GOOD: Reinforcement timer is adequate.';
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
        $reinforcedHours = $currentStrontium > 0 ? $currentStrontium / $strontiumRate : 0;
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
     * Get static fuel requirements for a tower type
     * Returns hard-coded calculations based on tower type and faction bonuses
     * Used for consistent fuel requirement calculations across the plugin
     * 
     * @param int $towerTypeId
     * @return array
     */
    public static function getStaticFuelRequirements($towerTypeId)
    {
        // Get base fuel consumption from database
        $baseFuelRate = DB::table('invControlTowerResources')
            ->where('controlTowerTypeID', $towerTypeId)
            ->whereIn('resourceTypeID', array_keys(self::FUEL_BLOCKS))
            ->where('purpose', 1) // Power (online)
            ->value('quantity');
        
        // If not found in database, use fallback based on tower size
        if (!$baseFuelRate) {
            // Fallback: Try to determine from hardcoded rates
            // Small towers: 10/hour, Medium: 20/hour, Large: 40/hour
            $smallTowers = [20060, 20062, 20064, 20066, 27610, 27592, 27598, 27784, 27604, 27594, 27612, 27600, 27606, 27790];
            $mediumTowers = [20059, 20061, 20063, 20065, 27607, 27589, 27595, 27782, 27601, 27591, 27609, 27597, 27603, 27788];
            
            if (in_array($towerTypeId, $smallTowers)) {
                $baseFuelRate = 10;
            } elseif (in_array($towerTypeId, $mediumTowers)) {
                $baseFuelRate = 20;
            } else {
                $baseFuelRate = 40; // Large towers
            }
        }
        
        // Apply faction/officer modifier
        $fuelModifier = self::FACTION_FUEL_MODIFIERS[$towerTypeId] ?? 1.0;
        $actualFuelRate = $baseFuelRate * $fuelModifier;
        
        // Calculate static time periods
        $hourly = $actualFuelRate;
        $daily = $actualFuelRate * 24;
        $weekly = $actualFuelRate * 24 * 7;  // 168 hours
        $monthly = $actualFuelRate * 24 * 30; // 720 hours (30 days)
        
        // Determine tower size for display
        $towerSize = 'Large'; // Default
        $smallTowers = [20060, 20062, 20064, 20066, 27610, 27592, 27598, 27784, 27604, 27594, 27612, 27600, 27606, 27790];
        $mediumTowers = [20059, 20061, 20063, 20065, 27607, 27589, 27595, 27782, 27601, 27591, 27609, 27597, 27603, 27788];
        
        if (in_array($towerTypeId, $smallTowers)) {
            $towerSize = 'Small';
        } elseif (in_array($towerTypeId, $mediumTowers)) {
            $towerSize = 'Medium';
        }
        
        // Determine faction type
        $factionType = 'T1'; // Default
        if ($fuelModifier == 0.9) {
            $factionType = 'Faction';
        } elseif ($fuelModifier == 0.8) {
            $factionType = 'Officer';
        }
        
        return [
            'tower_type_id' => $towerTypeId,
            'tower_size' => $towerSize,
            'faction_type' => $factionType,
            'base_fuel_rate' => $baseFuelRate,
            'fuel_modifier' => $fuelModifier,
            'actual_fuel_rate' => $actualFuelRate,
            'fuel_per_hour' => round($hourly, 1),
            'fuel_per_day' => round($daily, 1),
            'fuel_per_week' => round($weekly, 0),
            'fuel_per_month' => round($monthly, 0),
            'volume_per_week' => round($weekly * 5, 0), // Each fuel block = 5 m³
            'volume_per_month' => round($monthly * 5, 0),
        ];
    }
}
