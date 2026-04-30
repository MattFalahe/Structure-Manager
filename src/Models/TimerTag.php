<?php

namespace StructureManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Free-form label attached to a Timer row. Many tags per timer, one row
 * per (timer_id, tag) pair (unique constraint enforced at the DB level).
 *
 * Tags are normalized to lowercase at insertion via the `tag` setter.
 * Empty / whitespace-only strings are rejected by the controller before
 * reaching the model.
 *
 * @see Timer::tags()
 * @see migration 2025_10_01_000029_create_structure_manager_timer_tags_table
 */
class TimerTag extends Model
{
    protected $table = 'structure_manager_timer_tags';

    public $timestamps = false;

    protected $fillable = [
        'timer_id',
        'tag',
        'created_at',
    ];

    /**
     * Lower-case the tag at write time so lookups + display are
     * case-insensitive without ad-hoc casing in queries.
     */
    public function setTagAttribute(?string $value): void
    {
        if ($value === null) {
            $this->attributes['tag'] = null;
            return;
        }
        $this->attributes['tag'] = mb_strtolower(trim($value));
    }

    public function timer(): BelongsTo
    {
        return $this->belongsTo(Timer::class, 'timer_id');
    }
}
