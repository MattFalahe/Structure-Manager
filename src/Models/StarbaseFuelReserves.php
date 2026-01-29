<?php

namespace StructureManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Model for POS (Player Owned Starbase) fuel reserves tracking
 * 
 * Tracks fuel blocks, strontium, and charters in corporate hangars
 * Detects refuel events when reserves are moved to POS
 */
class StarbaseFuelReserves extends Model
{
    protected $table = 'starbase_fuel_reserves';
    
    protected $fillable = [
        'starbase_id',
        'corporation_id',
        'location_id',
        'resource_type_id',
        'resource_category',
        'reserve_quantity',
        'location_flag',
        'previous_quantity',
        'quantity_change',
        'is_refuel_event',
        'refuel_detected_at',
        'metadata',
    ];
    
    protected $casts = [
        'starbase_id' => 'integer',
        'corporation_id' => 'integer',
        'location_id' => 'integer',
        'resource_type_id' => 'integer',
        'reserve_quantity' => 'integer',
        'previous_quantity' => 'integer',
        'quantity_change' => 'integer',
        'is_refuel_event' => 'boolean',
        'metadata' => 'array',
    ];
    
    protected $dates = [
        'refuel_detected_at',
        'created_at',
        'updated_at',
    ];
    
    /**
     * Relationship to the starbase (if associated)
     */
    public function starbase()
    {
        return $this->belongsTo(\Seat\Eveapi\Models\Corporation\CorporationStarbase::class, 'starbase_id', 'starbase_id');
    }
    
    /**
     * Check if this is fuel block reserves
     */
    public function isFuel()
    {
        return $this->resource_category === 'fuel';
    }
    
    /**
     * Check if this is strontium reserves
     */
    public function isStrontium()
    {
        return $this->resource_category === 'strontium';
    }
    
    /**
     * Check if this is charter reserves
     */
    public function isCharter()
    {
        return $this->resource_category === 'charter';
    }
    
    /**
     * Check if this was a refuel event
     */
    public function isRefuelEvent()
    {
        return $this->is_refuel_event === true;
    }
    
    /**
     * Check if quantity decreased (moved to POS)
     */
    public function quantityDecreased()
    {
        return $this->quantity_change !== null && $this->quantity_change < 0;
    }
    
    /**
     * Check if quantity increased (added to reserves)
     */
    public function quantityIncreased()
    {
        return $this->quantity_change !== null && $this->quantity_change > 0;
    }
    
    /**
     * Get absolute change amount
     */
    public function getAbsoluteChange()
    {
        return abs($this->quantity_change ?? 0);
    }
    
    /**
     * Scope to get only refuel events
     */
    public function scopeRefuelEvents($query)
    {
        return $query->where('is_refuel_event', true);
    }
    
    /**
     * Scope to get reserves by category
     */
    public function scopeByCategory($query, $category)
    {
        return $query->where('resource_category', $category);
    }
    
    /**
     * Scope to get reserves for a specific starbase
     */
    public function scopeForStarbase($query, $starbaseId)
    {
        return $query->where('starbase_id', $starbaseId);
    }
    
    /**
     * Scope to get reserves at a specific location
     */
    public function scopeAtLocation($query, $locationId)
    {
        return $query->where('location_id', $locationId);
    }
}
