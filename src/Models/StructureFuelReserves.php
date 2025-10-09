<?php

namespace StructureManager\Models;

use Illuminate\Database\Eloquent\Model;

class StructureFuelReserves extends Model
{
    protected $table = 'structure_fuel_reserves';
    
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
    ];
    
    protected $casts = [
        'is_refuel_event' => 'boolean',
        'metadata' => 'array',
    ];
    
    protected $dates = [
        'created_at',
        'updated_at',
    ];
    
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
}
