<?php

namespace StructureManager\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use StructureManager\Models\StructureFuelReserves;

/**
 * Resolve a corporation_assets location_id into a (type, name, system)
 * triple usable for fuel-reserves tracking.
 *
 * SeAT v5's corporation_assets.location_id can point to:
 *   1. A corp_owned Upwell  — row in corporation_structures for the
 *                             querying corp.
 *   2. A foreign Upwell     — row in universe_structures (SeAT pulls
 *                             these whenever any of its tracked corps
 *                             has docked at / had assets in the
 *                             structure). NOT in corporation_structures
 *                             for the querying corp.
 *   3. An NPC station       — location_type='station' in corp_assets;
 *                             location_id in the 60000000-69999999
 *                             range. Resolved via staStations (SDE) or
 *                             mapDenormalize.
 *   4. A solar system / other — corporation_assets.location_type=
 *                             'solar_system' or 'other'. Generally not
 *                             relevant for fuel-bay tracking (you can't
 *                             store CorpSAG fuel in a solar system),
 *                             but the resolver handles them defensively.
 *
 * Returns the location_type constant + name + system context, all
 * denormalized for storage in structure_fuel_reserves.
 *
 * Per-request cache: resolver is called once per (location_id) per poll,
 * across multiple fuel-type rows in the same location. Caching avoids
 * repeated SDE lookups when a single hangar holds all four fuel blocks.
 */
class LocationResolver
{
    /**
     * NPC stations have IDs in this range. Below 70M is the historical
     * cutoff; player-built stations start at much higher IDs.
     */
    public const NPC_STATION_MIN = 60000000;
    public const NPC_STATION_MAX = 69999999;

    /** @var array<int, array> per-request cache keyed by location_id */
    private static array $cache = [];

    /**
     * Resolve a location_id into the storage-ready triple.
     *
     * @param int  $locationId  corp_assets.location_id value
     * @param int  $owningCorpId  which corp is asking (for 'is this our Upwell?' check)
     * @return array{
     *     location_type: string,
     *     location_name: string|null,
     *     location_system_id: int|null,
     *     location_system_name: string|null
     * }
     */
    public static function resolve(int $locationId, int $owningCorpId): array
    {
        $cacheKey = $locationId . ':' . $owningCorpId;
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        // 1. Owned Upwell — corp_owned structure
        $owned = DB::table('corporation_structures')
            ->where('structure_id', $locationId)
            ->where('corporation_id', $owningCorpId)
            ->first();
        if ($owned) {
            $result = self::buildOwnedStructureResult($locationId);
            return self::$cache[$cacheKey] = $result;
        }

        // 2. NPC station — by ID range first (cheap), then SDE lookup
        if ($locationId >= self::NPC_STATION_MIN && $locationId <= self::NPC_STATION_MAX) {
            $result = self::buildNpcStationResult($locationId);
            return self::$cache[$cacheKey] = $result;
        }

        // 3. Foreign Upwell — anything else with a universe_structures row
        $foreign = DB::table('universe_structures')
            ->where('structure_id', $locationId)
            ->first();
        if ($foreign) {
            $systemName = self::resolveSystemName($foreign->solar_system_id ?? null);
            return self::$cache[$cacheKey] = [
                'location_type' => StructureFuelReserves::LOCATION_FOREIGN_STRUCTURE,
                'location_name' => $foreign->name ?? null,
                'location_system_id' => $foreign->solar_system_id ?? null,
                'location_system_name' => $systemName,
            ];
        }

        // 4. Unknown — corporation_assets had a row but we can't resolve
        return self::$cache[$cacheKey] = [
            'location_type' => StructureFuelReserves::LOCATION_UNKNOWN,
            'location_name' => null,
            'location_system_id' => null,
            'location_system_name' => null,
        ];
    }

    /**
     * Build a result row for an owned structure. Looks up the name + system
     * via universe_structures (where SeAT stores Upwell metadata) and
     * mapDenormalize for the system name.
     */
    private static function buildOwnedStructureResult(int $structureId): array
    {
        $row = DB::table('corporation_structures as cs')
            ->leftJoin('universe_structures as us', 'cs.structure_id', '=', 'us.structure_id')
            ->where('cs.structure_id', $structureId)
            ->select('us.name', 'cs.system_id')
            ->first();

        $systemName = $row && $row->system_id ? self::resolveSystemName($row->system_id) : null;

        return [
            'location_type' => StructureFuelReserves::LOCATION_OWNED_STRUCTURE,
            'location_name' => $row->name ?? null,
            'location_system_id' => $row->system_id ?? null,
            'location_system_name' => $systemName,
        ];
    }

    /**
     * NPC stations live in the SDE's `staStations` table (CamelCase
     * stationID / stationName / solarSystemID) with `stations` as the
     * SeAT mirror (lowercase station_id / name / system_id). We try
     * both and fall back to "NPC Station #ID" if neither has a row.
     */
    private static function buildNpcStationResult(int $stationId): array
    {
        $name = null;
        $systemId = null;
        $systemName = null;

        // Preferred: SDE staStations (CamelCase columns)
        if (Schema::hasTable('staStations')) {
            $row = DB::table('staStations')
                ->where('stationID', $stationId)
                ->select('stationName', 'solarSystemID')
                ->first();
            if ($row) {
                $name = $row->stationName;
                $systemId = (int) $row->solarSystemID;
            }
        }

        // Fallback: SeAT's `stations` mirror (lowercase)
        if ($name === null && Schema::hasTable('stations')) {
            $row = DB::table('stations')
                ->where('station_id', $stationId)
                ->first();
            if ($row) {
                $name = $row->name ?? null;
                $systemId = $row->system_id ?? null;
            }
        }

        if ($name === null) {
            $name = 'NPC Station #' . $stationId;
        }

        if ($systemId) {
            $systemName = self::resolveSystemName($systemId);
        }

        return [
            'location_type' => StructureFuelReserves::LOCATION_NPC_STATION,
            'location_name' => $name,
            'location_system_id' => $systemId,
            'location_system_name' => $systemName,
        ];
    }

    /**
     * Resolve a solar_system_id to its name via mapDenormalize (SDE).
     */
    private static function resolveSystemName(?int $systemId): ?string
    {
        if (!$systemId) {
            return null;
        }
        if (!Schema::hasTable('mapDenormalize')) {
            return null;
        }
        return DB::table('mapDenormalize')
            ->where('itemID', $systemId)
            ->value('itemName');
    }

    /**
     * Reset the per-request cache. TrackFuelConsumption calls this at the
     * top of each handle() invocation so a long-running worker doesn't
     * accumulate stale entries across job runs.
     */
    public static function flushCache(): void
    {
        self::$cache = [];
    }
}
