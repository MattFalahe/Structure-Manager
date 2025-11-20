<?php

namespace StructureManager\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use StructureManager\Models\StructureFuelReserves;
use StructureManager\Models\StarbaseFuelReserves;
use StructureManager\Models\StructureManagerSettings;
use StructureManager\Helpers\PosFuelCalculator;
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
     * Get excluded hangars from settings
     */
    private function getExcludedHangars()
    {
        $excluded = StructureManagerSettings::get('excluded_hangars', []);
        
        // If it's a string (comma-separated), convert to array
        if (is_string($excluded)) {
            $excluded = array_filter(array_map('trim', explode(',', $excluded)));
        }
        
        return is_array($excluded) ? $excluded : [];
    }
    
    /**
     * Check if a location flag should be excluded
     */
    private function isHangarExcluded($locationFlag)
    {
        $excludedHangars = $this->getExcludedHangars();
        
        if (empty($excludedHangars)) {
            return false;
        }
        
        // Extract division number from location flag (e.g., "CorpSAG3" -> "3")
        if (preg_match('/CorpSAG(\d+)/', $locationFlag, $matches)) {
            $divisionNumber = $matches[1];
            return in_array($divisionNumber, $excludedHangars);
        }
        
        return false;
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
     * UPDATED: Now includes POS strontium and charters
     */
    public function getReservesData()
    {
        $userCorps = $this->getUserCorporations();
        
        // Get Structure Reserves (Upwell structures)
        $structureReserves = $this->getStructureReserves($userCorps);
        
        // Get POS Reserves (Control Towers)
        $posReserves = $this->getPosReserves($userCorps);
        
        // Merge the data
        $systemReserves = $this->mergeReserveData($structureReserves, $posReserves);
        
        return response()->json($systemReserves);
    }
    
    /**
     * Get Upwell structure reserves
     */
    private function getStructureReserves($userCorps)
    {
        $query = DB::table('corporation_structures as cs')
            ->join('universe_structures as us', 'cs.structure_id', '=', 'us.structure_id')
            ->join('invTypes as it', 'cs.type_id', '=', 'it.typeID')
            ->join('mapDenormalize as md', 'cs.system_id', '=', 'md.itemID')
            ->join('corporation_infos as ci', 'cs.corporation_id', '=', 'ci.corporation_id')
            ->whereNotNull('cs.fuel_expires');
        
        if ($userCorps !== null) {
            $query->whereIn('cs.corporation_id', $userCorps);
        }
        
        $structures = $query->select(
                'cs.structure_id',
                'cs.corporation_id',
                'cs.type_id',
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
            
            // Filter out excluded hangars
            $reserves = $reserves->filter(function($reserve) {
                return !$this->isHangarExcluded($reserve->location_flag);
            });
            
            // Separate fuel blocks and magmatic gas
            $fuelReserves = $reserves->where('fuel_type_id', '!=', self::MAGMATIC_GAS_TYPE_ID);
            $gasReserves = $reserves->where('fuel_type_id', '=', self::MAGMATIC_GAS_TYPE_ID);
            
            $totalFuelBlocks = $fuelReserves->sum('reserve_quantity');
            $totalGas = $gasReserves->sum('reserve_quantity');
            
            if ($totalFuelBlocks > 0 || $totalGas > 0) {
                $system = $structure->system_name;
                $isMetenox = $structure->type_id == self::METENOX_TYPE_ID;
                
                if (!isset($systemReserves[$system])) {
                    $systemReserves[$system] = [
                        'security' => $structure->security,
                        'structures' => [],
                        'pos_towers' => [],
                        'total_reserves' => 0,
                    ];
                }
                
                $divisionNames = DB::table('corporation_divisions')
                    ->where('corporation_id', $structure->corporation_id)
                    ->where('type', 'hangar')
                    ->pluck('name', 'division')
                    ->toArray();
                
                $reserveDetails = [];
                foreach ($reserves as $reserve) {
                    preg_match('/CorpSAG(\d+)/', $reserve->location_flag, $matches);
                    $divisionNumber = isset($matches[1]) ? (int)$matches[1] : 0;
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
                    'total_reserves' => $totalFuelBlocks,
                    'total_gas' => $totalGas,
                    'is_metenox' => $isMetenox,
                    'reserves' => $reserveDetails,
                    'asset_type' => 'structure',
                ];
                
                $systemReserves[$system]['total_reserves'] += $totalFuelBlocks;
            }
        }
        
        return $systemReserves;
    }
    
    /**
     * Get POS (Control Tower) reserves
     */
    private function getPosReserves($userCorps)
    {
        $query = DB::table('corporation_starbases as cs')
            ->join('invTypes as it', 'cs.type_id', '=', 'it.typeID')
            ->join('mapDenormalize as md', 'cs.system_id', '=', 'md.itemID')
            ->join('corporation_infos as ci', 'cs.corporation_id', '=', 'ci.corporation_id')
            ->where('cs.state', '!=', 0); // Exclude offline POSes
        
        if ($userCorps !== null) {
            $query->whereIn('cs.corporation_id', $userCorps);
        }
        
        $posList = $query->select(
                'cs.starbase_id',
                'cs.corporation_id',
                'cs.type_id',
                'it.typeName as tower_type',
                'md.itemName as system_name',
                'md.security',
                'ci.name as corporation_name'
            )
            ->orderBy('system_name', 'asc')
            ->get();
        
        $systemReserves = [];
        
        foreach ($posList as $pos) {
            // Get POS reserves
            $reserves = StarbaseFuelReserves::where('starbase_id', $pos->starbase_id)
                ->orderBy('created_at', 'desc')
                ->get()
                ->groupBy('resource_type_id')
                ->map(function($group) {
                    return $group->first(); // Get most recent entry for each resource type
                });
            
            // Filter out excluded hangars
            $reserves = $reserves->filter(function($reserve) {
                return !$this->isHangarExcluded($reserve->location_flag);
            });
            
            // Separate by category
            $fuelReserves = $reserves->where('resource_category', 'fuel');
            $strontiumReserves = $reserves->where('resource_category', 'strontium');
            $charterReserves = $reserves->where('resource_category', 'charter');
            
            $totalFuel = $fuelReserves->sum('reserve_quantity');
            $totalStrontium = $strontiumReserves->sum('reserve_quantity');
            $totalCharters = $charterReserves->sum('reserve_quantity');
            
            if ($totalFuel > 0 || $totalStrontium > 0 || $totalCharters > 0) {
                $system = $pos->system_name;
                
                if (!isset($systemReserves[$system])) {
                    $systemReserves[$system] = [
                        'security' => $pos->security,
                        'structures' => [],
                        'pos_towers' => [],
                        'total_reserves' => 0,
                    ];
                }
                
                $divisionNames = DB::table('corporation_divisions')
                    ->where('corporation_id', $pos->corporation_id)
                    ->where('type', 'hangar')
                    ->pluck('name', 'division')
                    ->toArray();
                
                $reserveDetails = [];
                foreach ($reserves as $reserve) {
                    preg_match('/CorpSAG(\d+)/', $reserve->location_flag, $matches);
                    $divisionNumber = isset($matches[1]) ? (int)$matches[1] : 0;
                    $divisionName = $divisionNames[$divisionNumber] ?? "Division {$divisionNumber}";
                    
                    $reserveDetails[] = [
                        'location' => $reserve->location_flag,
                        'division_name' => $divisionName,
                        'quantity' => $reserve->reserve_quantity,
                        'resource_type_id' => $reserve->resource_type_id,
                        'resource_category' => $reserve->resource_category,
                    ];
                }
                
                $systemReserves[$system]['pos_towers'][] = [
                    'starbase_id' => $pos->starbase_id,
                    'name' => $pos->tower_type,
                    'type' => $pos->tower_type,
                    'corporation' => $pos->corporation_name,
                    'total_fuel' => $totalFuel,
                    'total_strontium' => $totalStrontium,
                    'total_charters' => $totalCharters,
                    'reserves' => $reserveDetails,
                    'asset_type' => 'pos',
                ];
                
                $systemReserves[$system]['total_reserves'] += $totalFuel;
            }
        }
        
        return $systemReserves;
    }
    
    /**
     * Merge structure and POS reserves data
     */
    private function mergeReserveData($structureReserves, $posReserves)
    {
        $merged = $structureReserves;
        
        foreach ($posReserves as $system => $posData) {
            if (isset($merged[$system])) {
                // System exists, merge POS data
                $merged[$system]['pos_towers'] = array_merge(
                    $merged[$system]['pos_towers'] ?? [],
                    $posData['pos_towers']
                );
                $merged[$system]['total_reserves'] += $posData['total_reserves'];
            } else {
                // New system, add it
                $merged[$system] = $posData;
            }
        }
        
        return $merged;
    }
    
    /**
     * Get refuel events history
     * UPDATED: Now includes POS refuel events
     */
    public function getRefuelHistory($days = 30)
    {
        $userCorps = $this->getUserCorporations();
        
        // Get structure refuel events
        $structureEvents = $this->getStructureRefuelEvents($days, $userCorps);
        
        // Get POS refuel events
        $posEvents = $this->getPosRefuelEvents($days, $userCorps);
        
        // Merge and sort by timestamp
        $allEvents = array_merge($structureEvents, $posEvents);
        usort($allEvents, function($a, $b) {
            return strtotime($b['timestamp']) - strtotime($a['timestamp']);
        });
        
        return response()->json($allEvents);
    }
    
    /**
     * Get structure refuel events
     */
    private function getStructureRefuelEvents($days, $userCorps)
    {
        $query = StructureFuelReserves::where('is_refuel_event', true)
            ->where('created_at', '>=', Carbon::now()->subDays($days))
            ->orderBy('created_at', 'desc');
        
        if ($userCorps !== null) {
            $query->whereIn('corporation_id', $userCorps);
        }
        
        $events = $query->get();
        
        $eventData = [];
        foreach ($events as $event) {
            // Skip excluded hangars
            if ($this->isHangarExcluded($event->location_flag)) {
                continue;
            }
            
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
                'is_gas' => $event->fuel_type_id == self::MAGMATIC_GAS_TYPE_ID,
                'asset_type' => 'structure',
            ];
        }
        
        return $eventData;
    }
    
    /**
     * Get POS refuel events
     */
    private function getPosRefuelEvents($days, $userCorps)
    {
        $query = StarbaseFuelReserves::where('is_refuel_event', true)
            ->where('created_at', '>=', Carbon::now()->subDays($days))
            ->orderBy('created_at', 'desc');
        
        if ($userCorps !== null) {
            $query->whereIn('corporation_id', $userCorps);
        }
        
        $events = $query->get();
        
        $eventData = [];
        foreach ($events as $event) {
            // Skip excluded hangars
            if ($this->isHangarExcluded($event->location_flag)) {
                continue;
            }
            
            $pos = DB::table('corporation_starbases as cs')
                ->join('invTypes as it', 'cs.type_id', '=', 'it.typeID')
                ->join('mapDenormalize as md', 'cs.system_id', '=', 'md.itemID')
                ->where('cs.starbase_id', $event->starbase_id)
                ->select('it.typeName as tower_type', 'md.itemName as system_name')
                ->first();
            
            $eventData[] = [
                'timestamp' => $event->created_at,
                'starbase_id' => $event->starbase_id,
                'structure_name' => $pos->tower_type ?? 'Unknown POS',
                'system_name' => $pos->system_name ?? 'Unknown',
                'blocks_moved' => abs($event->quantity_change),
                'from_location' => $event->location_flag,
                'resource_type_id' => $event->resource_type_id,
                'resource_category' => $event->resource_category,
                'asset_type' => 'pos',
            ];
        }
        
        return $eventData;
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
    
    /**
     * Get reserve movements for a specific POS
     */
    public function getPosReserveHistory($starbaseId)
    {
        $history = StarbaseFuelReserves::where('starbase_id', $starbaseId)
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();
        
        return response()->json($history);
    }
}
