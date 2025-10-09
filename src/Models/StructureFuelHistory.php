<?php

namespace StructureManager\Models;

use Illuminate\Database\Eloquent\Model;

class StructureFuelHistory extends Model
{
    protected $table = 'structure_fuel_history';
    
    protected $fillable = [
        'structure_id',
        'corporation_id',
        'fuel_expires',
        'days_remaining',
        'fuel_blocks_used',
        'daily_consumption',
        'consumption_rate',
        'tracking_type',
        'metadata',
    ];
    
    protected $casts = [
        'metadata' => 'array',
    ];
    
    protected $dates = [
        'fuel_expires',
        'created_at',
        'updated_at',
    ];
    
    public function structure()
    {
        return $this->belongsTo(\Seat\Eveapi\Models\Corporation\CorporationStructure::class, 'structure_id', 'structure_id');
    }
}
