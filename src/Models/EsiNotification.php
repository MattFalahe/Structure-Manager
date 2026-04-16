<?php

namespace StructureManager\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

/**
 * Stores ESI notifications polled directly from director characters.
 *
 * Deduplicates by CCP's notification_id (globally unique). Tracks
 * whether a webhook has been dispatched via the `processed` flag.
 */
class EsiNotification extends Model
{
    protected $table = 'structure_manager_esi_notifications';

    protected $fillable = [
        'notification_id',
        'character_id',
        'corporation_id',
        'type',
        'sender_id',
        'sender_type',
        'timestamp',
        'text',
        'parsed_data',
        'source',
        'processed',
        'processed_at',
    ];

    protected $casts = [
        'parsed_data' => 'array',
        'processed' => 'boolean',
    ];

    protected $dates = [
        'timestamp',
        'processed_at',
        'created_at',
        'updated_at',
    ];

    /**
     * Mark this notification as processed (webhook sent).
     */
    public function markProcessed(): void
    {
        $this->processed = true;
        $this->processed_at = Carbon::now();
        $this->save();
    }

    /**
     * Check if a notification_id has already been recorded.
     */
    public static function exists(int $notificationId): bool
    {
        return self::where('notification_id', $notificationId)->exists();
    }

    /**
     * Scope to get unprocessed notifications.
     */
    public function scopeUnprocessed($query)
    {
        return $query->where('processed', false);
    }

    /**
     * Scope to get notifications by type category.
     */
    public function scopeAttackTypes($query)
    {
        return $query->whereIn('type', [
            'StructureUnderAttack',
            'StructureLostShields',
            'StructureLostArmor',
            'StructureDestroyed',
            'SkyhookUnderAttack',
            'SkyhookLostShields',
            'SkyhookDestroyed',
        ]);
    }
}
