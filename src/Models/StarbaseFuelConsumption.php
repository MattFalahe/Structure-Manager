<?php

namespace StructureManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Model for POS (Player Owned Starbase) fuel consumption tracking
 * 
 * Aggregates daily consumption data for fuel, strontium, and charters
 * Detects anomalies in consumption patterns
 */
class StarbaseFuelConsumption extends Model
{
    protected $table = 'starbase_fuel_consumption';
    
    protected $fillable = [
        'starbase_id',
        'corporation_id',
        'date',
        // Fuel
        'fuel_daily_consumption',
        'fuel_hourly_rate',
        'fuel_refuel_amount',
        // Strontium
        'strontium_consumption',
        'was_reinforced',
        'strontium_refuel_amount',
        // Charters
        'charter_consumption',
        'charter_refuel_amount',
        'required_charters',
        // Anomalies
        'has_anomaly',
        'anomaly_details',
        'metadata',
    ];
    
    protected $casts = [
        'starbase_id' => 'integer',
        'corporation_id' => 'integer',
        'date' => 'date',
        'fuel_daily_consumption' => 'float',
        'fuel_hourly_rate' => 'float',
        'fuel_refuel_amount' => 'integer',
        'strontium_consumption' => 'float',
        'was_reinforced' => 'boolean',
        'strontium_refuel_amount' => 'integer',
        'charter_consumption' => 'float',
        'charter_refuel_amount' => 'integer',
        'required_charters' => 'boolean',
        'has_anomaly' => 'boolean',
        'anomaly_details' => 'array',
        'metadata' => 'array',
    ];
    
    protected $dates = [
        'date',
        'created_at',
        'updated_at',
    ];
    
    /**
     * Relationship to the starbase
     */
    public function starbase()
    {
        return $this->belongsTo(\Seat\Eveapi\Models\Corporation\CorporationStarbase::class, 'starbase_id', 'starbase_id');
    }
    
    /**
     * Check if this day had a refuel event
     */
    public function hadRefuel()
    {
        return $this->fuel_refuel_amount > 0 || 
               $this->strontium_refuel_amount > 0 || 
               $this->charter_refuel_amount > 0;
    }
    
    /**
     * Check if the POS was reinforced this day
     */
    public function wasReinforced()
    {
        return $this->was_reinforced === true;
    }
    
    /**
     * Check if there was an anomaly detected
     */
    public function hasAnomaly()
    {
        return $this->has_anomaly === true;
    }
    
    /**
     * Get anomaly type if present
     */
    public function getAnomalyType()
    {
        if (!$this->has_anomaly || !$this->anomaly_details) {
            return null;
        }
        
        return $this->anomaly_details['type'] ?? null;
    }
    
    /**
     * Get anomaly description
     */
    public function getAnomalyDescription()
    {
        if (!$this->has_anomaly || !$this->anomaly_details) {
            return null;
        }
        
        return $this->anomaly_details['description'] ?? null;
    }
    
    /**
     * Scope to get records with anomalies
     */
    public function scopeWithAnomalies($query)
    {
        return $query->where('has_anomaly', true);
    }
    
    /**
     * Scope to get records for a date range
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('date', [$startDate, $endDate]);
    }
    
    /**
     * Scope to get records where POS was reinforced
     */
    public function scopeReinforced($query)
    {
        return $query->where('was_reinforced', true);
    }
    
    /**
     * Scope to get records with refuel events
     */
    public function scopeWithRefuels($query)
    {
        return $query->where(function($q) {
            $q->where('fuel_refuel_amount', '>', 0)
              ->orWhere('strontium_refuel_amount', '>', 0)
              ->orWhere('charter_refuel_amount', '>', 0);
        });
    }
    
    /**
     * Calculate expected fuel consumption based on tower type
     * 
     * @param int $towerTypeId
     * @param float|null $systemSecurity
     * @return float
     */
    public static function getExpectedConsumption($towerTypeId, $systemSecurity = null)
    {
        $rates = \StructureManager\Helpers\PosFuelCalculator::getFuelConsumptionRate($towerTypeId, $systemSecurity);
        return $rates['fuel_per_day'];
    }
    
    /**
     * Check if consumption deviates from expected
     * 
     * @param int $towerTypeId
     * @param float|null $systemSecurity
     * @param float $tolerancePercent Default 10%
     * @return bool
     */
    public function hasConsumptionDeviation($towerTypeId, $systemSecurity = null, $tolerancePercent = 10)
    {
        $expected = self::getExpectedConsumption($towerTypeId, $systemSecurity);
        $deviation = abs($this->fuel_daily_consumption - $expected);
        $deviationPercent = ($deviation / $expected) * 100;
        
        return $deviationPercent > $tolerancePercent;
    }
}
