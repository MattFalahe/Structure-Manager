<?php

namespace StructureManager\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use StructureManager\Helpers\TypeIdRegistry;
use StructureManager\Models\StructureFuelReserves;
use StructureManager\Models\StarbaseFuelReserves;
use StructureManager\Models\StructureManagerSettings;
use Carbon\Carbon;

class FuelReserveController extends Controller
{
    /**
     * Magmatic Gas type ID.
     * @deprecated use TypeIdRegistry::MAGMATIC_GAS
     */
    const MAGMATIC_GAS_TYPE_ID = TypeIdRegistry::MAGMATIC_GAS;

    /**
     * Metenox Moon Drill type ID.
     * @deprecated use TypeIdRegistry::METENOX
     */
    const METENOX_TYPE_ID = TypeIdRegistry::METENOX;
    
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
     * RIGHTS (admin = null = everything). This reports the current VIEW
     * SCOPE: it defaults to the user's own corporations even for admins,
     * so an admin landing on the Reserves page sees their own corp(s)
     * first instead of every corp on the install.
     *
     *   ?scope=mine     (default) -> user's own corporations
     *   ?scope=all                -> null (all corps) — admins only
     *   ?scope=<corpId>           -> that single corp (admins: any;
     *                                others: only their own, else own)
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
            return $ownCorps;
        }

        return $ownCorps;
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
     * Get reserves data by system.
     *
     * v2.0.0 — POS towers are NOT included here. POSes don't have CorpSAG
     * hangars (they use Stront/Fuel/Charter bays which are operational
     * consumables, not staged reserves), so they conceptually don't
     * belong on the "Fuel Reserves" page. POS resource tracking lives
     * on the dedicated POS view + per-POS detail pages.
     *
     * What IS included:
     *   - Owned Upwell structures (getStructureReserves)
     *   - CorpSAG fuel staged in external locations — NPC stations,
     *     foreign Upwell structures (getExternalReserves, v2.0.0)
     */
    public function getReservesData()
    {
        // Corp scope for THIS view — own corps by default (admins
        // included); ?scope=all / ?scope=<corpId> override.
        $userCorps = $this->getScopedCorporations();

        $structureReserves = $this->getStructureReserves($userCorps);
        $externalReserves = $this->getExternalReserves($userCorps);

        $systemReserves = $this->mergeExternalReserveData($structureReserves, $externalReserves);

        return response()->json($systemReserves);
    }

    /**
     * v2.0.0 — Fetch reserves that sit in non-owned locations.
     *
     * Reads structure_fuel_reserves rows where location_type is either
     * 'npc_station' (corp rents an Office in an NPC station) or
     * 'foreign_structure' (corp rents an Office or otherwise has CorpSAG
     * access in another corp's Upwell). Groups them by solar system —
     * each external location appears as a pseudo-structure under its
     * system so the UI's existing system-grouped layout renders it
     * alongside owned Upwells.
     *
     * unknown_location rows are deliberately excluded — those are
     * resolution failures (location_id doesn't match any known
     * structure/station), and surfacing them with no name/system is
     * UI noise. Operators can't refuel from an unknown location anyway.
     *
     * TrackFuelConsumption mirrors this filter — it only writes
     * npc_station and foreign_structure rows.
     *
     * Returns the same shape as getStructureReserves(): a system-keyed
     * dict where each entry has 'structures' + 'pos_towers' + 'total_reserves'.
     */
    private function getExternalReserves($userCorps)
    {
        // Pull the LATEST snapshot per (structure_id, fuel_type, location_flag)
        // for trackable external locations (NPC stations + foreign Upwells).
        $trackableTypes = [
            StructureFuelReserves::LOCATION_NPC_STATION,
            StructureFuelReserves::LOCATION_FOREIGN_STRUCTURE,
        ];

        $query = DB::table('structure_fuel_reserves as sfr')
            ->whereIn('id', function ($sub) use ($trackableTypes) {
                $sub->selectRaw('MAX(id)')
                    ->from('structure_fuel_reserves')
                    ->whereIn('location_type', $trackableTypes)
                    ->groupBy('structure_id', 'fuel_type_id', 'location_flag');
            })
            // Skip fully-depleted rows. The depletion-reconciliation pass
            // in TrackFuelConsumption inserts reserve_quantity=0 rows to
            // close out the audit trail when fuel moves away from a
            // location. Those rows are correct history but shouldn't
            // render as "0 blocks" cards on the system-grouped view.
            ->where('sfr.reserve_quantity', '>', 0);

        if ($userCorps !== null) {
            $query->whereIn('sfr.corporation_id', $userCorps);
        }

        $rows = $query->select(
            'sfr.structure_id',
            'sfr.corporation_id',
            'sfr.fuel_type_id',
            'sfr.reserve_quantity',
            'sfr.location_flag',
            'sfr.location_type',
            'sfr.location_name',
            'sfr.location_system_id',
            'sfr.location_system_name'
        )->get();

        if ($rows->isEmpty()) {
            return [];
        }

        // Resolve corp names + division names in bulk so we don't N+1 below
        $corpIds = $rows->pluck('corporation_id')->unique()->all();
        $corpNames = DB::table('corporation_infos')
            ->whereIn('corporation_id', $corpIds)
            ->pluck('name', 'corporation_id')
            ->toArray();

        $divisionMap = DB::table('corporation_divisions')
            ->whereIn('corporation_id', $corpIds)
            ->where('type', 'hangar')
            ->select('corporation_id', 'division', 'name')
            ->get()
            ->groupBy('corporation_id');

        // Group by (system, location_id) so each external location becomes
        // a single pseudo-structure card in the UI.
        $systemReserves = [];
        $locationBuckets = [];

        foreach ($rows as $row) {
            // Skip excluded hangars (matches existing owned-reserves behavior)
            if ($this->isHangarExcluded($row->location_flag)) {
                continue;
            }

            $systemName = $row->location_system_name ?: 'Unknown System';
            $locationKey = $systemName . ':' . $row->structure_id;

            if (!isset($systemReserves[$systemName])) {
                $systemReserves[$systemName] = [
                    'security' => 0.0, // External locations don't have sec from corp_structures
                    'structures' => [],
                    'pos_towers' => [],
                    'total_reserves' => 0,
                ];
            }

            if (!isset($locationBuckets[$locationKey])) {
                $locationBuckets[$locationKey] = [
                    'structure_id' => $row->structure_id,
                    'name' => $row->location_name ?: 'Unknown Location',
                    'type' => $this->labelForLocationType($row->location_type),
                    'corporation' => $corpNames[$row->corporation_id] ?? 'Unknown Corp',
                    'total_reserves' => 0,
                    'total_gas' => 0,
                    'is_metenox' => false,
                    'is_external' => true,
                    'location_type' => $row->location_type,
                    'reserves' => [],
                    'asset_type' => 'external',
                ];
            }

            $isGas = $row->fuel_type_id == self::MAGMATIC_GAS_TYPE_ID;
            if ($isGas) {
                $locationBuckets[$locationKey]['total_gas'] += $row->reserve_quantity;
            } else {
                $locationBuckets[$locationKey]['total_reserves'] += $row->reserve_quantity;
                $systemReserves[$systemName]['total_reserves'] += $row->reserve_quantity;
            }

            // Resolve division name for this row
            preg_match('/CorpSAG(\d+)/', $row->location_flag, $matches);
            $divisionNumber = isset($matches[1]) ? (int) $matches[1] : 0;
            $divisionName = "Division {$divisionNumber}";
            if (isset($divisionMap[$row->corporation_id])) {
                foreach ($divisionMap[$row->corporation_id] as $div) {
                    if ((int) $div->division === $divisionNumber && $div->name) {
                        $divisionName = $div->name;
                        break;
                    }
                }
            }

            $locationBuckets[$locationKey]['reserves'][] = [
                'location' => $row->location_flag,
                'division_name' => $divisionName,
                'quantity' => $row->reserve_quantity,
                'fuel_type_id' => $row->fuel_type_id,
            ];
        }

        // Attach buckets to systems
        foreach ($locationBuckets as $key => $bucket) {
            [$systemName, ] = explode(':', $key, 2);
            $systemReserves[$systemName]['structures'][] = $bucket;
        }

        return $systemReserves;
    }

    /**
     * Display label for an external location_type. Mirrors the lang
     * strings used elsewhere; kept inline here because this controller
     * does not (yet) ship its own lang file.
     */
    private function labelForLocationType(string $locationType): string
    {
        switch ($locationType) {
            case StructureFuelReserves::LOCATION_FOREIGN_STRUCTURE:
                return 'Foreign Citadel';
            case StructureFuelReserves::LOCATION_NPC_STATION:
                return 'NPC Station';
            case StructureFuelReserves::LOCATION_UNKNOWN:
                return 'Unknown Location';
            default:
                return 'External Location';
        }
    }

    /**
     * Merge external-location reserves into an existing system-keyed
     * reserves dict. Used after structure + POS data is already merged.
     */
    private function mergeExternalReserveData(array $base, array $external): array
    {
        foreach ($external as $systemName => $systemData) {
            if (!isset($base[$systemName])) {
                $base[$systemName] = $systemData;
                continue;
            }

            // Append external structures + accumulate totals
            foreach ($systemData['structures'] as $bucket) {
                $base[$systemName]['structures'][] = $bucket;
            }
            $base[$systemName]['total_reserves'] += $systemData['total_reserves'];
        }
        return $base;
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

            // Filter out fully-depleted rows (latest quantity = 0). These
            // are audit-trail rows produced by TrackFuelConsumption's
            // depletion-reconciliation pass when fuel is moved out — they
            // keep the historical chain intact but should not render as
            // "0 blocks" cards in the UI.
            $reserves = $reserves->filter(function($reserve) {
                return (int) $reserve->reserve_quantity > 0;
            });

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
    
    // ============================================================
    // getPosReserves + mergeReserveData (REMOVED in v2.0.0)
    // ============================================================
    // POS towers don't belong on the Fuel Reserves page — they use
    // Stront/Fuel/Charter bays which are operational consumables, not
    // CorpSAG hangar staging. The dedicated POS view + per-POS detail
    // pages cover POS resource tracking. Removed in commit following
    // 7957a5c.

    /**
     * Get refuel events history
     * UPDATED: Only tracks Upwell structures (excludes Metenox and POS)
     */
    public function getRefuelHistory($days = 30)
    {
        // Corp scope for THIS view — own corps by default (admins
        // included); ?scope=all / ?scope=<corpId> override.
        $userCorps = $this->getScopedCorporations();

        // v2.0.0 — accept ?page=N and ?per_page=N query params for
        // server-side pagination. Defaults preserve v1.x "all rows at
        // once" behavior for clients that don't pass page params, but
        // the response shape gains a 'pagination' key when one is set.
        $page = max(1, (int) request()->query('page', 1));
        $perPage = min(200, max(10, (int) request()->query('per_page', 50)));

        $events = $this->getStructureRefuelEvents($days, $userCorps, $page, $perPage);

        return response()->json($events);
    }

    /**
     * Get structure refuel events.
     *
     * v2.0.0 changes:
     *   - LEFT JOIN to corporation_structures (was INNER) so external
     *     reserves rows (foreign_structure / npc_station) are NOT
     *     filtered out by the join.
     *   - Excludes Metenox via cs.type_id when the corp owns the
     *     structure; external rows are returned regardless of type.
     *   - Paginated response — {data, pagination: {current_page,
     *     last_page, per_page, total}}
     *   - Each event row carries division_name (resolved from
     *     corporation_divisions) so the UI can show
     *     "CORPSAG3 - Fuel Hangar" without a JS-side lookup.
     */
    private function getStructureRefuelEvents($days, $userCorps, int $page = 1, int $perPage = 50)
    {
        $query = StructureFuelReserves::where('structure_fuel_reserves.is_refuel_event', true)
            ->where('structure_fuel_reserves.created_at', '>=', Carbon::now()->subDays($days))
            ->leftJoin('corporation_structures as cs', 'structure_fuel_reserves.structure_id', '=', 'cs.structure_id')
            // Filter Metenox when the row IS for an owned structure; allow
            // external rows (cs.type_id IS NULL because they're not in
            // corporation_structures).
            ->where(function ($q) {
                $q->whereNull('cs.type_id')
                  ->orWhere('cs.type_id', '!=', self::METENOX_TYPE_ID);
            })
            ->orderBy('structure_fuel_reserves.created_at', 'desc');

        if ($userCorps !== null) {
            $query->whereIn('structure_fuel_reserves.corporation_id', $userCorps);
        }

        // Apply hangar exclusion at the SQL level via location_flag regex
        // when possible. For simplicity and parity with the v1.x in-PHP
        // filter, we still filter in PHP after fetch — but with paginated
        // fetches we use a small over-fetch buffer so an excluded page
        // doesn't produce a tiny visible page. The total count includes
        // excluded rows, which is an acceptable approximation.
        $totalRows = (clone $query)->count();

        $rawEvents = $query
            ->select('structure_fuel_reserves.*')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        // Pre-cache structure metadata + division names so we don't N+1
        $structureIds = $rawEvents->pluck('structure_id')->unique()->all();
        $structures = DB::table('corporation_structures as cs')
            ->join('universe_structures as us', 'cs.structure_id', '=', 'us.structure_id')
            ->leftJoin('mapDenormalize as md', 'cs.system_id', '=', 'md.itemID')
            ->whereIn('cs.structure_id', $structureIds)
            ->select('cs.structure_id', 'us.name as structure_name', 'md.itemName as system_name')
            ->get()
            ->keyBy('structure_id');

        $corpIds = $rawEvents->pluck('corporation_id')->unique()->all();
        $divisionMap = DB::table('corporation_divisions')
            ->whereIn('corporation_id', $corpIds)
            ->where('type', 'hangar')
            ->select('corporation_id', 'division', 'name')
            ->get()
            ->groupBy('corporation_id');

        // Phantom-pair filter: build the set of depletion_reconciliation
        // row IDs that were "undone" by a return-to-positive row in the
        // same (structure_id, fuel_type_id, location_flag) tuple within
        // 4 hours. These are the most reliable signature of a race
        // condition between TrackFuelConsumption and SeAT's corporation-
        // assets refresh job: fuel "disappears" for one poll, the
        // reconciliation pass writes a depletion row, then fuel returns
        // on the next poll. Hiding them protects historical phantom rows
        // from cluttering the Fuel Withdrawals UI without deleting any
        // DB data — Matt can still see them via the cleanup-phantom-
        // withdrawals artisan command with --dry-run.
        $phantomIds = $this->detectPhantomPairs($rawEvents);

        $eventData = [];
        foreach ($rawEvents as $event) {
            // Skip phantom depletion-reconciliation rows (followed within
            // 4h by a return-to-positive in the same tuple — almost
            // certainly a SeAT asset-refresh race rather than a real move).
            if (isset($phantomIds[$event->id])) {
                continue;
            }

            // Skip excluded hangars (PHP-side filter preserves v1.x behavior)
            if ($this->isHangarExcluded($event->location_flag)) {
                continue;
            }

            // Resolve structure + system name. For external rows (no
            // corporation_structures row) fall back to the denormalized
            // fields on the reserve record itself.
            $isExternal = !isset($structures[$event->structure_id]);
            if ($isExternal) {
                $structureName = $event->location_name ?: 'Unknown Location';
                $systemName = $event->location_system_name ?: 'Unknown System';
            } else {
                $row = $structures[$event->structure_id];
                $structureName = $row->structure_name ?? 'Unknown';
                $systemName = $row->system_name ?? 'Unknown';
            }

            // Resolve division name for the CorpSAG location_flag
            preg_match('/CorpSAG(\d+)/', $event->location_flag, $matches);
            $divisionNumber = isset($matches[1]) ? (int) $matches[1] : 0;
            $divisionName = "Division {$divisionNumber}";
            if (isset($divisionMap[$event->corporation_id])) {
                foreach ($divisionMap[$event->corporation_id] as $div) {
                    if ((int) $div->division === $divisionNumber && $div->name) {
                        $divisionName = $div->name;
                        break;
                    }
                }
            }

            // Decode tracking_method from metadata JSON so the UI can
            // distinguish normal asset-poll detection from depletion
            // reconciliation rows (which fire when fuel moves OUT of a
            // tracked CorpSAG and the previous row's quantity needs to be
            // closed out to zero).
            $trackingMethod = 'detected';
            if (!empty($event->metadata)) {
                $meta = is_array($event->metadata) ? $event->metadata : json_decode($event->metadata, true);
                if (is_array($meta) && !empty($meta['tracking_method'])) {
                    $trackingMethod = (string) $meta['tracking_method'];
                }
            }

            $eventData[] = [
                'timestamp' => $event->created_at,
                'structure_id' => $event->structure_id,
                'structure_name' => $structureName,
                'system_name' => $systemName,
                'blocks_moved' => abs($event->quantity_change),
                'quantity_change' => (int) $event->quantity_change,
                'previous_quantity' => (int) ($event->previous_quantity ?? 0),
                'new_quantity' => (int) ($event->reserve_quantity ?? 0),
                'from_location' => $event->location_flag,
                'division_name' => $divisionName,
                'fuel_type_id' => $event->fuel_type_id,
                'is_gas' => $event->fuel_type_id == self::MAGMATIC_GAS_TYPE_ID,
                'is_external' => $isExternal,
                'location_type' => $event->location_type ?: StructureFuelReserves::LOCATION_OWNED_STRUCTURE,
                'asset_type' => $isExternal ? 'external' : 'structure',
                'tracking_method' => $trackingMethod,
            ];
        }

        return [
            'data' => $eventData,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $totalRows,
                'last_page' => max(1, (int) ceil($totalRows / $perPage)),
            ],
        ];
    }
    
    /**
     * Get reserve movements for a specific structure
     */
    public function getStructureReserveHistory($structureId)
    {
        // SECURITY: scope check - resolve corporation and enforce access
        $structure = DB::table('corporation_structures')
            ->where('structure_id', $structureId)
            ->select('corporation_id')
            ->first();

        if (!$structure) {
            abort(404);
        }

        $this->requireCorporationAccess($structure->corporation_id);

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
        // SECURITY: scope check - resolve corporation and enforce access
        $starbase = DB::table('corporation_starbases')
            ->where('starbase_id', $starbaseId)
            ->select('corporation_id')
            ->first();

        if (!$starbase) {
            abort(404);
        }

        $this->requireCorporationAccess($starbase->corporation_id);

        $history = StarbaseFuelReserves::where('starbase_id', $starbaseId)
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();

        return response()->json($history);
    }

    /**
     * Identify phantom withdrawal rows that should NOT appear in the
     * Fuel Withdrawals UI. Two distinct phantom signatures are caught:
     *
     *   (A) Dual-stack mirror pairs — the dominant phantom pattern. Pre-
     *       v2.0.0 trackStructureReserves + trackExternalReserves treated
     *       each physical fuel stack as a separate reserve, so a
     *       CorpSAG with N split stacks of the same fuel type would
     *       oscillate the dedup memory between stacks. Result: every
     *       poll wrote a mirror pair (+M / −M) in the same tuple within
     *       a few seconds of each other. The write-side fix aggregates
     *       stacks by tuple; this filter hides the historical noise.
     *
     *   (B) Depletion-reconciliation race phantoms — pre-debounce, the
     *       reconciliation pass could fire during SeAT's asset-refresh
     *       window and write a depletion row that next poll undid via
     *       a return-positive row. Now debounced at write-time; this
     *       filter is the belt-and-braces.
     *
     * Returns a map [row_id => true] for fast O(1) lookup during the
     * row-emit loop. Both halves of each (A)-pair are marked phantom.
     *
     * @param  \Illuminate\Support\Collection  $rawEvents
     * @return array<int, bool>
     */
    private function detectPhantomPairs($rawEvents): array
    {
        if ($rawEvents->isEmpty()) {
            return [];
        }

        $phantom = [];

        // === Signature (A): Dual-stack mirror pairs ===
        // For every is_refuel_event row in the page, ask: did the same
        // tuple have a matching +N companion row within 5 minutes? If
        // yes, both the −N (in our page) and the +N (anywhere in DB)
        // are dual-stack artifacts.
        $pairWindowSeconds = 300;

        foreach ($rawEvents as $event) {
            if (!isset($event->quantity_change) || $event->quantity_change >= 0) {
                continue; // We only need to evaluate negative-change rows
            }

            $until = Carbon::parse($event->created_at)->addSeconds($pairWindowSeconds);
            $since = Carbon::parse($event->created_at)->subSeconds($pairWindowSeconds);

            $mirrorExists = DB::table('structure_fuel_reserves')
                ->where('structure_id', $event->structure_id)
                ->where('fuel_type_id', $event->fuel_type_id)
                ->where('location_flag', $event->location_flag)
                ->where('quantity_change', -1 * (int) $event->quantity_change) // mirror
                ->where('created_at', '>=', $since)
                ->where('created_at', '<=', $until)
                ->where('id', '!=', $event->id)
                ->exists();

            if ($mirrorExists) {
                $phantom[$event->id] = true;
            }
        }

        // === Signature (B): Depletion-reconciliation race phantoms ===
        // Only meaningful for rows whose metadata.tracking_method =
        // 'depletion_reconciliation'. Hidden if a return-positive exists
        // in the same tuple within 4 hours.
        $depletionWindowHours = 4;

        foreach ($rawEvents as $event) {
            if (isset($phantom[$event->id])) {
                continue; // Already flagged by signature (A)
            }

            $meta = is_array($event->metadata)
                ? $event->metadata
                : json_decode($event->metadata ?? '', true);

            // Handle the legacy double-encoded case (json_decode returns a
            // string that itself is JSON). Decode once more to be safe.
            if (is_string($meta)) {
                $meta = json_decode($meta, true);
            }

            if (!is_array($meta)) {
                continue;
            }

            if (($meta['tracking_method'] ?? null) !== 'depletion_reconciliation') {
                continue;
            }

            $until = Carbon::parse($event->created_at)->addHours($depletionWindowHours);

            $returnExists = DB::table('structure_fuel_reserves')
                ->where('structure_id', $event->structure_id)
                ->where('fuel_type_id', $event->fuel_type_id)
                ->where('location_flag', $event->location_flag)
                ->where('reserve_quantity', '>', 0)
                ->where('created_at', '>', $event->created_at)
                ->where('created_at', '<=', $until)
                ->exists();

            if ($returnExists) {
                $phantom[$event->id] = true;
            }
        }

        return $phantom;
    }
}
