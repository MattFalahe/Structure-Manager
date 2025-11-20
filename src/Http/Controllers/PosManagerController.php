<?php

namespace StructureManager\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use StructureManager\Models\StarbaseFuelHistory;
use StructureManager\Models\StarbaseFuelReserves;
use StructureManager\Models\StarbaseFuelConsumption;
use StructureManager\Helpers\PosFuelCalculator;
use Carbon\Carbon;

/**
 * Controller for POS (Player Owned Starbase) management
 * 
 * Handles viewing POSes, fuel status, alerts, and historical data
 */
class PosManagerController extends Controller
{
    /**
     * Get user's accessible corporation IDs
     */
    private function getUserCorporations()
    {
        // Get corporation IDs from user's characters
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
     * Display POS list
     */
    public function index()
    {
        // Get user's corporations
        $userCorpIds = $this->getUserCorporations();
        
        if ($userCorpIds === null) {
            // Superadmin - get all corporations with POSes
            $corporations = DB::table('corporation_infos')
                ->join('corporation_starbases', 'corporation_infos.corporation_id', '=', 'corporation_starbases.corporation_id')
                ->select('corporation_infos.corporation_id', 'corporation_infos.name')
                ->distinct()
                ->orderBy('corporation_infos.name')
                ->get();
        } else {
            // Get only user's corporations
            $corporations = DB::table('corporation_infos')
                ->whereIn('corporation_id', $userCorpIds)
                ->select('corporation_id', 'name')
                ->orderBy('name')
                ->get();
        }
        
        return view('structure-manager::pos.index', compact('corporations'));
    }
    
    /**
     * Get POSes data for AJAX
     */
    public function getPosesData(Request $request)
    {
        try {
            $userCorpIds = $this->getUserCorporations();
            
            // Build query for POSes
            $query = DB::table('corporation_starbases as cs')
                ->join('invTypes as it', 'cs.type_id', '=', 'it.typeID')
                ->join('mapDenormalize as md', 'cs.system_id', '=', 'md.itemID')
                ->join('corporation_infos as ci', 'cs.corporation_id', '=', 'ci.corporation_id')
                ->leftJoin('corporation_assets as ca', 'cs.starbase_id', '=', 'ca.item_id')
                ->where('it.groupID', 365) // Control Tower group
                ->select(
                    'cs.starbase_id',
                    'it.typeName as tower_type',
                    'cs.type_id',
                    'md.itemName as system_name',
                    'md.security',
                    'ci.name as corporation_name',
                    'cs.corporation_id',
                    'cs.state',
                    'cs.system_id',
                    'ca.name as starbase_name',
                    'ca.map_name as location_name'
                );
            
            // Filter by corporation if user is not superadmin
            if ($userCorpIds !== null) {
                $query->whereIn('cs.corporation_id', $userCorpIds);
            }
            
            // Filter by selected corporation
            if ($request->has('corporation_id') && $request->corporation_id != '') {
                $query->where('cs.corporation_id', $request->corporation_id);
            }
            
            $poses = $query->get();
            
            // Enrich with fuel data from latest history
            $enrichedPoses = $poses->map(function($pos) {
                // Get latest fuel history
                $history = StarbaseFuelHistory::where('starbase_id', $pos->starbase_id)
                    ->orderBy('created_at', 'desc')
                    ->first();
                
                if ($history) {
                    // Keep name from assets, only use history as fallback
                    if (empty($pos->starbase_name)) {
                        $pos->starbase_name = $history->starbase_name ?? 'POS-' . $pos->starbase_id;
                    }
                    
                    // Get location from history metadata if not in assets
                    if (empty($pos->location_name) && isset($history->metadata['location_name'])) {
                        $pos->location_name = $history->metadata['location_name'];
                    }
                    
                    $pos->fuel_blocks_quantity = $history->fuel_blocks_quantity;
                    $pos->fuel_days_remaining = $history->fuel_days_remaining;
                    $pos->actual_days_remaining = $history->actual_days_remaining;
                    $pos->limiting_factor = $history->limiting_factor;
                    $pos->estimated_fuel_expiry = $history->estimated_fuel_expiry;
                    
                    $pos->strontium_quantity = $history->strontium_quantity;
                    $pos->strontium_hours_available = $history->strontium_hours_available;
                    $pos->strontium_status = $history->strontium_status;
                    
                    $pos->charter_quantity = $history->charter_quantity;
                    $pos->charter_days_remaining = $history->charter_days_remaining;
                    $pos->requires_charters = $history->requires_charters;
                    
                    $pos->space_type = $history->space_type;
                    $pos->last_updated = $history->created_at;
                    
                    // Determine overall fuel status
                    if ($history->actual_days_remaining < 7) {
                        $pos->fuel_status = 'critical';
                    } elseif ($history->actual_days_remaining < 14) {
                        $pos->fuel_status = 'warning';
                    } elseif ($history->actual_days_remaining < 30) {
                        $pos->fuel_status = 'normal';
                    } else {
                        $pos->fuel_status = 'good';
                    }
                } else {
                    // No history - use fallbacks
                    if (empty($pos->starbase_name)) {
                        $pos->starbase_name = 'POS-' . $pos->starbase_id;
                    }
                    $pos->fuel_status = 'unknown';
                    $pos->last_updated = null;
                }
                
                return $pos;
            });
            
            // Return data in DataTables format
            return response()->json([
                'data' => $enrichedPoses,
            ]);
            
        } catch (\Exception $e) {
            \Log::error('PosManagerController::getPosesData error: ' . $e->getMessage());
            return response()->json([
                'data' => [],
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * Display detailed view for a single POS
     */
    public function show($starbaseId)
    {
        // Get POS basic info
        $pos = DB::table('corporation_starbases as cs')
            ->join('invTypes as it', 'cs.type_id', '=', 'it.typeID')
            ->join('mapDenormalize as md', 'cs.system_id', '=', 'md.itemID')
            ->join('corporation_infos as ci', 'cs.corporation_id', '=', 'ci.corporation_id')
            ->leftJoin('corporation_assets as ca', 'cs.starbase_id', '=', 'ca.item_id')
            ->where('cs.starbase_id', $starbaseId)
            ->select(
                'cs.*',
                'it.typeName as tower_type',
                'md.itemName as system_name',
                'md.security as system_security',
                'ci.name as corporation_name',
                'ca.name as starbase_name',
                'ca.map_name as location_name'
            )
            ->first();
        
        if (!$pos) {
            abort(404, 'POS not found');
        }
        
        // Check access
        $userCorpIds = $this->getUserCorporations();
        if ($userCorpIds !== null && !in_array($pos->corporation_id, $userCorpIds)) {
            abort(403, 'Access denied');
        }
        
        // Get latest history
        $latestHistory = StarbaseFuelHistory::where('starbase_id', $starbaseId)
            ->orderBy('created_at', 'desc')
            ->first();
        
        // Ensure starbase_name is set (from history if not in corporation_starbases)
        if (!isset($pos->starbase_name) || $pos->starbase_name === null) {
            $pos->starbase_name = $latestHistory ? $latestHistory->starbase_name : null;
        }
        
        // Get 30 days of history
        $history = StarbaseFuelHistory::where('starbase_id', $starbaseId)
            ->where('created_at', '>=', Carbon::now()->subDays(30))
            ->orderBy('created_at', 'asc')
            ->get();
        
        // Get consumption data
        $consumption = StarbaseFuelConsumption::where('starbase_id', $starbaseId)
            ->where('date', '>=', Carbon::now()->subDays(30))
            ->orderBy('date', 'asc')
            ->get();
        
        // Get recent refuels
        $refuels = StarbaseFuelReserves::where('starbase_id', $starbaseId)
            ->where('is_refuel_event', true)
            ->orderBy('refuel_detected_at', 'desc')
            ->take(10)
            ->get();
        
        // Get current reserves (all locations)
        $reserves = StarbaseFuelReserves::where('starbase_id', $starbaseId)
            ->whereIn('id', function($query) use ($starbaseId) {
                $query->select(DB::raw('MAX(id)'))
                    ->from('starbase_fuel_reserves')
                    ->where('starbase_id', $starbaseId)
                    ->groupBy('location_id', 'resource_type_id');
            })
            ->where('reserve_quantity', '>', 0)
            ->get();
        
        // Get fuel consumption rates
        $rates = null;
        if ($latestHistory) {
            $rates = PosFuelCalculator::getFuelConsumptionRate(
                $pos->type_id, 
                $pos->system_security
            );
            
            // Get bay capacities from SDE attributes
            // Strontium Bay is a specific attribute in dgmTypeAttributes
            $strontiumBayCapacity = DB::table('dgmTypeAttributes')
                ->where('typeID', $pos->type_id)
                ->where('attributeID', 1233) // Strontium Bay attribute
                ->selectRaw('COALESCE(valueFloat, valueInt) as capacity')
                ->value('capacity');
            
            // Get general cargo capacity for fuel blocks and charters
            $towerCapacity = DB::table('invTypes')
                ->where('typeID', $pos->type_id)
                ->value('capacity');
            
            if ($towerCapacity) {
                // Fuel blocks and charters share the same bay (total capacity)
                // Fuel blocks: 5 m³ each
                // Charters: 1 m³ each
                // They compete for the same space!
                $rates['fuel_bay_capacity'] = floor($towerCapacity / 5);  // Max fuel blocks if bay was full
                $rates['charter_bay_capacity'] = $towerCapacity;          // Max charters if bay was full
                $rates['shared_bay_capacity'] = $towerCapacity;           // Actual shared bay size in m³
                
                // Calculate current bay usage percentages
                if ($latestHistory) {
                    // Calculate m³ used by each resource
                    $fuelM3Used = $latestHistory->fuel_blocks_quantity * 5;  // 5 m³ per block
                    $charterM3Used = $latestHistory->charter_quantity * 1;    // 1 m³ per charter
                    $totalM3Used = $fuelM3Used + $charterM3Used;
                    
                    $rates['fuel_bay_usage_pct'] = $rates['fuel_bay_capacity'] > 0 
                        ? min(100, ($latestHistory->fuel_blocks_quantity / $rates['fuel_bay_capacity']) * 100)
                        : 0;
                    $rates['charter_bay_usage_pct'] = $rates['charter_bay_capacity'] > 0 && $latestHistory->charter_quantity
                        ? min(100, ($latestHistory->charter_quantity / $rates['charter_bay_capacity']) * 100)
                        : 0;
                    $rates['shared_bay_usage_pct'] = min(100, ($totalM3Used / $towerCapacity) * 100);
                    $rates['shared_bay_m3_used'] = $totalM3Used;
                }
            }
            
            // Use Strontium Bay attribute if available
            if ($strontiumBayCapacity) {
                // Strontium Bay attribute is in m³, need to convert to units
                // Strontium Clathrates volume: 3 m³ per unit
                $rates['strontium_bay_capacity_m3'] = $strontiumBayCapacity;
                $rates['strontium_bay_capacity'] = floor($strontiumBayCapacity / 3); // Convert m³ to units
            } else {
                // Fallback if attribute not found - cannot calculate strontium bay from cargo capacity
                // These are separate bays in POS towers
                $rates['strontium_bay_capacity_m3'] = 0;
                $rates['strontium_bay_capacity'] = 0;
            }
            
            // Calculate strontium bay usage and good level
            if ($latestHistory && $rates['strontium_bay_capacity'] > 0) {
                $rates['strontium_bay_usage_pct'] = min(100, ($latestHistory->strontium_quantity / $rates['strontium_bay_capacity']) * 100);
                
                // Calculate "good level" - 24 hours of strontium
                $strontiumGoodLevel = $rates['strontium_for_reinforced'] * 24; // 24 hours worth
                $rates['strontium_good_level'] = $strontiumGoodLevel;
                $rates['strontium_good_level_pct'] = min(100, ($latestHistory->strontium_quantity / $strontiumGoodLevel) * 100);
            } else {
                $rates['strontium_bay_usage_pct'] = 0;
                $rates['strontium_good_level_pct'] = 0;
            }
        }
        
        return view('structure-manager::pos.detail', compact(
            'pos',
            'latestHistory',
            'history',
            'consumption',
            'refuels',
            'reserves',
            'rates'
        ));
    }
    
    /**
     * Get critical POSes for alerts widget
     */
    public function getCriticalAlerts()
    {
        $userCorpIds = $this->getUserCorporations();
        
        // Get POSes with critical fuel (< 7 days)
        $criticalFuel = StarbaseFuelHistory::whereIn('id', function($query) {
                $query->select(DB::raw('MAX(id)'))
                    ->from('starbase_fuel_history')
                    ->groupBy('starbase_id');
            })
            ->where('actual_days_remaining', '<', 7)
            ->when($userCorpIds !== null, function($query) use ($userCorpIds) {
                return $query->whereIn('corporation_id', $userCorpIds);
            })
            ->orderBy('actual_days_remaining', 'asc')
            ->get();
        
        // Get POSes with critical strontium (< 6 hours)
        $criticalStrontium = StarbaseFuelHistory::whereIn('id', function($query) {
                $query->select(DB::raw('MAX(id)'))
                    ->from('starbase_fuel_history')
                    ->groupBy('starbase_id');
            })
            ->where('strontium_hours_available', '<', 6)
            ->when($userCorpIds !== null, function($query) use ($userCorpIds) {
                return $query->whereIn('corporation_id', $userCorpIds);
            })
            ->orderBy('strontium_hours_available', 'asc')
            ->get();
        
        // Get POSes with low charters (< 7 days, high-sec only)
        $criticalCharters = StarbaseFuelHistory::whereIn('id', function($query) {
                $query->select(DB::raw('MAX(id)'))
                    ->from('starbase_fuel_history')
                    ->groupBy('starbase_id');
            })
            ->where('requires_charters', true)
            ->where('charter_days_remaining', '<', 7)
            ->when($userCorpIds !== null, function($query) use ($userCorpIds) {
                return $query->whereIn('corporation_id', $userCorpIds);
            })
            ->orderBy('charter_days_remaining', 'asc')
            ->get();
        
        return response()->json([
            'critical_fuel' => $criticalFuel,
            'critical_strontium' => $criticalStrontium,
            'critical_charters' => $criticalCharters,
        ]);
    }
}
