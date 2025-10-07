<?php

namespace StructureManager\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use StructureManager\Helpers\FuelCalculator;
use Carbon\Carbon;

class FuelAlertController extends Controller
{
    /**
     * Get critical fuel alerts for dashboard widget
     */
    public function getCriticalAlerts()
    {
        $structures = DB::table('corporation_structures as cs')
            ->join('universe_structures as us', 'cs.structure_id', '=', 'us.structure_id')
            ->join('invTypes as it', 'cs.type_id', '=', 'it.typeID')
            ->join('mapDenormalize as md', 'cs.system_id', '=', 'md.itemID')
            ->whereNotNull('cs.fuel_expires')
            ->whereRaw('DATEDIFF(cs.fuel_expires, NOW()) < 14') // Warning and critical only
            ->select(
                'cs.structure_id',
                'us.name as structure_name',
                'it.typeName as structure_type',
                'cs.type_id',
                'md.itemName as system_name',
                'cs.fuel_expires',
                DB::raw('DATEDIFF(cs.fuel_expires, NOW()) as days_remaining')
            )
            ->orderBy('days_remaining', 'asc')
            ->limit(10)
            ->get();
        
        // Calculate fuel blocks needed for each structure
        foreach ($structures as $structure) {
            $structure->blocks_needed = FuelCalculator::getFuelRequirement(
                $structure->type_id, 
                'weekly'
            );
            
            $structure->status = $structure->days_remaining < 7 ? 'critical' : 'warning';
        }
        
        return response()->json($structures);
    }
    
    /**
     * Generate fuel logistics report
     */
    public function getLogisticsReport()
    {
        $structures = DB::table('corporation_structures as cs')
            ->join('universe_structures as us', 'cs.structure_id', '=', 'us.structure_id')
            ->join('invTypes as it', 'cs.type_id', '=', 'it.typeID')
            ->join('mapDenormalize as md', 'cs.system_id', '=', 'md.itemID')
            ->join('corporation_infos as ci', 'cs.corporation_id', '=', 'ci.corporation_id')
            ->whereNotNull('cs.fuel_expires')
            ->select(
                'cs.structure_id',
                'us.name as structure_name',
                'it.typeName as structure_type',
                'cs.type_id',
                'md.itemName as system_name',
                'ci.name as corporation_name',
                'cs.fuel_expires',
                DB::raw('DATEDIFF(cs.fuel_expires, NOW()) as days_remaining')
            )
            ->orderBy('system_name', 'asc')
            ->orderBy('days_remaining', 'asc')
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
            
            $blocks30d = FuelCalculator::getFuelRequirement($structure->type_id, 'monthly');
            $blocks60d = $blocks30d * 2;
            $blocks90d = FuelCalculator::getFuelRequirement($structure->type_id, 'quarterly');
            
            $report[$system]['structures'][] = [
                'name' => $structure->structure_name,
                'type' => $structure->structure_type,
                'corporation' => $structure->corporation_name,
                'fuel_expires' => $structure->fuel_expires,
                'days_remaining' => $structure->days_remaining,
                'blocks_30d' => $blocks30d,
                'blocks_60d' => $blocks60d,
                'blocks_90d' => $blocks90d,
            ];
            
            $report[$system]['total_blocks_30d'] += $blocks30d;
            $report[$system]['total_blocks_60d'] += $blocks60d;
            $report[$system]['total_blocks_90d'] += $blocks90d;
            
            $totalBlocksNeeded += $blocks30d;
        }
        
        return [
            'systems' => $report,
            'summary' => [
                'total_structures' => $structures->count(),
                'total_systems' => count($report),
                'total_blocks_30d' => $totalBlocksNeeded,
                'total_volume_30d' => $totalBlocksNeeded * 5, // 5m3 per fuel block
                'total_hauler_trips' => ceil(($totalBlocksNeeded * 5) / 60000), // Assuming 60k m3 hauler
            ],
        ];
    }
}
