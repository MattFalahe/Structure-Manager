<?php

namespace StructureManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Model for POS (Player Owned Starbase) fuel history tracking
 * 
 * Tracks fuel blocks, strontium, and charters over time
 */
class StarbaseFuelHistory extends Model
{
    protected $table = 'starbase_fuel_history';
    
    protected $fillable = [
        'starbase_id',
        'corporation_id',
        'tower_type_id',
        'starbase_name',
        'system_id',
        'state', // POS state: 0=unanchored, 1=offline, 2=onlining, 3=reinforced, 4=online
        // Fuel blocks
        'fuel_blocks_quantity',
        'fuel_days_remaining',
        'fuel_blocks_used',
        'fuel_hourly_consumption',
        // Strontium
        'strontium_quantity',
        'strontium_hours_available',
        'strontium_status',
        // Charters
        'charter_quantity',
        'charter_days_remaining',
        'requires_charters',
        // Calculated
        'actual_days_remaining',
        'limiting_factor',
        'estimated_fuel_expiry',
        // Context
        'system_security',
        'space_type',
        'metadata',
        // Notification tracking
        'last_fuel_notification_status',
        'last_fuel_notification_at',
        'fuel_final_alert_sent',
        'last_strontium_notification_status',
        'last_strontium_notification_at',
        'strontium_final_alert_sent',
    ];
    
    protected $casts = [
        'state' => 'integer',
        'fuel_blocks_quantity' => 'integer',
        'fuel_days_remaining' => 'float',
        'fuel_blocks_used' => 'integer',
        'fuel_hourly_consumption' => 'float',
        'strontium_quantity' => 'integer',
        'strontium_hours_available' => 'float',
        'charter_quantity' => 'integer',
        'charter_days_remaining' => 'float',
        'requires_charters' => 'boolean',
        'actual_days_remaining' => 'float',
        'system_security' => 'float',
        'metadata' => 'array',
        'fuel_final_alert_sent' => 'boolean',
        'strontium_final_alert_sent' => 'boolean',
    ];
    
    protected $dates = [
        'estimated_fuel_expiry',
        'last_fuel_notification_at',
        'last_strontium_notification_at',
        'created_at',
        'updated_at',
    ];
    
    /**
     * Relationship to the starbase
     */
    public function starbase()
    {
        // SeAT's corporation_starbases table doesn't have a model by default
        // This would need to be created or use DB queries
        return $this->belongsTo(\Seat\Eveapi\Models\Corporation\CorporationStarbase::class, 'starbase_id', 'starbase_id');
    }
    
    /**
     * Check if strontium is at critical levels (< 6 hours)
     */
    public function hasCriticalStrontium()
    {
        return $this->strontium_hours_available !== null && 
               $this->strontium_hours_available < 6;
    }
    
    /**
     * Check if strontium is at warning levels (< 12 hours)
     */
    public function hasLowStrontium()
    {
        return $this->strontium_hours_available !== null && 
               $this->strontium_hours_available < 12;
    }
    
    /**
     * Check if the POS is in high-sec and requires charters
     */
    public function requiresCharters()
    {
        return $this->requires_charters === true;
    }
    
    /**
     * Check if charters are running low (< 7 days)
     */
    public function hasLowCharters()
    {
        return $this->requires_charters && 
               $this->charter_days_remaining !== null && 
               $this->charter_days_remaining < 7;
    }
    
    /**
     * Get the limiting factor (fuel or charters)
     */
    public function getLimitingFactor()
    {
        return $this->limiting_factor;
    }
    
    /**
     * Check if fuel is critically low (< 7 days)
     */
    public function hasCriticalFuel()
    {
        return $this->actual_days_remaining !== null && 
               $this->actual_days_remaining < 7;
    }
    
    /**
     * Check if fuel is low (< 14 days)
     */
    public function hasLowFuel()
    {
        return $this->actual_days_remaining !== null && 
               $this->actual_days_remaining < 14;
    }
    
    /**
     * Get fuel status level
     * 
     * @return string critical/warning/normal/good
     */
    public function getFuelStatus()
    {
        if ($this->actual_days_remaining === null) {
            return 'unknown';
        }
        
        if ($this->actual_days_remaining < 7) {
            return 'critical';
        } elseif ($this->actual_days_remaining < 14) {
            return 'warning';
        } elseif ($this->actual_days_remaining < 30) {
            return 'normal';
        } else {
            return 'good';
        }
    }
    
    /**
     * Get state name from state integer
     * 
     * @return string State name
     */
    public function getStateName()
    {
        $stateMap = [
            0 => 'Unanchored',
            1 => 'Offline',
            2 => 'Onlining',
            3 => 'Reinforced',
            4 => 'Online',
        ];
        
        return $stateMap[$this->state] ?? 'Unknown';
    }
    
    /**
     * Get state badge class for styling
     * 
     * @return string CSS class for badge
     */
    public function getStateBadgeClass()
    {
        $stateClassMap = [
            0 => 'badge-secondary',  // Unanchored
            1 => 'badge-danger',     // Offline
            2 => 'badge-info',       // Onlining
            3 => 'badge-warning',    // Reinforced
            4 => 'badge-success',    // Online
        ];
        
        return $stateClassMap[$this->state] ?? 'badge-secondary';
    }
    
    /**
     * Check if POS is online (state = 4)
     */
    public function isOnline()
    {
        return $this->state === 4;
    }
    
    /**
     * Check if POS is reinforced (state = 3)
     */
    public function isReinforced()
    {
        return $this->state === 3;
    }
    
    /**
     * Check if POS is offline (state = 1)
     */
    public function isOffline()
    {
        return $this->state === 1;
    }
}
