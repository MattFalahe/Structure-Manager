<?php

namespace StructureManager\Helpers;

/**
 * Classification of CCP structure timer mechanics by structure type.
 *
 * EVE Online's Upwell structures don't all share the same reinforce cycle.
 * Large/XL structures (Fortizar/Tatara/Keepstar/Sotiyo/Azbel/Palatine) get
 * THREE separate reinforce phases: shield → armor reinforce → armor
 * vulnerable → hull reinforce → hull vulnerable → destroyed. Defenders get
 * TWO opportunities to form fleet (armor cycle end + hull cycle end).
 *
 * Medium Upwell structures (Astrahus, Raitaru, Athanor) and the Equinox-era
 * single-cycle types (Metenox, Skyhook) and FLEX navigation structures
 * (Ansiblex, Pharolux, Tenebrex) skip the hull reinforce timer entirely.
 * After the armor cycle elapses, the armor vulnerable phase opens, and if
 * defenders fail, the hull comes down in the SAME window with no second
 * reinforce break. Effectively the armor cycle IS the final defense window.
 *
 * This helper provides the single source of truth for "is this timer the
 * final defense window?" — used by the Discord embed builders, the
 * Structure Board view, and the EventBus payload to warn operators
 * accordingly. Operators reading "Armor Reinforced — 23h to decloak" on
 * an Athanor need to know that's their ONLY fight; the same wording on a
 * Fortizar gives them a follow-up hull timer.
 *
 * Sources (current as of post-Equinox 2026):
 *   - https://support.eveonline.com/hc/en-us/articles/208289385-Upwell-Structures-Vulnerability-States
 *   - https://wiki.eveuniversity.org/Vulnerability
 *     "Medium and abandoned structures skip the structure reinforcement
 *      state and go straight to vulnerable structure."
 *   - https://www.eveonline.com/news/view/equinox-expansion-notes
 *
 * Type list is hardcoded (not SDE-queried) because:
 *   1. The list is small + stable (~8 structure types) — CCP doesn't add
 *      new structures often
 *   2. Avoids a per-alert DB hit for every embed render
 *   3. SeAT v5's invTypes table doesn't have a clean "is medium / final
 *      timer" attribute — the SDE schema doesn't carry CCP's reinforce
 *      design intent as a queryable field
 *   4. Game-design metadata belongs in code where it can be reviewed
 *      against CCP patch notes, not silently inferred from a data table
 *
 * When CCP introduces a new structure with single-cycle reinforce
 * mechanics, add its typeID to ARMOR_IS_FINAL_TYPE_IDS below and update
 * the corresponding tests/help docs in the same commit.
 */
class StructureTimerMechanics
{
    /**
     * TypeIDs where the armor cycle IS the final defense window. After this
     * cycle elapses (or for FLEX, after shields hit 0), no separate hull
     * reinforce timer follows — the structure can be destroyed in the same
     * vulnerability window.
     *
     * Three families included:
     *   - Medium Upwell (Astrahus, Raitaru, Athanor) — skip hull reinforce
     *   - FLEX navigation (Ansiblex, Pharolux, Tenebrex) — no armor
     *     reinforce at all, immediate vulnerability after shield drop
     *   - Equinox single-cycle (Metenox Moon Drill, Orbital Skyhook)
     *
     * NOT included (have a separate hull reinforce timer):
     *   - Azbel, Fortizar, Tatara (large)
     *   - Sotiyo, Keepstar, Palatine Keepstar (xlarge)
     */
    public const ARMOR_IS_FINAL_TYPE_IDS = [
        // Medium Upwell — armor cycle is the final defense
        TypeIdRegistry::ASTRAHUS,        // 35832
        TypeIdRegistry::RAITARU,         // 35825
        TypeIdRegistry::ATHANOR,         // 35835

        // FLEX navigation — no armor reinforce, direct vulnerability
        TypeIdRegistry::ANSIBLEX_JUMP_GATE, // 35841
        TypeIdRegistry::PHAROLUX_BEACON,    // 35840
        TypeIdRegistry::TENEBREX_JAMMER,    // 37534

        // Equinox single-cycle structures
        TypeIdRegistry::METENOX,            // 81826
        TypeIdRegistry::ORBITAL_SKYHOOK,    // 81080
    ];

    /**
     * Does the armor cycle end this structure's defense (no hull reinforce
     * follow-up)? Returns false for unknown typeIDs — safe default that
     * under-warns rather than mislabels a large structure as "FINAL".
     *
     * @param int|null $typeId Structure typeID from corporation_structures.type_id
     * @return bool true if armor cycle is the final timer for this structure type
     */
    public static function armorIsFinal(?int $typeId): bool
    {
        if ($typeId === null || $typeId <= 0) {
            return false;
        }
        return in_array($typeId, self::ARMOR_IS_FINAL_TYPE_IDS, true);
    }

    /**
     * Is THIS specific timer event the final defense window for its
     * structure? Combines the event_type with the structure typeID — a
     * reinforce_armor on an Athanor is final, but the same event_type on
     * a Fortizar is not.
     *
     * Returns true for:
     *   - reinforce_armor on a final-at-armor structure (the planning timer
     *     operators schedule fleet around — most actionable for reminders)
     *   - reinforce_hull on a final-at-armor structure (defensive — the
     *     hull cycle "row" we may create from StructureLostArmor for a
     *     medium represents the structure dying NOW with no future timer)
     *
     * @param string   $eventType  Timer event_type (reinforce_armor, reinforce_hull, etc.)
     * @param int|null $typeId     Structure typeID
     */
    public static function isFinalTimer(string $eventType, ?int $typeId): bool
    {
        if (!self::armorIsFinal($typeId)) {
            return false;
        }
        return in_array($eventType, ['reinforce_armor', 'reinforce_hull'], true);
    }

    /**
     * Operator-facing message explaining the FINAL TIMER situation.
     * Surfaced in Discord embeds + pre-timer reminders + board tooltips
     * so operators reading the alert immediately understand the stakes.
     *
     * Returns null when not applicable so callers can `?? ''` cleanly.
     */
    public static function finalTimerMessage(?int $typeId, string $eventType = 'reinforce_armor'): ?string
    {
        if (!self::isFinalTimer($eventType, $typeId)) {
            return null;
        }

        if ($eventType === 'reinforce_hull') {
            // Armor already fell — structure is in hull vulnerability NOW.
            // No future timer; this is "dying right now" messaging.
            return 'FINAL STAGE — armor has fallen, hull is exposed with no reinforce break. Structure will be destroyed if not defended immediately.';
        }

        // reinforce_armor — the planning timer. Most common surface.
        return 'FINAL TIMER — no hull reinforce follows. If armor falls during the vulnerability window, the structure is destroyed in the same pass.';
    }

    /**
     * Short label for embed titles / board badges. Returns null when not
     * applicable so callers can conditionally append.
     */
    public static function finalTimerBadge(?int $typeId, string $eventType = 'reinforce_armor'): ?string
    {
        if (!self::isFinalTimer($eventType, $typeId)) {
            return null;
        }
        return 'FINAL TIMER';
    }
}
