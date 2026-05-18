<?php

namespace StructureManager\Helpers;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class FuelCalculator
{
    /**
     * CRITICAL: Upwell structures themselves consume ZERO fuel
     * Only service modules consume fuel blocks
     */
    
    /**
     * Magmatic Gas type ID.
     * @deprecated use TypeIdRegistry::MAGMATIC_GAS
     */
    const MAGMATIC_GAS_TYPE_ID = TypeIdRegistry::MAGMATIC_GAS;
    
    /**
     * Service name → module slug mapping.
     * @deprecated use TypeIdRegistry::SERVICE_TO_MODULE_MAP
     */
    const SERVICE_TO_MODULE_MAP = TypeIdRegistry::SERVICE_TO_MODULE_MAP;

    /**
     * Service module fuel consumption rates (blocks per hour).
     * @deprecated use TypeIdRegistry::SERVICE_FUEL_RATES
     */
    const SERVICE_FUEL_RATES = TypeIdRegistry::SERVICE_FUEL_RATES;
    
    /**
     * Structure type definitions.
     * @deprecated use TypeIdRegistry::UPWELL_TYPE_IDS
     */
    const STRUCTURE_TYPES = TypeIdRegistry::UPWELL_TYPE_IDS;
    
    /**
     * Typical service configurations for estimation
     */
    const TYPICAL_CONFIGS = [
        'citadel' => [
            'minimal' => ['cloning_center'],  // 7.5 blocks/hour with bonus
            'trading' => ['market_hub', 'cloning_center'],  // 37.5 blocks/hour
        ],
        'engineering' => [
            'minimal' => ['manufacturing_plant'],  // 9 blocks/hour with bonus
            'standard' => ['manufacturing_plant', 'research_lab'],  // 18 blocks/hour
            'full' => ['manufacturing_plant', 'research_lab', 'invention_lab'],  // 27 blocks/hour
            'capital' => ['manufacturing_plant', 'research_lab', 'capital_shipyard'],  // 45 blocks/hour
        ],
        'refinery' => [
            'minimal' => ['moon_drill'],  // 5 blocks/hour (NO BONUS on Athanor or Tatara)
            'moon_mining' => ['moon_drill', 'reprocessing_facility'],  // 13 blocks/hour (Athanor), 12.5 (Tatara)
            'reactions' => ['moon_drill', 'reprocessing_facility', 'composite_reactor'],  // 25 blocks/hour (Athanor), 23.75 (Tatara)
        ],
        'deployable' => [
            'metenox' => ['metenox_moon_drill'],  // 5 blocks/hour + 200 gas/hour
        ],
    ];
    
    /**
     * Fuel block types from EVE.
     * @deprecated use TypeIdRegistry::FUEL_BLOCK_NAMES
     */
    const FUEL_BLOCKS = TypeIdRegistry::FUEL_BLOCK_NAMES;
    
    /**
     * Get estimated fuel consumption based on active services
     * This is the PRIMARY method - it analyzes actual online services
     * FIXED: Now groups services by module to avoid double-counting
     * NEW: Supports Metenox with magmatic gas tracking
     */
    public static function calculateFromActiveServices($structureId)
    {
        $structure = DB::table('corporation_structures')
            ->where('structure_id', $structureId)
            ->first();
        
        if (!$structure) {
            return ['hourly' => 0, 'daily' => 0, 'method' => 'unknown', 'error' => 'Structure not found'];
        }
        
        $structureInfo = self::STRUCTURE_TYPES[$structure->type_id] ?? null;
        
        // Special handling for Metenox Moon Drill
        if ($structureInfo && $structureInfo['category'] === 'deployable' && $structureInfo['name'] === 'Metenox Moon Drill') {
            return self::calculateMetenoxConsumption($structureId, $structure);
        }
        
        // Get online services (Upwell structures)
        $services = DB::table('corporation_structure_services')
            ->where('structure_id', $structureId)
            ->where('state', 'online')
            ->get();
        
        if ($services->isEmpty()) {
            return [
                'hourly' => 0,
                'daily' => 0,
                'weekly' => 0,
                'monthly' => 0,
                'method' => 'no_services',
                'note' => 'Structure has no online services - consuming 0 fuel',
            ];
        }
        
        // CRITICAL FIX: Group services by their source module
        $activeModules = [];
        $serviceBreakdown = [];
        
        foreach ($services as $service) {
            // Use exact service name (case-sensitive)
            $serviceName = $service->name;
            
            // Map service to its module
            $moduleName = self::SERVICE_TO_MODULE_MAP[$serviceName] ?? 'unknown';
            
            // Only count each module once
            if (!isset($activeModules[$moduleName])) {
                $consumption = self::getModuleConsumption($moduleName, $structureInfo);
                $activeModules[$moduleName] = $consumption;
            }
            
            // Track which services are provided by which module
            $serviceBreakdown[] = [
                'service_name' => $service->name,
                'module' => $moduleName,
                'fuel_consumption' => $activeModules[$moduleName] ?? 0,
            ];
        }
        
        // Sum fuel consumption from unique modules
        $totalHourly = array_sum($activeModules);
        
        // Group services by module for display
        $moduleBreakdown = [];
        foreach ($activeModules as $module => $consumption) {
            $servicesForModule = array_filter($serviceBreakdown, fn($s) => $s['module'] === $module);
            $serviceNames = array_map(fn($s) => $s['service_name'], $servicesForModule);
            
            $moduleBreakdown[] = [
                'module' => $module,
                'services_provided' => $serviceNames,
                'hourly_consumption' => $consumption,
            ];
        }
        
        return [
            'hourly' => round($totalHourly, 2),
            'daily' => round($totalHourly * 24),
            'weekly' => round($totalHourly * 24 * 7),
            'monthly' => round($totalHourly * 24 * 30),
            'quarterly' => round($totalHourly * 24 * 90),
            'method' => 'active_services',
            'modules' => $moduleBreakdown,
            'structure_type' => $structureInfo['name'] ?? 'Unknown',
            'note' => 'Based on ' . count($activeModules) . ' active module(s) providing ' . count($services) . ' service(s)',
        ];
    }
    
    /**
     * Calculate consumption for Metenox Moon Drill
     * Tracks BOTH fuel blocks AND magmatic gas
     */
    private static function calculateMetenoxConsumption($structureId, $structure)
    {
        // Get current fuel bay contents
        $fuelBlocks = DB::table('corporation_assets')
            ->where('location_id', $structureId)
            ->where('location_flag', 'StructureFuel')
            ->whereIn('type_id', array_keys(self::FUEL_BLOCKS))
            ->sum('quantity');
        
        $magmaticGas = DB::table('corporation_assets')
            ->where('location_id', $structureId)
            ->where('location_flag', 'StructureFuel')
            ->where('type_id', self::MAGMATIC_GAS_TYPE_ID)
            ->sum('quantity');
        
        // Calculate days remaining for each resource
        $fuelDaysRemaining = $fuelBlocks > 0 ? $fuelBlocks / (5 * 24) : 0;  // 5 blocks/hour
        $gasDaysRemaining = $magmaticGas > 0 ? $magmaticGas / (200 * 24) : 0;  // 200 gas/hour
        
        // The REAL time until empty is whichever runs out first
        $actualDaysRemaining = min($fuelDaysRemaining, $gasDaysRemaining);
        
        // Determine limiting factor
        $limitingFactor = 'none';
        if ($actualDaysRemaining > 0) {
            $limitingFactor = $fuelDaysRemaining < $gasDaysRemaining ? 'fuel_blocks' : 'magmatic_gas';
        }
        
        return [
            'hourly' => 5,
            'daily' => 120,
            'weekly' => 840,
            'monthly' => 3600,
            'quarterly' => 10800,
            'method' => 'metenox_drill',
            'structure_type' => 'Metenox Moon Drill',
            'magmatic_gas' => [
                'hourly' => 200,
                'daily' => 4800,
                'weekly' => 33600,
                'monthly' => 144000,
                'current_quantity' => $magmaticGas,
                'days_remaining' => round($gasDaysRemaining, 1),
            ],
            'fuel_blocks' => [
                'current_quantity' => $fuelBlocks,
                'days_remaining' => round($fuelDaysRemaining, 1),
            ],
            'actual_days_remaining' => round($actualDaysRemaining, 1),
            'limiting_factor' => $limitingFactor,
            'warning' => $limitingFactor === 'magmatic_gas' ? 
                'WARNING: Magmatic gas will run out in ' . round($gasDaysRemaining, 1) . ' days (before fuel blocks at ' . round($fuelDaysRemaining, 1) . ' days)!' : 
                ($limitingFactor === 'fuel_blocks' ? 
                    'WARNING: Fuel blocks will run out in ' . round($fuelDaysRemaining, 1) . ' days (before magmatic gas at ' . round($gasDaysRemaining, 1) . ' days)!' : 
                    null),
            'note' => 'Metenox consumes 5 fuel blocks/hour + 200 magmatic gas/hour. Both must be stocked!',
        ];
    }
    
    /**
     * Get fuel consumption for a specific module (not service)
     * FIXED: Now calculates per MODULE instead of per SERVICE
     */
    private static function getModuleConsumption($moduleName, $structureInfo)
    {
        $rate = self::SERVICE_FUEL_RATES[$moduleName] ?? null;
        
        if (!$rate) {
            // Unknown module - log warning and use safe default
            if ($moduleName !== 'unknown') {
                \Log::warning("Structure Manager: Unknown module '{$moduleName}' - using default 10 blocks/hour");
            }
            return 10;
        }
        
        // Special case: Metenox Moon Drill
        if ($moduleName === 'metenox_moon_drill') {
            return $rate['base'];  // Just return fuel blocks, gas tracked separately
        }
        
        $category = $structureInfo['category'] ?? 'unknown';
        $structureName = $structureInfo['name'] ?? '';
        
        // Apply structure bonuses
        if ($category === 'citadel' && isset($rate['citadel_bonus'])) {
            return $rate['citadel_bonus'];
        } elseif ($category === 'engineering' && isset($rate['engineering_bonus'])) {
            return $rate['engineering_bonus'];
        } elseif ($category === 'refinery') {
            // Moon Drill NEVER gets bonuses
            if ($moduleName === 'moon_drill') {
                return $rate['base'];
            }
            
            if ($structureName === 'Tatara' && isset($rate['tatara_bonus'])) {
                return $rate['tatara_bonus'];
            } elseif ($structureName === 'Athanor' && isset($rate['athanor_bonus'])) {
                return $rate['athanor_bonus'];
            }
        }
        
        return $rate['base'];
    }
    
    /**
     * Get typical configuration estimate (FALLBACK when no service data)
     * This is for logistics planning only
     */
    public static function getTypicalConfigEstimate($structureTypeId, $config = 'standard')
    {
        $structureInfo = self::STRUCTURE_TYPES[$structureTypeId] ?? null;
        
        if (!$structureInfo) {
            return [
                'hourly' => 0,
                'daily' => 0,
                'method' => 'unknown_structure',
                'warning' => 'Unknown structure type',
            ];
        }
        
        $category = $structureInfo['category'];
        $configs = self::TYPICAL_CONFIGS[$category] ?? null;
        
        if (!$configs || !isset($configs[$config])) {
            // Default to minimal config
            $config = 'minimal';
        }
        
        $services = $configs[$config] ?? ['cloning_center'];
        $totalHourly = 0;
        
        foreach ($services as $serviceKey) {
            $rate = self::SERVICE_FUEL_RATES[$serviceKey] ?? null;
            if ($rate) {
                // Apply structure bonuses
                if ($category === 'citadel' && isset($rate['citadel_bonus'])) {
                    $totalHourly += $rate['citadel_bonus'];
                } elseif ($category === 'engineering' && isset($rate['engineering_bonus'])) {
                    $totalHourly += $rate['engineering_bonus'];
                } elseif ($category === 'refinery') {
                    if ($structureInfo['name'] === 'Tatara' && isset($rate['tatara_bonus'])) {
                        $totalHourly += $rate['tatara_bonus'];
                    } elseif ($structureInfo['name'] === 'Athanor' && isset($rate['athanor_bonus'])) {
                        $totalHourly += $rate['athanor_bonus'];
                    } else {
                        $totalHourly += $rate['base'];
                    }
                } else {
                    $totalHourly += $rate['base'];
                }
            }
        }
        
        return [
            'hourly' => round($totalHourly, 2),
            'daily' => round($totalHourly * 24),
            'weekly' => round($totalHourly * 24 * 7),
            'monthly' => round($totalHourly * 24 * 30),
            'quarterly' => round($totalHourly * 24 * 90),
            'method' => 'typical_config',
            'config' => $config,
            'structure_type' => $structureInfo['name'],
            'warning' => 'This is an ESTIMATE based on typical ' . $config . ' configuration. Actual consumption may vary.',
        ];
    }
    
    /**
     * Smart fuel requirement - tries active services first, falls back to typical config
     * USE THIS in controllers for logistics planning
     */
    public static function getFuelRequirement($structureTypeId, $structureId = null, $period = 'monthly')
    {
        // Try to get from active services if we have structure ID
        if ($structureId) {
            $result = self::calculateFromActiveServices($structureId);
            
            if ($result['method'] === 'active_services' || $result['method'] === 'no_services' || $result['method'] === 'metenox_drill') {
                switch ($period) {
                    case 'hourly': return $result['hourly'];
                    case 'daily': return $result['daily'];
                    case 'weekly': return $result['weekly'];
                    case 'monthly': return $result['monthly'];
                    case 'quarterly': return $result['quarterly'] ?? ($result['monthly'] * 3);
                    default: return $result['daily'];
                }
            }
        }
        
        // Fallback to typical config estimate
        $estimate = self::getTypicalConfigEstimate($structureTypeId, 'standard');
        
        switch ($period) {
            case 'hourly': return $estimate['hourly'];
            case 'daily': return $estimate['daily'];
            case 'weekly': return $estimate['weekly'];
            case 'monthly': return $estimate['monthly'];
            case 'quarterly': return $estimate['quarterly'];
            default: return $estimate['daily'];
        }
    }
    
    /**
     * Calculate estimated fuel blocks remaining based on days and consumption
     * @deprecated - Use actual tracking data from StructureFuelHistory instead
     */
    public static function calculateBlocksRemaining($structureTypeId, $daysRemaining, $structureId = null)
    {
        $hourlyRate = self::getFuelRequirement($structureTypeId, $structureId, 'hourly');
        return round($daysRemaining * 24 * $hourlyRate);
    }
    
    /**
     * Get structure category (for applying correct bonuses)
     */
    public static function getStructureCategory($structureTypeId)
    {
        return self::STRUCTURE_TYPES[$structureTypeId]['category'] ?? 'unknown';
    }
    
    /**
     * Get structure info
     */
    public static function getStructureInfo($structureTypeId)
    {
        return self::STRUCTURE_TYPES[$structureTypeId] ?? [
            'name' => 'Unknown',
            'category' => 'unknown',
            'size' => 'unknown',
        ];
    }
    
    /**
     * =========================================================================
     * CROSS-PLUGIN INTEGRATION (Manager Core + Mining Manager)
     * =========================================================================
     * Added 2026-04-24 to support Mining Manager's extraction_at_risk
     * notification — MM subscribes to `structure.alert.*` events on MC's
     * EventBus and uses this helper to confirm the alerting refinery has
     * an active moon extraction worth warning about.
     *
     * @pending-sm-work  This is the FIRST piece of a larger cross-plugin
     *                   integration. Next pieces (same plugin):
     *
     *   1. ✅ hasActiveMoonExtraction() helper — this method (ships now)
     *   2. ✅ structure.alert.fuel_critical publish from NotifyUpwellLowFuel
     *          (see publishFuelCriticalEvent() in that Job; subscriber-side
     *          filtering — was previously gated to refineries-with-active-
     *          extraction here, but moved to MM's StructureAlertHandler so
     *          non-MM subscribers can also receive citadel/EC fuel alerts)
     *   3. ✅ Progressive combat events: structure.alert.shield_reinforced,
     *          structure.alert.armor_reinforced, structure.alert.hull_reinforced
     *          — scan character_notifications for StructureLostShields /
     *          StructureLostArmor / StructureUnderAttack types.
     *   4. ✅ structure.alert.destroyed — requires disappearance-detection
     *          tracking table + StructureDestroyed notification scan.
     *          Full design in memory:
     *          project_structure_manager_destruction_detection.md
     *
     * Mining Manager already subscribes to the full `structure.alert.*`
     * wildcard pattern, so every new flavor published from SM starts
     * working on MM's side with no MM-side changes.
     * =========================================================================
     */

    /**
     * Check if a refinery (Athanor/Tatara) has an active moon extraction
     * running right now. Used by NotifyUpwellLowFuel to scope the
     * `structure.alert.fuel_critical` event to refineries where a low-fuel
     * state has real operational consequences beyond "structure goes
     * offline" — a lost chunk is material ISK.
     *
     * Returns false (and does NOT throw) when Mining Manager isn't
     * installed, so Structure Manager can call this unconditionally
     * without class_exists guards at every callsite.
     *
     * @param int $structureId
     * @return bool
     */
    public static function hasActiveMoonExtraction(int $structureId): bool
    {
        // Mining Manager not installed — safe no-op
        if (!class_exists('MiningManager\\Models\\MoonExtraction')) {
            return false;
        }

        try {
            return \MiningManager\Models\MoonExtraction::query()
                ->where('structure_id', $structureId)
                ->whereNotIn('status', ['cancelled', 'expired'])
                // Plugin lifecycle max ~55h after chunk_arrival. Same window
                // MM uses internally for "active extraction" queries.
                ->where('chunk_arrival_time', '>', \Carbon\Carbon::now()->subHours(55))
                ->exists();
        } catch (\Throwable $e) {
            // Defensive — if MM tables aren't yet migrated or the query
            // blows up for any reason, fall back to "no active extraction"
            // so we don't surface a false SM error for a transient issue.
            //
            // Logged at warning (not debug) because this failure means the
            // SM ↔ MM cross-plugin alert chain is silently broken: SM's
            // own webhooks for low-fuel still fire, but the structure.alert.*
            // event won't be published and MM's extraction_at_risk
            // notification never fires. Without warning-level visibility,
            // operators wouldn't notice the integration is degraded.
            //
            // Common causes that warrant operator attention:
            //  - MM's moon_extractions table missing/migrated incorrectly
            //  - DB connection issue
            //  - MM's MoonExtraction model class loaded but signature drift
            \Log::warning("[SM] hasActiveMoonExtraction check failed for structure {$structureId}: " . $e->getMessage(), [
                'structure_id' => $structureId,
                'error' => $e->getMessage(),
                'trace_first_frame' => $e->getTrace()[0] ?? null,
            ]);
            return false;
        }
    }

    /**
     * Parse fuel notification YAML text (unchanged)
     */
    public static function parseFuelNotification($yamlText)
    {
        $lines = explode("\n", $yamlText);
        $data = [];
        
        foreach ($lines as $line) {
            if (preg_match('/^(\w+):\s*(.*)$/', $line, $matches)) {
                $data[$matches[1]] = $matches[2];
            } elseif (preg_match('/^- - (\d+)/', $line, $matches)) {
                $data['fuel_quantity'] = (int)$matches[1];
            } elseif (preg_match('/^  - (\d+)/', $line, $matches)) {
                $data['fuel_type_id'] = (int)$matches[1];
            }
        }
        
        return $data;
    }
}
