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
     */
    public static function calculateFromActiveServices($structureId)
    {
        $structure = DB::table('corporation_structures')
            ->where('structure_id', $structureId)
            ->first();
        
        if (!$structure) {
            return ['hourly' => 0, 'daily' => 0, 'method' => 'unknown', 'error' => 'Structure not found'];
        }
        
        // Get online services
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
        
        $structureInfo = self::STRUCTURE_TYPES[$structure->type_id] ?? null;
        $totalHourly = 0;
        $serviceBreakdown = [];
        
        foreach ($services as $service) {
            $consumption = self::estimateServiceConsumption($service->name, $structureInfo);
            $totalHourly += $consumption;
            $serviceBreakdown[] = [
                'name' => $service->name,
                'estimated_hourly' => $consumption,
            ];
        }
        
        return [
            'hourly' => round($totalHourly, 2),
            'daily' => round($totalHourly * 24),
            'weekly' => round($totalHourly * 24 * 7),
            'monthly' => round($totalHourly * 24 * 30),
            'method' => 'active_services',
            'services' => $serviceBreakdown,
            'structure_type' => $structureInfo['name'] ?? 'Unknown',
            'note' => 'Estimated based on ' . count($services) . ' online service(s)',
        ];
    }
    
    /**
     * Estimate fuel consumption for a specific service
     * FIXED: Moon Drill now correctly returns 5 blocks/hour on ALL refineries
     */
    private static function estimateServiceConsumption($serviceName, $structureInfo)
    {
        $serviceName = strtolower($serviceName);
        
        // Map service names to fuel rates
        if (strpos($serviceName, 'market') !== false) {
            return $structureInfo['category'] === 'citadel' ? 30 : 40;
        }
        if (strpos($serviceName, 'clone') !== false || strpos($serviceName, 'cloning') !== false) {
            return $structureInfo['category'] === 'citadel' ? 7.5 : 10;
        }
        if (strpos($serviceName, 'manufacturing') !== false) {
            return $structureInfo['category'] === 'engineering' ? 9 : 12;
        }
        if (strpos($serviceName, 'research') !== false) {
            return $structureInfo['category'] === 'engineering' ? 9 : 12;
        }
        if (strpos($serviceName, 'invention') !== false) {
            return $structureInfo['category'] === 'engineering' ? 9 : 12;
        }
        if (strpos($serviceName, 'reprocessing') !== false) {
            if ($structureInfo['name'] === 'Tatara') return 7.5;
            if ($structureInfo['name'] === 'Athanor') return 8;
            return 10;
        }
        // FIXED: Moon Drill ALWAYS uses 5 blocks/hour - NO BONUSES
        if (strpos($serviceName, 'moon') !== false) {
            return 5;  // Always 5 blocks/hour (120/day) regardless of structure
        }
        if (strpos($serviceName, 'reactor') !== false || strpos($serviceName, 'reaction') !== false) {
            if ($structureInfo['name'] === 'Tatara') return 11.25;
            if ($structureInfo['name'] === 'Athanor') return 12;
            return 15;
        }
        if (strpos($serviceName, 'capital') !== false && strpos($serviceName, 'shipyard') !== false) {
            return $structureInfo['category'] === 'engineering' ? 18 : 24;
        }
        if (strpos($serviceName, 'supercapital') !== false) {
            return $structureInfo['category'] === 'engineering' ? 27 : 36;
        }
        
        // Default estimate for unknown services
        return 10;
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
            
            if ($result['method'] === 'active_services' || $result['method'] === 'no_services') {
                switch ($period) {
                    case 'hourly': return $result['hourly'];
                    case 'daily': return $result['daily'];
                    case 'weekly': return $result['weekly'];
                    case 'monthly': return $result['monthly'];
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
    
    /**
     * @deprecated - Use getServiceModifier() is no longer relevant
     * Service consumption is calculated directly from active services
     */
    public static function getServiceModifier($services)
    {
        // This method is deprecated but kept for backwards compatibility
        return 1.0;
    }
}
