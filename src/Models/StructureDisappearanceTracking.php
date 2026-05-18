<?php

namespace StructureManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tracks Upwell structure presence in corporation_structures over time so
 * the disappearance-classifier can fire (or correctly NOT fire) the
 * structure.alert.destroyed event when a row vanishes.
 *
 * See migration `2025_10_01_000024_*` and the design memo
 * `project_structure_manager_destruction_detection.md`.
 *
 * Status state machine:
 *   watching
 *     ├── (3+ misses, last_known_state was vulnerable/reinforce)
 *     │     → destroyed (publish structure.alert.destroyed)
 *     ├── (3+ misses, last_known_state was healthy)
 *     │     → likely_transferred (do NOT publish — owner changed)
 *     ├── (3+ misses, corp had >= 2 trackings vanish simultaneously,
 *     │              no rows of corp present)
 *     │     → bulk_vanished (token loss / corp disbanded — do NOT publish)
 *     └── (row reappears within 24h)
 *           → reappeared → watching (resets misses)
 */
class StructureDisappearanceTracking extends Model
{
    protected $table = 'structure_manager_disappearance_tracking';

    protected $fillable = [
        'structure_id',
        'last_seen_at',
        'last_known_state',
        'last_known_fuel_expires',
        'last_known_corporation_id',
        'last_known_type_id',
        'last_known_structure_name',
        'last_known_system_id',
        'last_known_system_name',
        'last_known_system_security',
        'consecutive_misses',
        'status',
        'detection_source',
        'resolved_at',
    ];

    protected $casts = [
        'last_seen_at'              => 'datetime',
        'last_known_fuel_expires'   => 'datetime',
        'last_known_system_security' => 'decimal:4',
        'consecutive_misses'        => 'integer',
        'resolved_at'               => 'datetime',
    ];

    /**
     * Combat-active states. If the structure was in one of these on its last
     * sighting and then vanished, the disappearance is treated as a destruction
     * (combat was actively in progress, no clean way to lose the row otherwise).
     */
    public const COMBAT_ACTIVE_STATES = [
        'shield_vulnerable',
        'armor_vulnerable',
        'hull_vulnerable',
        'shield_reinforce',
        'armor_reinforce',
        'hull_reinforce',
    ];

    /**
     * Number of consecutive missed polls before classifying. At 10-min cadence,
     * 3 misses = ~30 minutes absent — enough to rule out single-poll glitches
     * but quick enough that destruction events aren't delayed by hours.
     */
    public const MISS_THRESHOLD = 3;

    /**
     * Reappearance grace window — if a tracked structure reappears within this
     * many hours of its last sighting, treat as ESI glitch, NOT actual gap.
     */
    public const REAPPEARANCE_GRACE_HOURS = 24;

    public function scopeWatching($query)
    {
        return $query->where('status', 'watching');
    }

    public function scopeDestroyed($query)
    {
        return $query->where('status', 'destroyed');
    }

    /**
     * Was this structure in combat when last seen? Drives "destroyed" vs
     * "likely_transferred" classification.
     */
    public function getWasInCombatAttribute(): bool
    {
        return in_array((string) $this->last_known_state, self::COMBAT_ACTIVE_STATES, true);
    }
}
