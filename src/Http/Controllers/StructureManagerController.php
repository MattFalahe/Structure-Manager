<?php

namespace StructureManager\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use StructureManager\Models\StructureFuelHistory;
use Carbon\Carbon;

class StructureManagerController extends Controller
{
    /**
     * Get user's accessible corporation IDs
     *
     * Returns null when the user has explicit structure-manager.admin permission
     * (full cross-corporation access). Otherwise returns an array of corp IDs the
     * user's linked characters belong to, which may be empty.
     */
    private function getUserCorporations()
    {
        // SECURITY: Only users with explicit admin permission get cross-corp access.
        // Previously an empty query (user with no linked characters) was treated as
        // superadmin, which was a privilege-escalation path.
        if (auth()->user() && auth()->user()->can('structure-manager.admin')) {
            return null;
        }

        return DB::table('refresh_tokens')
            ->join('character_affiliations', 'refresh_tokens.character_id', '=', 'character_affiliations.character_id')
            ->where('refresh_tokens.user_id', auth()->id())
            ->whereNull('refresh_tokens.deleted_at')
            ->pluck('character_affiliations.corporation_id')
            ->unique()
            ->filter()
            ->toArray();
    }

    /**
     * The corporations the CURRENT VIEW should be filtered to.
     *
     * Distinct from getUserCorporations() — that reports actual access
     * RIGHTS (admin = null = everything) and backs requireCorporationAccess().
     * This reports the current VIEW SCOPE: it defaults to the user's own
     * corporations even for admins, so an admin landing on a list page
     * sees their own corp(s) first instead of every corp on the install.
     *
     *   ?scope=mine     (default) -> user's own corporations
     *   ?scope=all                -> null (all corps) — admins only
     *   ?scope=<corpId>           -> that single corp (admins: any;
     *                                others: only their own, else own)
     *
     * Returns null only for an admin who explicitly asked for scope=all.
     */
    private function getScopedCorporations()
    {
        $user    = auth()->user();
        $isAdmin = $user && $user->can('structure-manager.admin');

        $ownCorps = DB::table('refresh_tokens')
            ->join('character_affiliations', 'refresh_tokens.character_id', '=', 'character_affiliations.character_id')
            ->where('refresh_tokens.user_id', auth()->id())
            ->whereNull('refresh_tokens.deleted_at')
            ->pluck('character_affiliations.corporation_id')
            ->unique()
            ->filter()
            ->values()
            ->toArray();

        $scope = (string) request()->query('scope', 'mine');

        if ($scope === 'all') {
            return $isAdmin ? null : $ownCorps;
        }

        if ($scope !== 'mine' && ctype_digit($scope)) {
            $corpId = (int) $scope;
            if ($isAdmin || in_array($corpId, $ownCorps)) {
                return [$corpId];
            }
            return $ownCorps; // non-admin asked for a corp that isn't theirs
        }

        return $ownCorps; // 'mine' / default
    }

    /**
     * Abort 403 if the given corporation_id is not accessible to the current user.
     * Admin (null = full access) always passes.
     */
    private function requireCorporationAccess($corporationId)
    {
        $userCorps = $this->getUserCorporations();
        if ($userCorps !== null && !in_array($corporationId, $userCorps)) {
            abort(403, 'Access denied');
        }
    }
    
    public function index()
    {
        // Get corporations the user has access to
        $userCorpIds = $this->getUserCorporations();
        
        if ($userCorpIds === null) {
            // User has no specific corporations (superadmin), get all corporations with structures
            $corporations = DB::table('corporation_infos')
                ->join('corporation_structures', 'corporation_infos.corporation_id', '=', 'corporation_structures.corporation_id')
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
        
        return view('structure-manager::index', compact('corporations'));
    }
    
    public function getStructuresData(Request $request)
    {
        try {
            // Corp scope for THIS view — defaults to the user's own corps
            // (admins included); ?scope=all / ?scope=<corpId> override.
            $userCorpIds = $this->getScopedCorporations();

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
                    // 3-tier model from FuelThresholds (locked at 7d/14d).
                    // NOTE: this CASE uses cs.fuel_expires which only knows
                    // about fuel BLOCKS — for Metenoxes the gas may be the
                    // limiting factor and run out sooner. We override below
                    // (after fetch) by checking structure_fuel_history for
                    // any Metenoxes and replacing the status with the gas-
                    // limited value when applicable.
                    DB::raw('CASE
                        WHEN cs.fuel_expires IS NULL THEN "unknown"
                        WHEN TIMESTAMPDIFF(HOUR, NOW(), cs.fuel_expires) < ' . \StructureManager\Helpers\FuelThresholds::upwellFuelCriticalHours() . ' THEN "critical"
                        WHEN TIMESTAMPDIFF(HOUR, NOW(), cs.fuel_expires) < ' . \StructureManager\Helpers\FuelThresholds::upwellFuelWarningHours() . ' THEN "warning"
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
            
            // Scope filter — own corps by default, single corp or all
            // depending on ?scope (resolved by getScopedCorporations()).
            // null = admin explicitly chose scope=all.
            if ($userCorpIds !== null) {
                $query->whereIn('cs.corporation_id', $userCorpIds);
            }

            // Hide structures whose ESI data is stale (corp removed its
            // token, so SeAT can no longer refresh them). Configurable
            // via the stale_structure_threshold_days setting; null cutoff
            // = feature disabled.
            $staleCutoff = \StructureManager\Helpers\FuelThresholds::staleStructureCutoff();
            if ($staleCutoff !== null) {
                $query->where('cs.updated_at', '>=', $staleCutoff);
            }

            // Apply fuel status filter — 3-tier model from FuelThresholds.
            if ($request->has('fuel_status') && $request->fuel_status != 'all') {
                $critHrs = \StructureManager\Helpers\FuelThresholds::upwellFuelCriticalHours();
                $warnHrs = \StructureManager\Helpers\FuelThresholds::upwellFuelWarningHours();
                switch($request->fuel_status) {
                    case 'critical':
                        $query->whereRaw('TIMESTAMPDIFF(HOUR, NOW(), cs.fuel_expires) < ?', [$critHrs]);
                        break;
                    case 'warning':
                        $query->whereRaw('TIMESTAMPDIFF(HOUR, NOW(), cs.fuel_expires) BETWEEN ? AND ?', [$critHrs, $warnHrs]);
                        break;
                    case 'good':
                        $query->whereRaw('TIMESTAMPDIFF(HOUR, NOW(), cs.fuel_expires) > ?', [$warnHrs]);
                        break;
                }
            }
            
            $structures = $query->orderBy('hours_remaining', 'asc')->get();
            
            // Calculate consumption rates - NOW ALWAYS USES SERVICE-BASED CALCULATION
            foreach ($structures as $structure) {
                $consumption = $this->calculateConsumption($structure->structure_id, $structure->type_id);
                $structure->daily_consumption = $consumption['daily'];
                $structure->weekly_consumption = $consumption['weekly'];
                $structure->monthly_consumption = $consumption['monthly'];
                
                // Add Metenox data if applicable
                if ($structure->type_id == 81826) { // Metenox type ID
                    $metenoxCalc = \StructureManager\Helpers\FuelCalculator::calculateFromActiveServices($structure->structure_id);
                    if (isset($metenoxCalc['magmatic_gas']) && isset($metenoxCalc['fuel_blocks'])) {
                        $fuelDays = $metenoxCalc['fuel_blocks']['days_remaining'] ?? 0;
                        $gasDays  = $metenoxCalc['magmatic_gas']['days_remaining'] ?? 0;
                        $limitingFactor = $metenoxCalc['limiting_factor'] ?? 'unknown';

                        $structure->metenox_data = [
                            'fuel_blocks_days'  => $fuelDays,
                            'magmatic_gas_days' => $gasDays,
                            'limiting_factor'   => $limitingFactor,
                        ];

                        // CRITICAL: Override fuel_status / hours_remaining /
                        // days_remaining with the LIMITING factor's value.
                        // The SQL CASE above only knows about fuel BLOCKS via
                        // cs.fuel_expires; for a Metenox where gas runs out
                        // first, the list view would otherwise show the
                        // structure as "good" while it's actually critical
                        // on gas. Match the value that NotifyUpwellLowFuel
                        // and the webhook embed compute.
                        $actualDays = min($fuelDays, $gasDays);
                        $structure->days_remaining   = floor($actualDays);
                        $structure->hours_remaining  = (int) round($actualDays * 24);
                        $structure->remaining_hours  = (int) round(($actualDays - floor($actualDays)) * 24);
                        $thresholds = \StructureManager\Helpers\FuelThresholds::class;
                        if ($actualDays < $thresholds::UPWELL_FUEL_CRITICAL_DAYS) {
                            $structure->fuel_status = 'critical';
                        } elseif ($actualDays < $thresholds::UPWELL_FUEL_WARNING_DAYS) {
                            $structure->fuel_status = 'warning';
                        } else {
                            $structure->fuel_status = 'good';
                        }
                    }
                }
                
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
                'message' => 'Failed to load structures data',
                'debug' => config('app.debug') ? $e->getMessage() : null,
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

        // SECURITY: scope check - user must have access to this structure's corporation
        $this->requireCorporationAccess($structure->corporation_id);

        $services = DB::table('corporation_structure_services')
            ->where('structure_id', $id)
            ->get();
        
        // Eager-load candidates so the detail view can render the
        // Tier 2 forensics row without N+1 queries. The relation is
        // empty for non-withdrawal events, so the load is cheap.
        $fuelHistory = StructureFuelHistory::where('structure_id', $id)
            ->with('candidates')
            ->orderBy('created_at', 'desc')
            ->limit(30)
            ->get();
        
        // Get service-based consumption for display
        $consumption = $this->calculateConsumption($id, $structure->type_id);
        
        // For Metenox, ensure we have the full consumption data
        if ($structure->type_id == 81826) { // Metenox type ID
            $consumptionData = \StructureManager\Helpers\FuelCalculator::calculateFromActiveServices($id);
            if ($consumptionData['method'] === 'metenox_drill') {
                $consumption = $consumptionData;
            }
        }
        
        // Get historical analysis for trends/anomalies (optional - for detail page only)
        $historicalAnalysis = null;
        try {
            $analysis = \StructureManager\Services\FuelConsumptionTracker::analyzeFuelConsumption($id, 30);
            if ($analysis['status'] === 'success') {
                $historicalAnalysis = $analysis;
            }
        } catch (\Exception $e) {
            \Log::debug("Structure Manager: Could not load historical analysis for structure {$id}");
        }
        
        return view('structure-manager::detail', compact('structure', 'services', 'fuelHistory', 'consumption', 'historicalAnalysis'));
    }
    
    public function getFuelHistory($id)
    {
        // SECURITY: scope check - resolve corporation and enforce access
        $structure = DB::table('corporation_structures')
            ->where('structure_id', $id)
            ->select('corporation_id')
            ->first();

        if (!$structure) {
            abort(404);
        }

        $this->requireCorporationAccess($structure->corporation_id);

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
                $lastRecord->created_at->diffInHours(now(), true) >= 24) {

                $daysRemaining = Carbon::parse($structure->fuel_expires)->diffInDays(now(), true);

                $fuelUsed = null;
                $dailyConsumption = null;

                if ($lastRecord && $lastRecord->fuel_expires != $structure->fuel_expires) {
                    // Fuel was added
                    $daysDiff = Carbon::parse($structure->fuel_expires)->diffInDays(Carbon::parse($lastRecord->fuel_expires), true);
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

    /**
     * Calculate consumption rates for a structure
     * ALWAYS uses service-based calculation for accurate, real-time results
     * Historical tracking continues in background for refuel/anomaly detection
     */
    private function calculateConsumption($structureId, $structureTypeId = null)
    {
        try {
            // Get structure info if type not provided
            if ($structureTypeId === null) {
                $structure = DB::table('corporation_structures')
                    ->where('structure_id', $structureId)
                    ->first();
                
                if (!$structure) {
                    return [
                        'hourly' => 0,
                        'daily' => 0,
                        'weekly' => 0,
                        'monthly' => 0,
                        'quarterly' => 0,
                        'method' => 'error',
                        'error' => 'Structure not found',
                    ];
                }
                
                $structureTypeId = $structure->type_id;
            }
            
            // ALWAYS use service-based calculation from FuelCalculator
            $hourly = \StructureManager\Helpers\FuelCalculator::getFuelRequirement(
                $structureTypeId, 
                $structureId,
                'hourly'
            );
            
            return [
                'hourly' => round($hourly, 2),
                'daily' => round($hourly * 24),
                'weekly' => round($hourly * 24 * 7),
                'monthly' => round($hourly * 24 * 30),
                'quarterly' => round($hourly * 24 * 90),
                'method' => 'service_based',
                'note' => 'Based on current active services',
            ];
            
        } catch (\Exception $e) {
            \Log::error("Structure Manager: Error calculating consumption for structure {$structureId}: " . $e->getMessage());
            
            return [
                'hourly' => 0,
                'daily' => 0,
                'weekly' => 0,
                'monthly' => 0,
                'quarterly' => 0,
                'method' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Get fuel analysis for a structure (for detail page)
     * Returns historical tracking data for trends, refuels, and anomalies
     */
    public function getFuelAnalysis($id)
    {
        // SECURITY: scope check - resolve corporation and enforce access
        $structure = DB::table('corporation_structures')
            ->where('structure_id', $id)
            ->select('corporation_id')
            ->first();

        if (!$structure) {
            abort(404);
        }

        $this->requireCorporationAccess($structure->corporation_id);

        $analysis = \StructureManager\Services\FuelConsumptionTracker::analyzeFuelConsumption($id, 30);
        return response()->json($analysis);
    }

    /**
     * Display the help and documentation page
     *
     * @return \Illuminate\View\View
     */
    public function help()
    {
        // Latest-version check shown in the Overview card. The service caches
        // for 6h + has a 3s timeout, so a Packagist outage can never slow the
        // Help page meaningfully or break the render.
        $versionStatus = app(\StructureManager\Services\VersionChecker::class)->getStatus();

        return view('structure-manager::help.index', compact('versionStatus'));
    }
}
