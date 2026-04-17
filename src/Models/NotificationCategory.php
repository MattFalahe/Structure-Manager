<?php

namespace StructureManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A notification category (e.g. upwell.fuel, events.structure_attack, pos.strontium).
 *
 * Each category has:
 *  - A master enabled toggle
 *  - A default Discord role mention (used when a binding doesn't override)
 *  - A many-to-many relationship with webhooks via the category_webhook pivot
 *
 * The pivot row itself may carry a per-binding role mention override — see the
 * `role_mention_pivot` accessor in CategoryBinding / the pivot metadata.
 */
class NotificationCategory extends Model
{
    protected $table = 'structure_manager_notification_categories';

    protected $fillable = [
        'namespace',
        'category_key',
        'display_name',
        'description',
        'enabled',
        'role_mention',
        'role_source',
        'role_id',
        'sort_order',
    ];

    protected $casts = [
        'enabled'    => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * The webhooks this category fans out to.
     * Pivot carries enabled + role_mention override.
     */
    public function webhooks(): BelongsToMany
    {
        return $this->belongsToMany(
            WebhookConfiguration::class,
            'structure_manager_category_webhook',
            'category_id',
            'webhook_id'
        )->withPivot(['id', 'enabled', 'role_mention', 'role_source', 'role_id'])
         ->withTimestamps();
    }

    /**
     * Scope: only enabled categories.
     */
    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    /**
     * Scope: filter by namespace (upwell | events | pos).
     */
    public function scopeInNamespace($query, string $namespace)
    {
        return $query->where('namespace', $namespace);
    }

    /**
     * Look up a category by (namespace, key). Returns null if not found.
     */
    public static function forKey(string $namespace, string $categoryKey): ?self
    {
        return static::where('namespace', $namespace)
            ->where('category_key', $categoryKey)
            ->first();
    }

    /**
     * Fully qualified key for display/logging (e.g. "upwell.fuel").
     */
    public function getFullKeyAttribute(): string
    {
        return $this->namespace . '.' . $this->category_key;
    }
}
