<?php

namespace StructureManager\Helpers;

/**
 * Fuel and reagent thresholds — single source of truth.
 *
 * Hybrid model: Upwell thresholds are LOCKED IN CODE; POS thresholds are
 * CONFIGURABLE per install via the Settings UI. Rationale:
 *
 * UPWELL (locked):
 *   Upwell structures (citadels, refineries, Metenoxes, etc.) live in
 *   relatively predictable security space and respond similarly to fuel
 *   depletion across deployments. Locking at 7d/14d gives one source of
 *   truth across every display surface (list, detail, board, webhooks,
 *   Critical Alerts) so settings-vs-UI drift is structurally impossible.
 *
 * POS (configurable):
 *   POSes can be anchored anywhere — high-sec, low-sec, null-sec, AND
 *   WORMHOLES. Wormhole POS deployments in particular need extended
 *   response time because forming defense requires scouting wormhole
 *   chains, which can take hours. A 7-day fuel critical threshold may
 *   be totally inadequate for a J-space corp who needs 14-21 days of
 *   warning to safely arrange logistics.
 *
 *   POS thresholds therefore stay configurable, matching the v1.0.11
 *   release behavior. Display surfaces all read from these settings via
 *   the static methods below so the v1.0.11 settings-vs-UI drift bug
 *   (where the UI hardcoded 7/14 even when admins changed the values)
 *   stays fixed.
 *
 * The notification-cadence settings (intervals between repeat alerts during
 * critical state, zero-strontium spam guards) are kept configurable for
 * both POS and Upwell — those are operator-preference (channel chattiness),
 * not ops-correctness.
 */
final class FuelThresholds
{
    // ============================================================
    // Upwell structures (citadels, engineering complexes, refineries,
    // Athanors, Tataras, Metenox, Ansiblex, Pharolux, Tenebrex)
    // ============================================================

    /**
     * Upwell structure fuel: critical threshold in days.
     * Below this, the structure is at imminent risk of going offline.
     * Embeds render red, board rows tagged fuel_critical.
     */
    public const UPWELL_FUEL_CRITICAL_DAYS = 7;

    /**
     * Upwell structure fuel: warning threshold in days.
     * Below this, fuel is getting low and operators should plan deliveries.
     * Embeds render yellow, board rows tagged fuel_warning.
     */
    public const UPWELL_FUEL_WARNING_DAYS = 14;

    // ============================================================
    // POS / Control Towers (legacy starbases)
    //
    // POS thresholds are CONFIGURABLE per install — see Settings >
    // POS > Alert Thresholds. Wormhole POSes especially need extended
    // response time (hours of scouting chains before defense can form),
    // so a fixed 7-day critical threshold is inappropriate for them.
    //
    // The constants below are the DEFAULT values used as fallback when
    // no setting is configured. The static `posXxx()` methods read from
    // settings with these as fallback. Use the methods, not the constants,
    // when reading from application code.
    // ============================================================

    /** POS fuel critical default (overridable via pos_fuel_critical_days setting) */
    public const POS_FUEL_CRITICAL_DAYS_DEFAULT = 7;
    /** POS fuel warning default (overridable via pos_fuel_warning_days setting) */
    public const POS_FUEL_WARNING_DAYS_DEFAULT = 14;
    /** POS strontium critical default (overridable via pos_strontium_critical_hours setting) */
    public const POS_STRONTIUM_CRITICAL_HOURS_DEFAULT = 6;
    /** POS strontium warning default (overridable via pos_strontium_warning_hours setting) */
    public const POS_STRONTIUM_WARNING_HOURS_DEFAULT = 12;
    /** POS strontium "good" target default (used for percentage display + recommendations) */
    public const POS_STRONTIUM_GOOD_HOURS_DEFAULT = 24;
    /** POS charter critical default (overridable via pos_charter_critical_days setting) */
    public const POS_CHARTER_CRITICAL_DAYS_DEFAULT = 7;

    public static function posFuelCritical(): int
    {
        return (int) \StructureManager\Models\StructureManagerSettings::get(
            'pos_fuel_critical_days', self::POS_FUEL_CRITICAL_DAYS_DEFAULT
        );
    }

    public static function posFuelWarning(): int
    {
        return (int) \StructureManager\Models\StructureManagerSettings::get(
            'pos_fuel_warning_days', self::POS_FUEL_WARNING_DAYS_DEFAULT
        );
    }

    public static function posStrontiumCritical(): int
    {
        return (int) \StructureManager\Models\StructureManagerSettings::get(
            'pos_strontium_critical_hours', self::POS_STRONTIUM_CRITICAL_HOURS_DEFAULT
        );
    }

    public static function posStrontiumWarning(): int
    {
        return (int) \StructureManager\Models\StructureManagerSettings::get(
            'pos_strontium_warning_hours', self::POS_STRONTIUM_WARNING_HOURS_DEFAULT
        );
    }

    public static function posStrontiumGood(): int
    {
        return (int) \StructureManager\Models\StructureManagerSettings::get(
            'pos_strontium_good_hours', self::POS_STRONTIUM_GOOD_HOURS_DEFAULT
        );
    }

    public static function posCharterCritical(): int
    {
        return (int) \StructureManager\Models\StructureManagerSettings::get(
            'pos_charter_critical_days', self::POS_CHARTER_CRITICAL_DAYS_DEFAULT
        );
    }

    // ============================================================
    // Structure data staleness
    //
    // When a corporation removes its ESI key (or its director token is
    // revoked / expires), SeAT can no longer refresh that corp's
    // corporation_structures rows. The data freezes — fuel_expires drifts
    // months into the past and the UI would otherwise render a nonsensical
    // "-142d remaining" CRITICAL alert for an Upwell structure SeAT can no
    // longer actually see.
    //
    // Operators can hide UPWELL structures whose row has not been refreshed
    // in this many days. CONFIGURABLE via the stale_structure_threshold_days
    // setting. 0 disables the feature (every structure stays visible).
    //
    // POSes are intentionally EXEMPT: a corporation_starbases row only
    // changes when the tower's state/settings change, so a stable or
    // offline POS keeps a static row and its updated_at freezes even while
    // ESI polling is perfectly healthy. Using updated_at age would hide
    // healthy towers. The POS state column is the correct activity signal.
    // See PosManagerController::getPosesData().
    // ============================================================

    /** Default: hide structures not refreshed by ESI in 30+ days. */
    public const STALE_STRUCTURE_THRESHOLD_DAYS_DEFAULT = 30;

    /**
     * Configured staleness threshold in days. 0 = feature disabled.
     */
    public static function staleStructureThresholdDays(): int
    {
        return (int) \StructureManager\Models\StructureManagerSettings::get(
            'stale_structure_threshold_days', self::STALE_STRUCTURE_THRESHOLD_DAYS_DEFAULT
        );
    }

    /**
     * Cutoff datetime — Upwell structure rows with updated_at OLDER than
     * this are stale and hidden from the Upwell list views. Returns null
     * when the feature is disabled (threshold = 0) = "show everything".
     * Not applied to POSes (see the class-level note above).
     */
    public static function staleStructureCutoff(): ?\Carbon\Carbon
    {
        $days = self::staleStructureThresholdDays();
        return $days > 0 ? \Carbon\Carbon::now()->subDays($days) : null;
    }

    // ============================================================
    // Cyno reagents (Pharolux beacon liquid ozone, Tenebrex jammer
    // strontium clathrates). Quantity-based not time-based since
    // consumption rate depends on usage frequency.
    // ============================================================

    /** Pharolux Cyno Beacon liquid ozone: critical qty (low operations). */
    public const PHAROLUX_LIQUID_OZONE_CRITICAL_QTY = 5000;
    /** Pharolux Cyno Beacon liquid ozone: warning qty. */
    public const PHAROLUX_LIQUID_OZONE_WARNING_QTY = 25000;

    /** Tenebrex Cyno Jammer strontium clathrates: critical qty. */
    public const TENEBREX_STRONTIUM_CRITICAL_QTY = 10000;
    /** Tenebrex Cyno Jammer strontium clathrates: warning qty. */
    public const TENEBREX_STRONTIUM_WARNING_QTY = 50000;

    // ============================================================
    // Convenience: hours equivalents for views that reason in hours
    // ============================================================

    public static function upwellFuelCriticalHours(): int
    {
        return self::UPWELL_FUEL_CRITICAL_DAYS * 24;
    }

    public static function upwellFuelWarningHours(): int
    {
        return self::UPWELL_FUEL_WARNING_DAYS * 24;
    }

    public static function posFuelCriticalHours(): int
    {
        return self::posFuelCritical() * 24;
    }

    public static function posFuelWarningHours(): int
    {
        return self::posFuelWarning() * 24;
    }

    /**
     * Bundle for view injection: pass to a view via compact() or @json so
     * Blade templates and inline JS can consume thresholds without each
     * having to import the class.
     *
     * Returns CURRENT values — POS values reflect the operator's settings
     * (with constant defaults as fallback); Upwell values are the locked
     * constants. Re-read on every request, so changes in Settings take
     * effect on the next page load.
     *
     * @return array<string,int>
     */
    public static function forViews(): array
    {
        return [
            // Upwell — locked
            'upwell_fuel_critical_days'  => self::UPWELL_FUEL_CRITICAL_DAYS,
            'upwell_fuel_warning_days'   => self::UPWELL_FUEL_WARNING_DAYS,
            'upwell_fuel_critical_hours' => self::upwellFuelCriticalHours(),
            'upwell_fuel_warning_hours'  => self::upwellFuelWarningHours(),

            // POS — configurable, read live from settings each call
            'pos_fuel_critical_days'     => self::posFuelCritical(),
            'pos_fuel_warning_days'      => self::posFuelWarning(),
            'pos_fuel_critical_hours'    => self::posFuelCriticalHours(),
            'pos_fuel_warning_hours'     => self::posFuelWarningHours(),
            'pos_strontium_critical'     => self::posStrontiumCritical(),
            'pos_strontium_warning'      => self::posStrontiumWarning(),
            'pos_strontium_good'         => self::posStrontiumGood(),
            'pos_charter_critical_days'  => self::posCharterCritical(),

            // Cyno reagents — locked
            'pharolux_liquid_ozone_critical' => self::PHAROLUX_LIQUID_OZONE_CRITICAL_QTY,
            'pharolux_liquid_ozone_warning'  => self::PHAROLUX_LIQUID_OZONE_WARNING_QTY,
            'tenebrex_strontium_critical'    => self::TENEBREX_STRONTIUM_CRITICAL_QTY,
            'tenebrex_strontium_warning'     => self::TENEBREX_STRONTIUM_WARNING_QTY,
        ];
    }
}
