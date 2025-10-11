<?php

namespace StructureManager\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use StructureManager\Models\StructureFuelReserves;
use Carbon\Carbon;

class FuelReserveController extends Controller
{
    /**
     * Magmatic Gas type ID
     */
    const MAGMATIC_GAS_TYPE_ID = 81143;
    
    /**
     * Metenox Moon Drill type ID
     */
    const METENOX_TYPE_ID = 81826;
    
    /**
     * Get user's accessible corporation IDs
     */
    private function getUserCorporations()
    {
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
     * Show reserve management view
     */
    public function index()
    {
        return view('structure-manager::reserves');
    }
    
    /**
     * Get reserves data by system
     * UPDATED: Now includes magmatic gas for Metenox structures
     */
    public function getReservesData()
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
                'cs.corporation_id',
                'cs.type_id',  // ← ADD THIS for Metenox detection
                'us.name as structure_name',
                'it.typeName as structure_type',
                'md.itemName as system_name',
                'md.security',
                'ci.name as corporation_name'
            )
            ->orderBy('system_name', 'asc')
            ->get();
        
        $systemReserves = [];
        
        foreach ($structures as $structure) {
            $reserves = StructureFuelReserves::getCurrentReserves($structure->structure_id);
            
            // Separate fuel blocks and magmatic gas
            $fuelReserves = $reserves->where('fuel_type_id', '!=', self::MAGMATIC_GAS_TYPE_ID);
            $gasReserves = $reserves->where('fuel_type_id', '=', self::MAGMATIC_GAS_TYPE_ID);
            
            $totalFuelBlocks = $fuelReserves->sum('reserve_quantity');
            $totalGas = $gasReserves->sum('reserve_quantity');
            
            // Only include structures that have reserves (either fuel or gas)
            if ($totalFuelBlocks > 0 || $totalGas > 0) {
                $system = $structure->system_name;
                $isMetenox = $structure->type_id == self::METENOX_TYPE_ID;
                
                if (!isset($systemReserves[$system])) {
                    $systemReserves[$system] = [
                        'security' => $structure->security,
                        'structures' => [],
                        'total_reserves' => 0,
                    ];
                }
                
                // Get division names for this corporation
                $divisionNames = DB::table('corporation_divisions')
                    ->where('corporation_id', $structure->corporation_id)
                    ->where('type', 'hangar')
                    ->pluck('name', 'division')
                    ->toArray();
                
                $reserveDetails = [];
                foreach ($reserves as $reserve) {
                    // Extract division number from location_flag (e.g., "CorpSAG3" -> 3)
                    preg_match('/CorpSAG(\d+)/', $reserve->location_flag, $matches);
                    $divisionNumber = isset($matches[1]) ? (int)$matches[1] : 0;
                    
                    // Get custom division name or fall back to default
                    $divisionName = $divisionNames[$divisionNumber] ?? "Division {$divisionNumber}";
                    
                    $reserveDetails[] = [
                        'location' => $reserve->location_flag,
                        'division_name' => $divisionName,
                        'quantity' => $reserve->reserve_quantity,
                        'fuel_type_id' => $reserve->fuel_type_id,
                    ];
                }
                
                $systemReserves[$system]['structures'][] = [
                    'structure_id' => $structure->structure_id,
                    'name' => $structure->structure_name,
                    'type' => $structure->structure_type,
                    'corporation' => $structure->corporation_name,
                    'total_reserves' => $totalFuelBlocks,  // Only fuel blocks for totals
                    'total_gas' => $totalGas,  // NEW: Gas total
                    'is_metenox' => $isMetenox,  // NEW: Flag for frontend
                    'reserves' => $reserveDetails,
                ];
                
                $systemReserves[$system]['total_reserves'] += $totalFuelBlocks;
            }
        }
        
        return response()->json($systemReserves);
    }
    
    /**
     * Get refuel events history
     * UPDATED: Now includes magmatic gas events
     */
    public function getRefuelHistory($days = 30)
    {
        $query = StructureFuelReserves::where('is_refuel_event', true)
            ->where('created_at', '>=', Carbon::now()->subDays($days))
            ->orderBy('created_at', 'desc');
        
        // Filter by user's corporations
        $userCorps = $this->getUserCorporations();
        if ($userCorps !== null) {
            $query->whereIn('corporation_id', $userCorps);
        }
        
        $events = $query->get();
        
        $eventData = [];
        foreach ($events as $event) {
            $structure = DB::table('corporation_structures as cs')
                ->join('universe_structures as us', 'cs.structure_id', '=', 'us.structure_id')
                ->join('mapDenormalize as md', 'cs.system_id', '=', 'md.itemID')
                ->where('cs.structure_id', $event->structure_id)
                ->select('us.name as structure_name', 'md.itemName as system_name')
                ->first();
            
            $eventData[] = [
                'timestamp' => $event->created_at,
                'structure_id' => $event->structure_id,
                'structure_name' => $structure->structure_name ?? 'Unknown',
                'system_name' => $structure->system_name ?? 'Unknown',
                'blocks_moved' => abs($event->quantity_change),
                'from_location' => $event->location_flag,
                'fuel_type_id' => $event->fuel_type_id,
                'is_gas' => $event->fuel_type_id == self::MAGMATIC_GAS_TYPE_ID,  // NEW: Flag for frontend
            ];
        }
        
        return response()->json($eventData);
    }
    
    /**
     * Get reserve movements for a specific structure
     */
    public function getStructureReserveHistory($structureId)
    {
        $history = StructureFuelReserves::where('structure_id', $structureId)
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();
        
        return response()->json($history);
    }
}
