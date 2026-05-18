<?php

namespace StructureManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tier 2 — suspect-narrowing forensic candidates for withdrawal_* events.
 *
 * One row per (fuel_history_id, character_id). Created by
 * WithdrawalForensicsService::computeCandidates() and persisted by
 * WithdrawalForensicsJob after the hourly poll classifies an event as
 * withdrawal_bay or withdrawal_reserves.
 *
 * HARD ESI LIMIT: this is PROBABILISTIC inference, not deterministic
 * attribution. ESI does not expose actor identity for asset moves, so
 * these rows reflect "who collaterally MATCHED the event" not "who DID it".
 *
 * Confidence buckets:
 *   - HIGH   — score >= 60 (multiple signals matched, strong correlation)
 *   - MEDIUM — score 30-59 (some signals matched)
 *   - LOW    — score 10-29 (weak match, mostly online-during-window only)
 *
 * Rows with score < 10 are not stored (too noisy — every member of a 50-corp
 * online during the poll window would otherwise generate a row).
 */
class StructureFuelEventCandidate extends Model
{
    protected $table = 'structure_fuel_event_candidates';

    public const CONFIDENCE_HIGH   = 'HIGH';
    public const CONFIDENCE_MEDIUM = 'MEDIUM';
    public const CONFIDENCE_LOW    = 'LOW';

    /** Score thresholds used by WithdrawalForensicsService. */
    public const THRESHOLD_HIGH   = 60;
    public const THRESHOLD_MEDIUM = 30;
    public const THRESHOLD_STORE  = 10;

    /**
     * Signal keys stored inside the `signals` JSON column. Each present
     * key indicates that signal matched; absent keys indicate no match.
     */
    public const SIGNAL_ONLINE_WINDOW    = 'online_during_window';
    public const SIGNAL_ASSET_GAIN       = 'asset_gain_match';
    public const SIGNAL_HAS_ROLE         = 'has_role';
    public const SIGNAL_WALLET_SALE      = 'wallet_sale_match';

    protected $fillable = [
        'fuel_history_id',
        'character_id',
        'character_name',
        'corporation_id',
        'confidence',
        'score',
        'signals',
    ];

    protected $casts = [
        'signals' => 'array',
        'score' => 'integer',
    ];

    public function event()
    {
        return $this->belongsTo(StructureFuelHistory::class, 'fuel_history_id', 'id');
    }

    /**
     * Pick the confidence bucket for a score. Centralized so the
     * service, model, and any future test cases agree.
     */
    public static function bucketForScore(int $score): ?string
    {
        if ($score >= self::THRESHOLD_HIGH) {
            return self::CONFIDENCE_HIGH;
        }
        if ($score >= self::THRESHOLD_MEDIUM) {
            return self::CONFIDENCE_MEDIUM;
        }
        if ($score >= self::THRESHOLD_STORE) {
            return self::CONFIDENCE_LOW;
        }
        return null; // do not store
    }

    /**
     * Bootstrap-style CSS class for the confidence badge.
     */
    public function confidenceBadgeClass(): string
    {
        switch ($this->confidence) {
            case self::CONFIDENCE_HIGH:
                return 'badge-danger';
            case self::CONFIDENCE_MEDIUM:
                return 'badge-warning';
            case self::CONFIDENCE_LOW:
            default:
                return 'badge-secondary';
        }
    }

    /**
     * Display label for a signal key. Falls back to the raw key if no
     * translation is found, so newly-added signals render without lang
     * updates.
     */
    public static function signalLabel(string $signal): string
    {
        $key = 'structure-manager::structure.fuel_signal_' . $signal;
        $translated = trans($key);
        return $translated === $key ? ucwords(str_replace('_', ' ', $signal)) : $translated;
    }
}
