<?php

namespace StructureManager\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use StructureManager\Helpers\FuelCalculator;
use Carbon\Carbon;

class FuelAlertController extends Controller
{
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
        
        // Calculate fuel blocks needed for each structure
        foreach ($structures as $structure) {
            // FIXED: Pass structure_id to get actual service data
           $structure->blocks_needed = FuelCalculator::getFuelRequirement(
            $structure->type_id,
            $structure->structure_id,
            'weekly'
        );
            
            $structure->status = $structure->hours_remaining < 168 ? 'critical' : 'warning'; // 168 hours = 7 days
        }
        
        return response()->json($structures);
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
