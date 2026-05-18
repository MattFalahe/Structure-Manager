<?php

namespace StructureManager\Models;

use Illuminate\Database\Eloquent\Model;

class StructureFuelHistory extends Model
{
    protected $table = 'structure_fuel_history';

    /**
     * Event classification constants — emitted by FuelEventClassifier and
     * stored in the `event_type` column. Strings (not enum) so we can
     * extend the set without an ALTER TABLE later.
     */
    public const EVENT_UNCLASSIFIED       = 'unclassified';
    public const EVENT_CONSUMPTION_NORMAL = 'consumption_normal';
    public const EVENT_CONSUMPTION_ANOMALY = 'consumption_anomaly';
    public const EVENT_REFUEL_INTERNAL    = 'refuel_internal';
    public const EVENT_REFUEL_EXTERNAL    = 'refuel_external';
    public const EVENT_WITHDRAWAL_BAY     = 'withdrawal_bay';
    public const EVENT_WITHDRAWAL_RESERVES = 'withdrawal_reserves';
    public const EVENT_UNEXPLAINED_GAIN   = 'unexplained_gain';

    /**
     * Withdrawal-class event types — used to decide whether to dispatch
     * WithdrawalForensicsJob and how to render the UI row.
     */
    public const WITHDRAWAL_TYPES = [
        self::EVENT_WITHDRAWAL_BAY,
        self::EVENT_WITHDRAWAL_RESERVES,
    ];

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
        'magmatic_gas_quantity',
        'magmatic_gas_days',
        // v2.0.0 — fuel event classification (Tier 1)
        'event_type',
        'expected_consumption',
        'unexplained_delta',
        'reserves_delta',
    ];

    protected $casts = [
        'metadata' => 'array',
        'magmatic_gas_quantity' => 'integer',
        'magmatic_gas_days' => 'float',
        'expected_consumption' => 'float',
        'unexplained_delta' => 'integer',
        'reserves_delta' => 'integer',
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
     * Tier 2 — candidate handlers identified by WithdrawalForensicsService.
     * Only populated for withdrawal_* events; empty collection otherwise.
     */
    public function candidates()
    {
        return $this->hasMany(StructureFuelEventCandidate::class, 'fuel_history_id', 'id')
            ->orderByRaw("FIELD(confidence, 'HIGH', 'MEDIUM', 'LOW')")
            ->orderByDesc('score');
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

    /**
     * True when this row is a withdrawal-class event eligible for
     * suspect narrowing (drives async dispatch of WithdrawalForensicsJob).
     */
    public function isWithdrawalEvent(): bool
    {
        return in_array($this->event_type, self::WITHDRAWAL_TYPES, true);
    }

    /**
     * Human-friendly label for the event_type. Lang strings live in
     * resources/lang/en/structure.php under the `fuel_event_*` keys so
     * future locales can override.
     */
    public function eventLabel(): string
    {
        $key = 'structure-manager::structure.fuel_event_' . ($this->event_type ?: self::EVENT_UNCLASSIFIED);
        $translated = trans($key);
        // Fall back to a humanized event_type when the lang key is missing
        return $translated === $key ? ucwords(str_replace('_', ' ', (string) $this->event_type)) : $translated;
    }

    /**
     * Bootstrap-style CSS class for badge color. Used by detail.blade.php.
     */
    public function eventBadgeClass(): string
    {
        switch ($this->event_type) {
            case self::EVENT_REFUEL_INTERNAL:
            case self::EVENT_REFUEL_EXTERNAL:
                return 'badge-success';
            case self::EVENT_WITHDRAWAL_BAY:
            case self::EVENT_WITHDRAWAL_RESERVES:
                return 'badge-danger';
            case self::EVENT_CONSUMPTION_ANOMALY:
            case self::EVENT_UNEXPLAINED_GAIN:
                return 'badge-warning';
            case self::EVENT_CONSUMPTION_NORMAL:
                return 'badge-secondary';
            case self::EVENT_UNCLASSIFIED:
            default:
                return 'badge-light';
        }
    }
}
