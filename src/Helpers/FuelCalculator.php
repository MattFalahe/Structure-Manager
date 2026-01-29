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
     * Magmatic Gas type ID
     */
    const MAGMATIC_GAS_TYPE_ID = 81143;
    
    /**
     * CRITICAL: Map services to their source modules
     * One module can provide multiple services but only consumes fuel ONCE
     * Service names are CASE-SENSITIVE and must match EVE API exactly
     */
    const SERVICE_TO_MODULE_MAP = [
        // Research Lab provides 3 services but is 1 module
        'Blueprint Copying' => 'research_lab',
        'Material Efficiency Research' => 'research_lab',
        'Time Efficiency Research' => 'research_lab',
        
        // Invention Lab provides 1 service
        'Invention' => 'invention_lab',
        
        // Manufacturing Plant provides 1 service
        'Manufacturing (Standard)' => 'manufacturing_plant',
        
        // Capital Shipyard provides 1 service
        'Manufacturing (Capitals)' => 'capital_shipyard',
        
        // Supercapital Shipyard provides 1 service (Sotiyo only)
        'Manufacturing (Supercapitals)' => 'supercapital_shipyard',
        
        // Reprocessing Facility provides 1 service
        'Reprocessing' => 'reprocessing_facility',
        
        // Moon Drill provides 1 service (Athanor/Tatara)
        'Moon Drilling' => 'moon_drill',
        
        // Automatic Moon Drilling (Metenox Moon Drill - deployable structure)
        'Automatic Moon Drilling' => 'metenox_moon_drill',
        
        // Reactors provide 1 service each
        'Composite Reactions' => 'composite_reactor',
        'Biochemical Reactions' => 'biochemical_reactor',
        'Hybrid Reactions' => 'hybrid_reactor',
        
        // Market Hub provides 1 service
        'Market' => 'market_hub',
        
        // Cloning Center provides 1 service
        'Clone Bay' => 'cloning_center',
        
        // Navigation structures
        'Jump Gate' => 'ansiblex_jump_bridge',
        'Cynosural Beacon' => 'pharolux_cyno_beacon',
        'Cynosural Jammer' => 'tenebrex_cyno_jammer',
    ];
    
    /**
     * Service module fuel consumption rates (blocks per hour)
     * Based on actual EVE Online mechanics as of 2025
     */
    const SERVICE_FUEL_RATES = [
        // Citadel Services
        'cloning_center' => [
            'base' => 10,
            'citadel_bonus' => 7.5,  // -25% on Citadels
        ],
        'market_hub' => [
            'base' => 40,
            'citadel_bonus' => 30,  // -25% on Citadels
            'restrictions' => 'Large/X-Large only',
        ],
        
        // Manufacturing & Research
        'manufacturing_plant' => [
            'base' => 12,
            'engineering_bonus' => 9,  // -25% on Engineering Complexes
        ],
        'research_lab' => [
            'base' => 12,
            'engineering_bonus' => 9,
            'faction_base' => 10,  // Hyasyoda variant
            'faction_engineering_bonus' => 7.5,
        ],
        'invention_lab' => [
            'base' => 12,
            'engineering_bonus' => 9,
        ],
        'capital_shipyard' => [
            'base' => 24,
            'engineering_bonus' => 18,
            'restrictions' => 'Large/X-Large only, no high-sec',
        ],
        'supercapital_shipyard' => [
            'base' => 36,
            'engineering_bonus' => 27,
            'restrictions' => 'Sotiyo only, sov null-sec only',
        ],
        
        // Refinery Services
        'reprocessing_facility' => [
            'base' => 10,
            'athanor_bonus' => 8,   // -20% on Athanor
            'tatara_bonus' => 7.5,  // -25% on Tatara
        ],
        'moon_drill' => [
            'base' => 5,
            // NO BONUSES - Moon Drill always uses 5 blocks/hour (120/day) on ALL refineries
            'restrictions' => 'Refineries only',
        ],
        'composite_reactor' => [
            'base' => 15,
            'athanor_bonus' => 12,   // -20% on Athanor
            'tatara_bonus' => 11.25, // -25% on Tatara
            'restrictions' => 'Refineries only, no high-sec',
        ],
        'biochemical_reactor' => [
            'base' => 15,
            'athanor_bonus' => 12,   // -20% on Athanor
            'tatara_bonus' => 11.25, // -25% on Tatara
            'restrictions' => 'Refineries only, no high-sec',
        ],
        'hybrid_reactor' => [
            'base' => 15,
            'athanor_bonus' => 12,   // -20% on Athanor
            'tatara_bonus' => 11.25, // -25% on Tatara
            'restrictions' => 'Refineries only, no high-sec',
        ],
        
        // Navigation Structures (Flex Structures)
        'ansiblex_jump_bridge' => [
            'base' => 30,
            'restrictions' => 'Requires sov, one per system',
        ],
        'pharolux_cyno_beacon' => [
            'base' => 15,
            'restrictions' => 'Requires sov, one per system',
        ],
        'tenebrex_cyno_jammer' => [
            'base' => 40,
            'restrictions' => 'Requires sov, up to 3 per system',
        ],
        
        // Metenox Moon Drill (deployable structure, not Upwell)
        'metenox_moon_drill' => [
            'base' => 5,  // 5 fuel blocks per hour
            'magmatic_gas' => 200,  // 200 magmatic gas per hour
            'note' => 'Metenox Moon Drills consume 5 fuel blocks/hour (120/day) + 200 magmatic gas/hour (4,800/day). CRITICAL: Magmatic gas often runs out BEFORE fuel blocks!',
            'restrictions' => 'Deployable structure, requires magmatic gas in addition to fuel blocks',
        ],
    ];
    
    /**
     * Structure type definitions
     */
    const STRUCTURE_TYPES = [
        // Engineering Complexes
        35825 => ['name' => 'Raitaru', 'category' => 'engineering', 'size' => 'medium'],
        35826 => ['name' => 'Azbel', 'category' => 'engineering', 'size' => 'large'],
        35827 => ['name' => 'Sotiyo', 'category' => 'engineering', 'size' => 'xlarge'],
        
        // Citadels
        35832 => ['name' => 'Astrahus', 'category' => 'citadel', 'size' => 'medium'],
        35833 => ['name' => 'Fortizar', 'category' => 'citadel', 'size' => 'large'],
        35834 => ['name' => 'Keepstar', 'category' => 'citadel', 'size' => 'xlarge'],
        40340 => ['name' => 'Palatine Keepstar', 'category' => 'citadel', 'size' => 'xlarge'],
        
        // Refineries
        35835 => ['name' => 'Athanor', 'category' => 'refinery', 'size' => 'medium'],
        35836 => ['name' => 'Tatara', 'category' => 'refinery', 'size' => 'large'],
        
        // Navigation Structures
        35841 => ['name' => 'Ansiblex Jump Gate', 'category' => 'navigation', 'size' => 'medium'],
        35840 => ['name' => 'Pharolux Cyno Beacon', 'category' => 'navigation', 'size' => 'medium'],
        35839 => ['name' => 'Tenebrex Cyno Jammer', 'category' => 'navigation', 'size' => 'medium'],
        
        // Metenox Moon Drill (Deployable)
        81826 => ['name' => 'Metenox Moon Drill', 'category' => 'deployable', 'size' => 'medium'],
    ];
    
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
     * Fuel block types from EVE
     */
    const FUEL_BLOCKS = [
        4051 => 'Nitrogen Fuel Block',
        4246 => 'Hydrogen Fuel Block',
        4247 => 'Helium Fuel Block',
        4312 => 'Oxygen Fuel Block',
    ];
    
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
