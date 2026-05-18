<?php

namespace StructureManager\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Async killmail enrichment for `structure.alert.destroyed` events.
 *
 * Tier C Stage 2: when SM publishes `structure.alert.destroyed` (immediately
 * on CCP notification arrival, OR via the grace-period path after a
 * disappearance), an EnrichKillmailJob runs against zKillboard to find the
 * killmail, resolve attacker / ISK details, and publish a follow-up
 * `structure.alert.destroyed_confirmed` event correlated by `original_event_id`.
 *
 * One row per ever-destroyed structure (unique on structure_id). Survives
 * plugin restarts. Not synced to MC — purely SM-owned local store.
 *
 * @see EnrichKillmailJob
 * @see migration 2025_10_01_000027_create_killmail_enrichments_table
 */
class StructureKillmailEnrichment extends Model
{
    protected $table = 'structure_manager_killmail_enrichments';

    public const STATUS_PENDING   = 'pending';
    public const STATUS_ENRICHED  = 'enriched';
    public const STATUS_NOT_FOUND = 'not_found';

    protected $fillable = [
        'structure_id',
        'corporation_id',
        'structure_type_id',
        'system_id',
        'destroyed_at',
        'original_event_id',

        'status',
        'attempts',
        'last_attempted_at',
        'enriched_at',
        'gave_up_at',
        'published_at',

        'killmail_id',
        'killmail_hash',
        'killmail_url',
        'killmail_time',

        'final_blow_character_id',
        'final_blow_character_name',
        'final_blow_corporation_id',
        'final_blow_corporation_name',
        'final_blow_alliance_id',
        'final_blow_alliance_name',
        'final_blow_ship_type_id',
        'final_blow_ship_type',

        'top_damage_character_id',
        'top_damage_character_name',
        'top_damage_ship_type_id',
        'top_damage_ship_type',

        'attacker_count',
        'isk_value',
        'zkb_points',
    ];

    protected $casts = [
        'destroyed_at'      => 'datetime',
        'last_attempted_at' => 'datetime',
        'enriched_at'       => 'datetime',
        'gave_up_at'        => 'datetime',
        'published_at'      => 'datetime',
        'killmail_time'     => 'datetime',

        'attempts'          => 'integer',
        'isk_value'         => 'decimal:2',
        'zkb_points'        => 'integer',
        'attacker_count'    => 'integer',
    ];

    /**
     * Pending enrichments awaiting zKB success or retry-budget exhaustion.
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Enrichments that gave up after exhausting retry budget — useful for
     * "structures we know we lost but zKB never carried the kill" reports.
     */
    public function scopeNotFound($query)
    {
        return $query->where('status', self::STATUS_NOT_FOUND);
    }

    /**
     * Successfully enriched — has full killmail + attacker details.
     */
    public function scopeEnriched($query)
    {
        return $query->where('status', self::STATUS_ENRICHED);
    }

    /**
     * Has the stage 2 destroyed_confirmed event already been published for
     * this enrichment row? Used by the job to skip republishing on retry.
     */
    public function hasPublishedConfirmedEvent(): bool
    {
        return $this->published_at !== null;
    }

    /**
     * Mark this enrichment as in a terminal state (enriched OR not_found)
     * AND record that the stage 2 event has fired. Single transition point
     * keeps the publish-idempotency guard in one place.
     */
    public function markPublished(): void
    {
        $this->published_at = Carbon::now();
        $this->save();
    }
}
