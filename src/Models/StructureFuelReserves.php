<?php

namespace StructureManager\Models;

use Illuminate\Database\Eloquent\Model;

class StructureFuelReserves extends Model
{
    protected $table = 'structure_fuel_reserves';

    /**
     * Location type constants — set by TrackFuelConsumption when polling.
     *
     * OWNED_STRUCTURE: corp owns the Upwell, structure_id references
     *                  corporation_structures. v1.x default behavior.
     * FOREIGN_STRUCTURE: corp has assets in another corp's Upwell;
     *                  structure_id is universe_structures.structure_id.
     * NPC_STATION:     corp has assets in an NPC station (location_id
     *                  in the 60000000-69999999 range); structure_id
     *                  is the station_id, resolved via staStations.
     * UNKNOWN_LOCATION: corporation_assets.location_type='other' or no
     *                  matching universe/station row could be resolved.
     */
    public const LOCATION_OWNED_STRUCTURE   = 'owned_structure';
    public const LOCATION_FOREIGN_STRUCTURE = 'foreign_structure';
    public const LOCATION_NPC_STATION       = 'npc_station';
    public const LOCATION_UNKNOWN           = 'unknown_location';

    protected $fillable = [
        'structure_id',
        'corporation_id',
        'fuel_type_id',
        'reserve_quantity',
        'location_flag',
        'previous_quantity',
        'quantity_change',
        'is_refuel_event',
        'metadata',
        // v2.0.0 — external reserves tracking
        'location_type',
        'location_name',
        'location_system_id',
        'location_system_name',
    ];

    protected $casts = [
        'is_refuel_event' => 'boolean',
        'metadata' => 'array',
    ];

    protected $dates = [
        'created_at',
        'updated_at',
    ];

    /**
     * Relationship is meaningful only for owned_structure rows. Returns
     * null for foreign/NPC/unknown — that's intentional, callers should
     * branch on location_type when they need the structure object.
     */
    public function structure()
    {
        return $this->belongsTo(\Seat\Eveapi\Models\Corporation\CorporationStructure::class, 'structure_id', 'structure_id');
    }

    /**
     * Get current reserve total for a structure
     */
    public static function getCurrentReserves($structureId)
    {
        return self::where('structure_id', $structureId)
            ->selectRaw('fuel_type_id, location_flag, reserve_quantity')
            ->whereIn('id', function($query) use ($structureId) {
                $query->selectRaw('MAX(id)')
                    ->from('structure_fuel_reserves')
                    ->where('structure_id', $structureId)
                    ->groupBy('fuel_type_id', 'location_flag');
            })
            ->get();
    }

    /**
     * Get total reserves across all divisions for a structure
     */
    public static function getTotalReserves($structureId)
    {
        $reserves = self::getCurrentReserves($structureId);
        return $reserves->sum('reserve_quantity');
    }

    /**
     * Get recent refuel events
     */
    public static function getRefuelEvents($structureId, $days = 30)
    {
        return self::where('structure_id', $structureId)
            ->where('is_refuel_event', true)
            ->where('created_at', '>=', \Carbon\Carbon::now()->subDays($days))
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * True when this row is for a corp-owned Upwell. Most v1.x consumers
     * should branch on this — they'll only know how to render owned rows.
     */
    public function isOwnedStructure(): bool
    {
        return $this->location_type === self::LOCATION_OWNED_STRUCTURE
            || $this->location_type === null; // null = legacy v1.x row, treat as owned
    }

    /**
     * True when this row is for an NPC station, foreign Upwell, or
     * anywhere else that's not the corp's own structure.
     */
    public function isExternalLocation(): bool
    {
        return !$this->isOwnedStructure();
    }
}
