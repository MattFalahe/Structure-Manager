<?php

namespace StructureManager\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

/**
 * Represents a director character in the ESI fast-polling key pool.
 *
 * Admins assign characters to this pool via the settings UI.
 * The polling job round-robins through enabled key holders,
 * tracking per-character health and skipping failed/expired ones.
 */
class EsiKeyHolder extends Model
{
    protected $table = 'structure_manager_esi_key_holders';

    protected $fillable = [
        'character_id',
        'corporation_id',
        'character_name',
        'enabled',
        'last_polled_at',
        'last_poll_status',
        'last_error',
        'consecutive_failures',
        'total_polls',
        'total_notifications_found',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'consecutive_failures' => 'integer',
        'total_polls' => 'integer',
        'total_notifications_found' => 'integer',
    ];

    protected $dates = [
        'last_polled_at',
        'created_at',
        'updated_at',
    ];

    /**
     * Get enabled key holders ordered by least-recently-polled (round-robin).
     */
    public static function getNextInRotation(int $count = 2): \Illuminate\Database\Eloquent\Collection
    {
        return self::where('enabled', true)
            ->where(function ($q) {
                // Skip characters that have failed 5+ times in a row
                // (they need admin attention — token revoked, etc.)
                $q->where('consecutive_failures', '<', 5)
                  ->orWhereNull('consecutive_failures');
            })
            ->orderBy('last_polled_at', 'asc') // least-recently-polled first
            ->orderBy('id', 'asc')             // deterministic tiebreak
            ->limit($count)
            ->get();
    }

    /**
     * Get all eligible characters from SeAT that could be added to the pool.
     * These are characters with Director role + notifications ESI scope.
     *
     * Returns characters NOT already in the key pool.
     */
    public static function getEligibleCharacters(): \Illuminate\Support\Collection
    {
        return \DB::table('refresh_tokens as rt')
            ->join('character_affiliations as ca', 'rt.character_id', '=', 'ca.character_id')
            ->leftJoin('character_infos as ci', 'rt.character_id', '=', 'ci.character_id')
            ->leftJoin('corporation_infos as corp', 'ca.corporation_id', '=', 'corp.corporation_id')
            ->whereNull('rt.deleted_at')
            // Must have Director role
            ->whereExists(function ($query) {
                $query->select(\DB::raw(1))
                    ->from('corporation_roles')
                    ->whereColumn('corporation_roles.character_id', 'rt.character_id')
                    ->where('corporation_roles.scope', 'roles')
                    ->where('corporation_roles.role', 'Director');
            })
            // Exclude characters already in the pool
            ->whereNotIn('rt.character_id', function ($query) {
                $query->select('character_id')
                    ->from('structure_manager_esi_key_holders');
            })
            ->select(
                'rt.character_id',
                'ci.name as character_name',
                'ca.corporation_id',
                'corp.name as corporation_name',
                'rt.scopes',
                'rt.expires_on'
            )
            ->orderBy('corp.name')
            ->orderBy('ci.name')
            ->get()
            ->map(function ($row) {
                // Check if character has the notification scope
                $scopes = $row->scopes;
                if (is_string($scopes)) {
                    $scopes = json_decode($scopes, true) ?? [];
                }
                $row->has_notification_scope = is_array($scopes)
                    && in_array('esi-characters.read_notifications.v1', $scopes);
                $row->token_expired = $row->expires_on
                    && Carbon::parse($row->expires_on)->lt(Carbon::now());
                return $row;
            });
    }

    /**
     * Record a successful poll.
     */
    public function recordSuccess(int $notificationsFound = 0): void
    {
        $this->last_polled_at = Carbon::now();
        $this->last_poll_status = 'success';
        $this->last_error = null;
        $this->consecutive_failures = 0;
        $this->total_polls++;
        $this->total_notifications_found += $notificationsFound;
        $this->save();
    }

    /**
     * Record a failed poll.
     */
    public function recordFailure(string $status, string $error): void
    {
        $this->last_polled_at = Carbon::now();
        $this->last_poll_status = $status;
        $this->last_error = $error;
        $this->consecutive_failures++;
        $this->total_polls++;
        $this->save();
    }

    /**
     * Check if this key holder's token has the required notification scope.
     */
    public function hasNotificationScope(): bool
    {
        $token = \Seat\Eveapi\Models\RefreshToken::find($this->character_id);
        if (!$token) {
            return false;
        }

        $scopes = $token->scopes ?? [];
        if (is_string($scopes)) {
            $scopes = json_decode($scopes, true) ?? [];
        }

        return in_array('esi-characters.read_notifications.v1', $scopes);
    }

    /**
     * Get a human-readable health status for display.
     */
    public function getHealthStatus(): string
    {
        if (!$this->enabled) {
            return 'disabled';
        }
        if ($this->consecutive_failures >= 5) {
            return 'suspended';
        }
        if ($this->last_poll_status === 'token_expired' || $this->last_poll_status === 'scope_missing') {
            return 'needs_attention';
        }
        if ($this->last_poll_status === 'failed' || $this->last_poll_status === 'rate_limited') {
            return 'degraded';
        }
        if ($this->last_poll_status === 'success') {
            return 'healthy';
        }
        return 'unknown';
    }

    /**
     * Get a CSS badge class for the health status.
     */
    public function getHealthBadgeClass(): string
    {
        $map = [
            'healthy' => 'badge-success',
            'degraded' => 'badge-warning',
            'needs_attention' => 'badge-danger',
            'suspended' => 'badge-dark',
            'disabled' => 'badge-secondary',
            'unknown' => 'badge-info',
        ];

        return $map[$this->getHealthStatus()] ?? 'badge-secondary';
    }
}
