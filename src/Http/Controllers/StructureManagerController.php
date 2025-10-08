<?php

namespace StructureManager\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use StructureManager\Models\StructureFuelHistory;
use Carbon\Carbon;

class StructureManagerController extends Controller
{
    public function index()
    {
        $corporations = DB::table('corporation_infos')
            ->select('corporation_id', 'name')
            ->orderBy('name')
            ->get();
        
        return view('structure-manager::index', compact('corporations'));
    }
    
    public function getStructuresData(Request $request)
    {
        try {
            $query = DB::table('corporation_structures as cs')
                ->join('universe_structures as us', 'cs.structure_id', '=', 'us.structure_id')
                ->join('invTypes as it', 'cs.type_id', '=', 'it.typeID')
                ->join('mapDenormalize as md', 'cs.system_id', '=', 'md.itemID')
                ->join('corporation_infos as ci', 'cs.corporation_id', '=', 'ci.corporation_id')
                ->leftJoin('corporation_structure_services as css', function($join) {
                    $join->on('cs.structure_id', '=', 'css.structure_id')
                         ->where('css.state', '=', 'online');
                })
                ->select(
                    'cs.structure_id',
                    'us.name as structure_name',
                    'it.typeName as structure_type',
                    'cs.type_id',
                    'md.itemName as system_name',
                    'md.security',
                    'ci.name as corporation_name',
                    'cs.fuel_expires',
                    'cs.state',
                    'cs.updated_at',
                    DB::raw('GROUP_CONCAT(css.name SEPARATOR ", ") as services'),
                    DB::raw('TIMESTAMPDIFF(HOUR, NOW(), cs.fuel_expires) as hours_remaining'),
                    DB::raw('FLOOR(TIMESTAMPDIFF(HOUR, NOW(), cs.fuel_expires) / 24) as days_remaining'),
                    DB::raw('MOD(TIMESTAMPDIFF(HOUR, NOW(), cs.fuel_expires), 24) as remaining_hours'),
                    DB::raw('CASE 
                        WHEN cs.fuel_expires IS NULL THEN "unknown"
                        WHEN TIMESTAMPDIFF(HOUR, NOW(), cs.fuel_expires) < 168 THEN "critical"
                        WHEN TIMESTAMPDIFF(HOUR, NOW(), cs.fuel_expires) < 336 THEN "warning"
                        WHEN TIMESTAMPDIFF(HOUR, NOW(), cs.fuel_expires) < 720 THEN "normal"
                        ELSE "good"
                    END as fuel_status')
                )
                ->groupBy(
                    'cs.structure_id',
                    'us.name',
                    'it.typeName',
                    'cs.type_id',
                    'md.itemName',
                    'md.security',
                    'ci.name',
                    'cs.fuel_expires',
                    'cs.state',
                    'cs.updated_at'
                );
            
            // Apply filters
            if ($request->has('corporation_id') && $request->corporation_id != 'all') {
                $query->where('cs.corporation_id', $request->corporation_id);
            }
            
            if ($request->has('fuel_status') && $request->fuel_status != 'all') {
                switch($request->fuel_status) {
                    case 'critical':
                        $query->whereRaw('TIMESTAMPDIFF(HOUR, NOW(), cs.fuel_expires) < 168'); // < 7 days
                        break;
                    case 'warning':
                        $query->whereRaw('TIMESTAMPDIFF(HOUR, NOW(), cs.fuel_expires) BETWEEN 168 AND 336'); // 7-14 days
                        break;
                    case 'normal':
                        $query->whereRaw('TIMESTAMPDIFF(HOUR, NOW(), cs.fuel_expires) BETWEEN 336 AND 720'); // 14-30 days
                        break;
                    case 'good':
                        $query->whereRaw('TIMESTAMPDIFF(HOUR, NOW(), cs.fuel_expires) > 720'); // > 30 days
                        break;
                }
            }
            
            $structures = $query->orderBy('hours_remaining', 'asc')->get();
            
            // Calculate consumption rates from history
            foreach ($structures as $structure) {
                $consumption = $this->calculateConsumption($structure->structure_id);
                $structure->daily_consumption = $consumption['daily'];
                $structure->weekly_consumption = $consumption['weekly'];
                $structure->monthly_consumption = $consumption['monthly'];
                
                // Estimate fuel blocks remaining based on consumption
                if ($structure->hours_remaining && $consumption['daily'] > 0) {
                    $structure->estimated_blocks = round(($structure->hours_remaining / 24) * $consumption['daily']);
                } else {
                    $structure->estimated_blocks = null;
                }
            }
            
            return response()->json(['data' => $structures]);
            
        } catch (\Exception $e) {
            \Log::error('Structure Manager - Error fetching structures data: ' . $e->getMessage());
            return response()->json([
                'error' => true,
                'message' => $e->getMessage(),
                'data' => []
            ], 500);
        }
    }
    
    public function structureDetail($id)
    {
        $structure = DB::table('corporation_structures as cs')
            ->join('universe_structures as us', 'cs.structure_id', '=', 'us.structure_id')
            ->join('invTypes as it', 'cs.type_id', '=', 'it.typeID')
            ->join('mapDenormalize as md', 'cs.system_id', '=', 'md.itemID')
            ->join('corporation_infos as ci', 'cs.corporation_id', '=', 'ci.corporation_id')
            ->where('cs.structure_id', $id)
            ->select(
                'cs.*',
                'us.name as structure_name',
                'it.typeName as structure_type',
                'md.itemName as system_name',
                'md.security',
                'ci.name as corporation_name'
            )
            ->first();
        
        if (!$structure) {
            abort(404);
        }
        
        $services = DB::table('corporation_structure_services')
            ->where('structure_id', $id)
            ->get();
        
        $fuelHistory = StructureFuelHistory::where('structure_id', $id)
            ->orderBy('created_at', 'desc')
            ->limit(30)
            ->get();
        
        $consumption = $this->calculateConsumption($id);
        
        return view('structure-manager::detail', compact('structure', 'services', 'fuelHistory', 'consumption'));
    }
    
    public function getFuelHistory($id)
    {
        $history = StructureFuelHistory::where('structure_id', $id)
            ->orderBy('created_at', 'desc')
            ->limit(90) // 3 months of daily data
            ->get();
        
        return response()->json($history);
    }
    
    public function trackFuel(Request $request)
    {
        // This method would be called by a scheduled job to track fuel changes
        $structures = DB::table('corporation_structures')
            ->whereNotNull('fuel_expires')
            ->get();
        
        foreach ($structures as $structure) {
            $lastRecord = StructureFuelHistory::where('structure_id', $structure->structure_id)
                ->orderBy('created_at', 'desc')
                ->first();
            
            // Only create new record if fuel_expires changed or it's been 24 hours
            if (!$lastRecord || 
                $lastRecord->fuel_expires != $structure->fuel_expires ||
                $lastRecord->created_at->diffInHours(now()) >= 24) {
                
                $daysRemaining = Carbon::parse($structure->fuel_expires)->diffInDays(now());
                
                $fuelUsed = null;
                $dailyConsumption = null;
                
                if ($lastRecord && $lastRecord->fuel_expires != $structure->fuel_expires) {
                    // Fuel was added
                    $daysDiff = Carbon::parse($structure->fuel_expires)->diffInDays(Carbon::parse($lastRecord->fuel_expires));
                    if ($daysDiff > 0) {
                        // Estimate blocks added (assuming 40 blocks per day for large structures)
                        $fuelUsed = $daysDiff * -40; // Negative means fuel was added
                    }
                }
                
                StructureFuelHistory::create([
                    'structure_id' => $structure->structure_id,
                    'corporation_id' => $structure->corporation_id,
                    'fuel_expires' => $structure->fuel_expires,
                    'days_remaining' => $daysRemaining,
                    'fuel_blocks_used' => $fuelUsed,
                    'daily_consumption' => $dailyConsumption,
                ]);
            }
        }
        
        return response()->json(['success' => true]);
    }
    
    private function calculateConsumption($structureId)
    {
        // Try to get actual consumption from historical data
        $analysis = \StructureManager\Services\FuelConsumptionTracker::analyzeFuelConsumption($structureId, 30);
        
        if ($analysis['status'] === 'success' && $analysis['consumption']['average_daily'] > 0) {
            return [
                'daily' => $analysis['consumption']['average_daily'],
                'weekly' => $analysis['consumption']['average_weekly'],
                'monthly' => $analysis['consumption']['average_monthly'],
                'quarterly' => $analysis['consumption']['average_monthly'] * 3,
            ];
        }
        
        // Fall back to basic estimates if no historical data
        $structure = DB::table('corporation_structures')
            ->where('structure_id', $structureId)
            ->first();
        
        if (!$structure) {
            return ['daily' => 0, 'weekly' => 0, 'monthly' => 0, 'quarterly' => 0];
        }
        
        // Use basic rates as fallback
        return [
            'daily' => \StructureManager\Helpers\FuelCalculator::getFuelRequirement($structure->type_id, 'daily'),
            'weekly' => \StructureManager\Helpers\FuelCalculator::getFuelRequirement($structure->type_id, 'weekly'),
            'monthly' => \StructureManager\Helpers\FuelCalculator::getFuelRequirement($structure->type_id, 'monthly'),
            'quarterly' => \StructureManager\Helpers\FuelCalculator::getFuelRequirement($structure->type_id, 'quarterly'),
        ];
    }

    public function getFuelAnalysis($id)
    {
        $analysis = \StructureManager\Services\FuelConsumptionTracker::analyzeFuelConsumption($id, 30);
        return response()->json($analysis);
    }
}
