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
        'magmatic_gas_quantity',  // NEW: v1.0.6 - Metenox support
        'magmatic_gas_days',       // NEW: v1.0.6 - Metenox support
    ];
    
    protected $casts = [
        'metadata' => 'array',
        'magmatic_gas_quantity' => 'integer',
        'magmatic_gas_days' => 'float',
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
    
    /**
     * Check if this record is for a Metenox Moon Drill
     */
    public function isMetenox()
    {
        if ($this->metadata && isset($this->metadata['is_metenox'])) {
            return $this->metadata['is_metenox'] === true;
        }
        return false;
    }
    
    /**
     * Get the limiting factor for Metenox (fuel_blocks or magmatic_gas)
     */
    public function getMetenoxLimitingFactor()
    {
        if (!$this->isMetenox()) {
            return null;
        }
        
        if ($this->metadata && isset($this->metadata['limiting_factor'])) {
            return $this->metadata['limiting_factor'];
        }
        
        return null;
    }
    
    /**
     * Check if Metenox has critical magmatic gas levels (< 7 days)
     */
    public function hasLowMagmaticGas()
    {
        return $this->isMetenox() && 
               $this->magmatic_gas_days !== null && 
               $this->magmatic_gas_days < 7;
    }
}
