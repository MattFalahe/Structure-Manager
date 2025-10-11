<?php

namespace StructureManager\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use StructureManager\Helpers\FuelCalculator;
use Carbon\Carbon;

class FuelAlertController extends Controller
{
    /**
     * Metenox Moon Drill type ID
     */
    const METENOX_TYPE_ID = 81826;
    
    /**
     * Get user's accessible corporation IDs
     */
    private function getUserCorporations()
    {
        // Get corporation IDs from user's characters via refresh_tokens and character_affiliations
        $corporationIds = DB::table('refresh_tokens')
            ->join('character_affiliations', 'refresh_tokens.character_id', '=', 'character_affiliations.character_id')
            ->where('refresh_tokens.user_id', auth()->id())
            ->whereNull('refresh_tokens.deleted_at')
            ->pluck('character_affiliations.corporation_id')
            ->unique()
            ->filter()
            ->toArray();
        
        return !empty($corporationIds) ? $corporationIds : null;
    }
    
    /**
     * Show critical alerts view
     */
    public function criticalAlertsView()
    {
        return view('structure-manager::critical-alerts');
    }
    
    /**
     * Show logistics report view
     */
    public function logisticsReportView()
    {
        return view('structure-manager::logistics-report');
    }
    
    /**
     * Get critical fuel alerts for dashboard widget
     */
    public function getCriticalAlerts()
    {
        try {
            $query = DB::table('corporation_structures as cs')
                ->join('universe_structures as us', 'cs.structure_id', '=', 'us.structure_id')
                ->join('invTypes as it', 'cs.type_id', '=', 'it.typeID')
                ->join('mapDenormalize as md', 'cs.system_id', '=', 'md.itemID')
                ->whereNotNull('cs.fuel_expires')
                ->whereRaw('TIMESTAMPDIFF(HOUR, NOW(), cs.fuel_expires) < 336'); // < 14 days
            
            // Filter by user's corporations
            $userCorps = $this->getUserCorporations();
            if ($userCorps !== null) {
                $query->whereIn('cs.corporation_id', $userCorps);
            }
            
            $structures = $query->select(
                    'cs.structure_id',
                    'us.name as structure_name',
                    'it.typeName as structure_type',
                    'cs.type_id',
                    'md.itemName as system_name',
                    'cs.fuel_expires',
                    DB::raw('TIMESTAMPDIFF(HOUR, NOW(), cs.fuel_expires) as hours_remaining'),
                    DB::raw('FLOOR(TIMESTAMPDIFF(HOUR, NOW(), cs.fuel_expires) / 24) as days_remaining'),
                    DB::raw('MOD(TIMESTAMPDIFF(HOUR, NOW(), cs.fuel_expires), 24) as remaining_hours')
                )
                ->orderBy('hours_remaining', 'asc')
                ->limit(10)
                ->get();
            
            // Calculate fuel blocks needed and add Metenox data
            foreach ($structures as $structure) {
                try {
                    $structure->blocks_needed = FuelCalculator::getFuelRequirement(
                        $structure->type_id,
                        $structure->structure_id,
                        'weekly'
                    );
                } catch (\Exception $e) {
                    \Log::warning("Failed to calculate fuel requirement for structure {$structure->structure_id}: " . $e->getMessage());
                    $structure->blocks_needed = 0;
                }
                
                $structure->status = $structure->hours_remaining < 168 ? 'critical' : 'warning';
                
                // Add Metenox limiting factor data if applicable
                if ($structure->type_id == self::METENOX_TYPE_ID) {
                    try {
                        // Get latest fuel history record for this Metenox
                        $latestHistory = DB::table('structure_fuel_history')
                            ->where('structure_id', $structure->structure_id)
                            ->orderBy('created_at', 'desc')
                            ->first();
                        
                        if ($latestHistory) {
                            $metadata = null;
                            
                            // Safely decode metadata
                            if ($latestHistory->metadata) {
                                if (is_string($latestHistory->metadata)) {
                                    $metadata = json_decode($latestHistory->metadata, true);
                                } else {
                                    $metadata = $latestHistory->metadata;
                                }
                            }
                            
                            // Only add metenox_data if we have valid metadata
                            if ($metadata && is_array($metadata)) {
                                $structure->metenox_data = [
                                    'fuel_blocks_quantity' => $metadata['fuel_blocks'] ?? 0,
                                    'magmatic_gas_quantity' => $latestHistory->magmatic_gas_quantity ?? 0,
                                    'fuel_blocks_days' => $metadata['fuel_days_remaining'] ?? 0,
                                    'magmatic_gas_days' => $latestHistory->magmatic_gas_days ?? 0,
                                    'limiting_factor' => $metadata['limiting_factor'] ?? 'unknown',
                                ];
                            } else {
                                // Fallback metenox data if metadata is missing
                                $structure->metenox_data = [
                                    'fuel_blocks_quantity' => 0,
                                    'magmatic_gas_quantity' => 0,
                                    'fuel_blocks_days' => 0,
                                    'magmatic_gas_days' => 0,
                                    'limiting_factor' => 'unknown',
                                ];
                            }
                        }
                    } catch (\Exception $e) {
                        \Log::warning("Failed to fetch Metenox data for structure {$structure->structure_id}: " . $e->getMessage());
                        // Set default metenox_data on error
                        $structure->metenox_data = [
                            'fuel_blocks_quantity' => 0,
                            'magmatic_gas_quantity' => 0,
                            'fuel_blocks_days' => 0,
                            'magmatic_gas_days' => 0,
                            'limiting_factor' => 'unknown',
                        ];
                    }
                }
            }
            
            return response()->json($structures);
            
        } catch (\Exception $e) {
            \Log::error('Structure Manager - Error in getCriticalAlerts: ' . $e->getMessage());
            \Log::error('Stack trace: ' . $e->getTraceAsString());
            
            return response()->json([
                'error' => true,
                'message' => 'Failed to load critical alerts',
                'debug' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
    
    /**
     * Generate fuel logistics report
     */
    public function getLogisticsReport()
    {
        $query = DB::table('corporation_structures as cs')
            ->join('universe_structures as us', 'cs.structure_id', '=', 'us.structure_id')
            ->join('invTypes as it', 'cs.type_id', '=', 'it.typeID')
            ->join('mapDenormalize as md', 'cs.system_id', '=', 'md.itemID')
            ->join('corporation_infos as ci', 'cs.corporation_id', '=', 'ci.corporation_id')
            ->whereNotNull('cs.fuel_expires');
        
        // Filter by user's corporations
        $userCorps = $this->getUserCorporations();
        if ($userCorps !== null) {
            $query->whereIn('cs.corporation_id', $userCorps);
        }
        
        $structures = $query->select(
                'cs.structure_id',
                'us.name as structure_name',
                'it.typeName as structure_type',
                'cs.type_id',
                'md.itemName as system_name',
                'ci.name as corporation_name',
                'cs.fuel_expires',
                DB::raw('TIMESTAMPDIFF(HOUR, NOW(), cs.fuel_expires) as hours_remaining'),
                DB::raw('FLOOR(TIMESTAMPDIFF(HOUR, NOW(), cs.fuel_expires) / 24) as days_remaining'),
                DB::raw('MOD(TIMESTAMPDIFF(HOUR, NOW(), cs.fuel_expires), 24) as remaining_hours')
            )
            ->orderBy('system_name', 'asc')
            ->orderBy('hours_remaining', 'asc')
            ->get();
        
        $report = [];
        $totalBlocksNeeded = 0;
        
        foreach ($structures as $structure) {
            $system = $structure->system_name;
            
            if (!isset($report[$system])) {
                $report[$system] = [
                    'structures' => [],
                    'total_blocks_30d' => 0,
                    'total_blocks_60d' => 0,
                    'total_blocks_90d' => 0,
                ];
            }
            
            // FIXED: Pass structure_id as second parameter to get actual services
            $blocks30d = FuelCalculator::getFuelRequirement(
                $structure->type_id,
                $structure->structure_id,
                'monthly'
            );
            $blocks60d = $blocks30d * 2;
            $blocks90d = $blocks30d * 3;
            
            $report[$system]['structures'][] = [
                'name' => $structure->structure_name,
                'type' => $structure->structure_type,
                'corporation' => $structure->corporation_name,
                'fuel_expires' => $structure->fuel_expires,
                'days_remaining' => $structure->days_remaining,
                'remaining_hours' => $structure->remaining_hours,
                'hours_remaining' => $structure->hours_remaining,
                'blocks_30d' => $blocks30d,
                'blocks_60d' => $blocks60d,
                'blocks_90d' => $blocks90d,
            ];
            
            $report[$system]['total_blocks_30d'] += $blocks30d;
            $report[$system]['total_blocks_60d'] += $blocks60d;
            $report[$system]['total_blocks_90d'] += $blocks90d;
            
            $totalBlocksNeeded += $blocks30d;
        }
        
        return response()->json([
            'systems' => $report,
            'summary' => [
                'total_structures' => $structures->count(),
                'total_systems' => count($report),
                'total_blocks_30d' => $totalBlocksNeeded,
                'total_volume_30d' => $totalBlocksNeeded * 5, // Each fuel block is 5 m³
                'total_hauler_trips' => ceil(($totalBlocksNeeded * 5) / 60000), // 60,000 m³ = typical hauler capacity
            ],
        ]);
    }
}
