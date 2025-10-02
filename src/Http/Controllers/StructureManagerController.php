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
                'md.itemName as system_name',
                'md.security',
                'ci.name as corporation_name',
                'cs.fuel_expires',
                'cs.state',
                'cs.updated_at',
                DB::raw('GROUP_CONCAT(css.name SEPARATOR ", ") as services'),
                DB::raw('DATEDIFF(cs.fuel_expires, NOW()) as days_remaining'),
                DB::raw('CASE 
                    WHEN cs.fuel_expires IS NULL THEN "unknown"
                    WHEN DATEDIFF(cs.fuel_expires, NOW()) < 7 THEN "critical"
                    WHEN DATEDIFF(cs.fuel_expires, NOW()) < 14 THEN "warning"
                    WHEN DATEDIFF(cs.fuel_expires, NOW()) < 30 THEN "normal"
                    ELSE "good"
                END as fuel_status')
            )
            ->groupBy(
                'cs.structure_id',
                'us.name',
                'it.typeName',
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
                    $query->whereRaw('DATEDIFF(cs.fuel_expires, NOW()) < 7');
                    break;
                case 'warning':
                    $query->whereRaw('DATEDIFF(cs.fuel_expires, NOW()) BETWEEN 7 AND 14');
                    break;
                case 'normal':
                    $query->whereRaw('DATEDIFF(cs.fuel_expires, NOW()) BETWEEN 14 AND 30');
                    break;
                case 'good':
                    $query->whereRaw('DATEDIFF(cs.fuel_expires, NOW()) > 30');
                    break;
            }
        }
        
        $structures = $query->orderBy('days_remaining', 'asc')->get();
        
        // Calculate consumption rates from history
        foreach ($structures as $structure) {
            $consumption = $this->calculateConsumption($structure->structure_id);
            $structure->daily_consumption = $consumption['daily'];
            $structure->weekly_consumption = $consumption['weekly'];
            $structure->monthly_consumption = $consumption['monthly'];
            
            // Estimate fuel blocks remaining based on consumption
            if ($structure->days_remaining && $consumption['daily'] > 0) {
                $structure->estimated_blocks = round($structure->days_remaining * $consumption['daily']);
            } else {
                $structure->estimated_blocks = null;
            }
        }
        
        return response()->json(['data' => $structures]);
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
        $history = StructureFuelHistory::where('structure_id', $structureId)
            ->where('created_at', '>=', Carbon::now()->subDays(30))
            ->orderBy('created_at', 'desc')
            ->get();
        
        $daily = 0;
        $weekly = 0;
        $monthly = 0;
        
        if ($history->count() >= 2) {
            // Calculate based on fuel_expires changes
            $latest = $history->first();
            $oldest = $history->last();
            
            if ($latest->fuel_expires && $oldest->fuel_expires) {
                $daysDiff = Carbon::parse($latest->created_at)->diffInDays(Carbon::parse($oldest->created_at));
                $fuelDiff = Carbon::parse($oldest->fuel_expires)->diffInDays(Carbon::parse($latest->fuel_expires));
                
                if ($daysDiff > 0) {
                    // Estimate daily consumption (assuming 40 blocks per day for large, 20 for medium)
                    $daily = 40; // Default, would need to check structure type
                    $weekly = $daily * 7;
                    $monthly = $daily * 30;
                }
            }
        }
        
        return [
            'daily' => $daily,
            'weekly' => $weekly,
            'monthly' => $monthly,
            'quarterly' => $monthly * 3,
        ];
    }
}
