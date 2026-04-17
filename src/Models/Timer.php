<?php

namespace StructureManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

/**
 * A Structure Board timer row.
 *
 * See migration 000023 for schema details + visibility model explanation.
 *
 * Category groups (derived from event_type, used by client-side filter):
 *   - 'fuel'      — fuel_warning, fuel_critical, fuel_final
 *   - 'tactical'  — reinforce_*, hostile_op, defense_op
 *   - 'lifecycle' — anchor_*, unanchor_*
 */
class Timer extends Model
{
    protected $table = 'structure_manager_timers';

    protected $fillable = [
        'source',
        'event_type',
        'severity',
        'structure_id',
        'structure_name',
        'structure_type',
        'structure_type_id',
        'system_id',
        'system_name',
        'system_security',
        'corporation_id',
        'role_id',
        'group_id',
        'owner_corporation_name',
        'attacker_corporation_name',
        'eve_time',
        'notes',
        'dismissed_at',
        'created_by_user_id',
        'source_reference',
    ];

    protected $casts = [
        'eve_time'         => 'datetime',
        'dismissed_at'     => 'datetime',
        'system_security'  => 'decimal:4',
    ];

    /**
     * Event type → category group mapping. Used for the master filter chips
     * and for any grouped-rendering logic.
     */
    public const EVENT_GROUPS = [
        // fuel
        'fuel_warning'         => 'fuel',
        'fuel_critical'        => 'fuel',
        'fuel_final'           => 'fuel',
        // tactical (attacks + manual ops)
        'reinforce_shield'     => 'tactical',
        'reinforce_armor'      => 'tactical',
        'reinforce_hull'       => 'tactical',
        'hostile_op'           => 'tactical',
        'defense_op'           => 'tactical',
        // lifecycle (anchoring / ownership changes)
        'anchor_start'         => 'lifecycle',
        'anchor_complete'      => 'lifecycle',
        'unanchor_start'       => 'lifecycle',
        'unanchor_complete'    => 'lifecycle',
        'ownership_transferred' => 'lifecycle',
    ];

    public const ALL_GROUPS = ['fuel', 'tactical', 'lifecycle'];

    // ============================================================
    // Relationships
    // ============================================================

    /**
     * The SeAT role gating this timer (if any). Nullable.
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(\Seat\Web\Models\Acl\Role::class, 'role_id');
    }

    /**
     * The user who created this timer (null for auto-generated).
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(\Seat\Web\Models\User::class, 'created_by_user_id');
    }

    /**
     * Corporation (if set). Optional — the row might be global (null).
     */
    public function corporation(): BelongsTo
    {
        return $this->belongsTo(\Seat\Eveapi\Models\Corporation\CorporationInfo::class, 'corporation_id', 'corporation_id');
    }

    // ============================================================
    // Scopes
    // ============================================================

    /**
     * Filter timers visible to a given user. Applies:
     *   - corporation scope (null OR matches user's corp IDs)
     *   - role gate (null OR matches user's role IDs)
     *
     * Admins with structure-manager.admin (or superuser) bypass both filters.
     */
    public function scopeVisibleTo($query, $user)
    {
        if (!$user) {
            // Anonymous — shouldn't reach here given 'auth' middleware, but defensive
            return $query->whereRaw('1=0');
        }

        // Admin bypass: see everything
        if ($user->isAdmin() || $user->can('structure-manager.admin')) {
            return $query;
        }

        $corpIds  = $this->resolveUserCorpIds($user);
        $roleIds  = $user->roles->pluck('id')->all();

        return $query->where(function ($q) use ($corpIds) {
                $q->whereNull('corporation_id');
                if (!empty($corpIds)) {
                    $q->orWhereIn('corporation_id', $corpIds);
                }
            })
            ->where(function ($q) use ($roleIds) {
                $q->whereNull('role_id');
                if (!empty($roleIds)) {
                    $q->orWhereIn('role_id', $roleIds);
                }
            });
    }

    /**
     * Active (not dismissed) timers.
     */
    public function scopeActive($query)
    {
        return $query->whereNull('dismissed_at');
    }

    /**
     * Timers within a given look-ahead window. Also includes recently-elapsed
     * timers (within 2 hours of now) so they remain on the board briefly for
     * post-event reference (matches raikia's "Current" cutoff).
     */
    public function scopeWithinWindow($query, int $daysAhead = 7)
    {
        $start = Carbon::now()->subHours(2);
        $end   = Carbon::now()->addDays($daysAhead);
        return $query->whereBetween('eve_time', [$start, $end]);
    }

    /**
     * Filter by category group(s).
     */
    public function scopeInGroups($query, array $groups)
    {
        $types = array_keys(array_filter(
            self::EVENT_GROUPS,
            fn($group) => in_array($group, $groups, true)
        ));
        return $query->whereIn('event_type', $types);
    }

    // ============================================================
    // Accessors
    // ============================================================

    /**
     * Which category group this timer belongs to (fuel / tactical / lifecycle).
     */
    public function getCategoryGroupAttribute(): ?string
    {
        return self::EVENT_GROUPS[$this->event_type] ?? null;
    }

    /**
     * Is this timer elapsed (eve_time already passed)?
     */
    public function getIsElapsedAttribute(): bool
    {
        return $this->eve_time !== null && $this->eve_time->isPast();
    }

    /**
     * Structure image URL for thumbnails. Uses type_id if available,
     * otherwise falls back to a generic Astrahus render.
     */
    public function getStructureImageAttribute(): string
    {
        $typeId = $this->structure_type_id ?? 35832; // Astrahus fallback
        return "https://images.evetech.net/types/{$typeId}/render?size=64";
    }

    /**
     * Is this timer visible only to a specific role?
     */
    public function getIsRoleGatedAttribute(): bool
    {
        return $this->role_id !== null;
    }

    /**
     * Is this a global (all-corps) timer?
     */
    public function getIsGlobalAttribute(): bool
    {
        return $this->corporation_id === null;
    }

    // ============================================================
    // Helpers
    // ============================================================

    /**
     * Derive the list of corporation IDs the given user has a character in.
     *
     * Uses character_affiliations joined via the user's linked characters.
     * Works whether the user's characters come from user_characters or the
     * newer SeAT v5 main/linked-character model by pulling all user-owned
     * refresh_tokens → characters → corporations.
     *
     * Cached per-request via a static lookup to avoid repeated queries.
     */
    protected function resolveUserCorpIds($user): array
    {
        static $cache = [];
        $userId = $user->id;

        if (isset($cache[$userId])) {
            return $cache[$userId];
        }

        // Pull all characters the user has associated + their current corp.
        // SeAT v5 pattern: user has many character_infos via refresh_tokens.
        $characterIds = \DB::table('refresh_tokens')
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->pluck('character_id')
            ->all();

        if (empty($characterIds)) {
            return $cache[$userId] = [];
        }

        $corpIds = \DB::table('character_affiliations')
            ->whereIn('character_id', $characterIds)
            ->pluck('corporation_id')
            ->unique()
            ->filter()
            ->values()
            ->all();

        return $cache[$userId] = $corpIds;
    }

    /**
     * Public wrapper so controllers can reuse the same derivation
     * (e.g. to populate the filter dropdown with the user's corps).
     */
    public static function getUserCorpIds($user): array
    {
        return (new self())->resolveUserCorpIds($user);
    }

    // ============================================================
    // Factory helpers for auto-generation (used by dispatcher jobs in Phase 1b)
    // ============================================================

    /**
     * Upsert an auto-generated timer. Uses the unique dedup key
     * (source, event_type, structure_id, eve_time) so repeated calls
     * don't create duplicate rows.
     *
     * @param array $attrs fillable fields
     * @return self
     */
    public static function upsertAuto(array $attrs): self
    {
        $dedupKey = [
            'source'       => $attrs['source'],
            'event_type'   => $attrs['event_type'],
            'structure_id' => $attrs['structure_id'] ?? null,
            'eve_time'     => $attrs['eve_time'],
        ];

        return self::updateOrCreate($dedupKey, $attrs);
    }

    /**
     * Mark dismissed (soft — row stays for audit, just hidden by Active scope).
     */
    public function dismiss(?int $userId = null): void
    {
        $this->dismissed_at = Carbon::now();
        $this->save();
    }

    /**
     * Clear a dismissal (undismiss).
     */
    public function undismiss(): void
    {
        $this->dismissed_at = null;
        $this->save();
    }
}
