<?php

namespace StructureManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tracks notification state for Upwell structure fuel alerts.
 *
 * One row per fueled structure. Survives history table cleanup/pruning.
 */
class StructureNotificationStatus extends Model
{
    protected $table = 'structure_notification_status';

    protected $fillable = [
        'structure_id',
        'corporation_id',
        'last_fuel_notification_status',
        'last_fuel_notification_at',
        'fuel_final_alert_sent',
        'last_gas_notification_status',
        'last_gas_notification_at',
    ];

    protected $casts = [
        'fuel_final_alert_sent' => 'boolean',
    ];

    protected $dates = [
        'last_fuel_notification_at',
        'last_gas_notification_at',
        'created_at',
        'updated_at',
    ];

    /**
     * Get or create a notification status row for a structure.
     *
     * @param int $structureId
     * @param int $corporationId
     * @return static
     */
    public static function getOrCreate(int $structureId, int $corporationId): self
    {
        return self::firstOrCreate(
            ['structure_id' => $structureId],
            ['corporation_id' => $corporationId]
        );
    }
}
