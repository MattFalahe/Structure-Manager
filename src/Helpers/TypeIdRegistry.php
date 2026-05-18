<?php

namespace StructureManager\Helpers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Centralized type-ID registry for Structure Manager.
 *
 * Single source of truth for every EVE Online type ID the plugin
 * references, plus SDE-derived helpers that resolve relationships
 * (e.g. "what fuel block does a Domination Control Tower Medium use").
 *
 * Named to match Mining Manager's `MiningManager\Services\TypeIdRegistry`
 * for cross-plugin consistency. Same intent, scoped to SM-relevant types.
 * EVE-wide bits will eventually graduate to a shared registry in Manager
 * Core (Phase 3 of the migration plan); for now SM owns the canonical copy.
 *
 * ============================================================================
 * HARDCODED ON PURPOSE — DO NOT AUTO-DERIVE FROM SDE
 * ============================================================================
 *
 * The constants in this file (typeIDs, names, fuel rates, faction modifiers,
 * service-to-module mappings) are HARDCODED VALUES verified against the
 * actual EVE game data. They are NOT auto-derived from SeAT's SDE because
 * SeAT's SDE has historically had drift / bugs that produced wrong answers.
 *
 * Real bugs we have hit and fixed by hardcoding:
 *
 *   1. Tenebrex Cyno Jammer typeID
 *      Older SDE imports listed it as 35839 (which is actually the
 *      unpublished "Large Observatory Array" debug type). The real
 *      published typeID is 37534. Verified in-game and against ESI.
 *      See FuelCalculator::STRUCTURE_TYPES history, ~Apr 2026.
 *
 *   2. Faction Control Tower fuel modifiers
 *      Community wikis (and some SDE imports) claimed 25% / 50% fuel
 *      reductions for faction / officer towers. The actual values from
 *      `invControlTowerResources` quantities are 10% / 20% (modifier
 *      0.9 / 0.8). Hardcoded in PosFuelCalculator::FACTION_FUEL_MODIFIERS;
 *      do NOT replace with a community-rate calculation.
 *
 *   3. Service module fuel rates
 *      The mapping from CCP-emitted service name strings (e.g.
 *      "Manufacturing (Capitals)") to internal module slugs and per-hour
 *      fuel block consumption is not in the SDE in any consumable form.
 *      Hardcoded SERVICE_TO_MODULE_MAP + SERVICE_FUEL_RATES below.
 *      Verified against in-game observations 2025; update only when CCP
 *      ships a service balance change.
 *
 * If you are tempted to replace any constant here with an SDE query
 * (`invTypes.typeName`, `dgmTypeAttributes` lookup, "the SDE has this
 * already" intuition, etc.) — check the file history first. Most of
 * these constants exist precisely because the SDE was wrong.
 *
 * Reliable SDE sources we DO trust (used in this file):
 *
 *   - `invControlTowerResources`
 *       Per-tower fuel block resource type + hourly quantity. CCP keeps
 *       this current; the existing PosFuelCalculator::getStaticFuelRequirements
 *       has used it reliably for years. The two SDE-backed methods in this
 *       class (racialFuelForTower / hourlyFuelRateForTower) query it.
 *
 *   - `mapDenormalize`
 *       Solar system metadata (security, region). Reliable, used outside
 *       this file via direct queries; not exposed through this registry
 *       because it's high-cardinality.
 *
 * Everything else in this file: hardcoded values are the source of truth.
 *
 * ============================================================================
 *
 * Existing constants in `FuelCalculator` and `PosFuelCalculator` will
 * stay as deprecated aliases pointing here so consumers can migrate
 * incrementally without breakage.
 *
 * Style: prefer constants for static lists, methods for SDE lookups.
 * Methods cache in-process per request to avoid repeat queries.
 */
class TypeIdRegistry
{
    // ===================================================================
    // Fuel blocks (Upwell + Metenox + POS racial)
    // ===================================================================

    /** Nitrogen Fuel Block (Caldari) */
    public const NITROGEN  = 4051;
    /** Hydrogen Fuel Block (Minmatar) */
    public const HYDROGEN  = 4246;
    /** Helium Fuel Block (Amarr) */
    public const HELIUM    = 4247;
    /** Oxygen Fuel Block (Gallente) */
    public const OXYGEN    = 4312;

    /** All four fuel block typeIDs (use for whereIn / array_keys lookups). */
    public const FUEL_BLOCK_IDS = [
        self::NITROGEN,
        self::HYDROGEN,
        self::HELIUM,
        self::OXYGEN,
    ];

    /** Fuel block typeID → display name (for UI). */
    public const FUEL_BLOCK_NAMES = [
        self::NITROGEN => 'Nitrogen Fuel Block',
        self::HYDROGEN => 'Hydrogen Fuel Block',
        self::HELIUM   => 'Helium Fuel Block',
        self::OXYGEN   => 'Oxygen Fuel Block',
    ];

    /** Fuel block typeID → race string (caldari/minmatar/amarr/gallente). */
    public const FUEL_BLOCK_TO_RACE = [
        self::NITROGEN => 'caldari',
        self::HYDROGEN => 'minmatar',
        self::HELIUM   => 'amarr',
        self::OXYGEN   => 'gallente',
    ];

    /** Race string → racial fuel block typeID. Inverse of FUEL_BLOCK_TO_RACE. */
    public const RACE_TO_FUEL_BLOCK = [
        'caldari'  => self::NITROGEN,
        'minmatar' => self::HYDROGEN,
        'amarr'    => self::HELIUM,
        'gallente' => self::OXYGEN,
    ];

    // ===================================================================
    // Other consumables
    // ===================================================================

    /** Magmatic Gas — Metenox dual-fuel companion to fuel blocks. */
    public const MAGMATIC_GAS = 81143;

    /** Strontium Clathrates — POS reinforcement reagent. */
    public const STRONTIUM = 16275;

    // ===================================================================
    // Starbase (POS) charters — required in high-sec
    // ===================================================================

    public const CHARTER_AMARR    = 24592;
    public const CHARTER_CALDARI  = 24593;
    public const CHARTER_GALLENTE = 24594;
    public const CHARTER_MINMATAR = 24595;
    public const CHARTER_KHANID   = 24596;
    public const CHARTER_AMMATAR  = 24597;

    public const CHARTER_IDS = [
        self::CHARTER_AMARR,
        self::CHARTER_CALDARI,
        self::CHARTER_GALLENTE,
        self::CHARTER_MINMATAR,
        self::CHARTER_KHANID,
        self::CHARTER_AMMATAR,
    ];

    public const CHARTER_NAMES = [
        self::CHARTER_AMARR    => 'Amarr Empire Starbase Charter',
        self::CHARTER_CALDARI  => 'Caldari State Starbase Charter',
        self::CHARTER_GALLENTE => 'Gallente Federation Starbase Charter',
        self::CHARTER_MINMATAR => 'Minmatar Republic Starbase Charter',
        self::CHARTER_KHANID   => 'Khanid Kingdom Starbase Charter',
        self::CHARTER_AMMATAR  => 'Ammatar Mandate Starbase Charter',
    ];

    // ===================================================================
    // Upwell structures (typeIDs the plugin reasons about)
    // ===================================================================

    /** Metenox Moon Drill — deployable, dual-fuel (blocks + magmatic gas). */
    public const METENOX = 81826;

    /** Engineering Complexes (manufacturing / research). */
    public const RAITARU = 35825;
    public const AZBEL   = 35826;
    public const SOTIYO  = 35827;

    /** Citadels (docking / market hub when configured). */
    public const ASTRAHUS         = 35832;
    public const FORTIZAR         = 35833;
    public const KEEPSTAR         = 35834;
    public const PALATINE_KEEPSTAR = 40340;

    /** Refineries (moon mining / reprocessing). */
    public const ATHANOR = 35835;
    public const TATARA  = 35836;

    /** Navigation structures. */
    public const ANSIBLEX_JUMP_GATE = 35841;
    public const PHAROLUX_BEACON    = 35840;
    public const TENEBREX_JAMMER    = 37534; // SDE-verified; 35839 was the unpublished test type

    /**
     * Orbital Skyhook (Equinox-era planetary extraction structure).
     * Single reinforce cycle — armor cycle is the final defense window.
     * Verified against EVE Ref typeID 81080 (groupID 4736 / category Orbitals).
     */
    public const ORBITAL_SKYHOOK = 81080;

    /**
     * Every Upwell typeID the plugin knows about, mapped to a human-friendly
     * group name. Used for breakdowns + display formatting.
     */
    public const UPWELL_TYPE_IDS = [
        self::RAITARU         => ['name' => 'Raitaru',          'category' => 'engineering', 'size' => 'medium'],
        self::AZBEL           => ['name' => 'Azbel',            'category' => 'engineering', 'size' => 'large'],
        self::SOTIYO          => ['name' => 'Sotiyo',           'category' => 'engineering', 'size' => 'xlarge'],
        self::ASTRAHUS        => ['name' => 'Astrahus',         'category' => 'citadel',     'size' => 'medium'],
        self::FORTIZAR        => ['name' => 'Fortizar',         'category' => 'citadel',     'size' => 'large'],
        self::KEEPSTAR        => ['name' => 'Keepstar',         'category' => 'citadel',     'size' => 'xlarge'],
        self::PALATINE_KEEPSTAR => ['name' => 'Palatine Keepstar', 'category' => 'citadel',  'size' => 'xlarge'],
        self::ATHANOR         => ['name' => 'Athanor',          'category' => 'refinery',    'size' => 'medium'],
        self::TATARA          => ['name' => 'Tatara',           'category' => 'refinery',    'size' => 'large'],
        self::ANSIBLEX_JUMP_GATE => ['name' => 'Ansiblex Jump Gate', 'category' => 'navigation', 'size' => 'medium'],
        self::PHAROLUX_BEACON => ['name' => 'Pharolux Cyno Beacon', 'category' => 'navigation', 'size' => 'medium'],
        self::TENEBREX_JAMMER => ['name' => 'Tenebrex Cyno Jammer', 'category' => 'navigation', 'size' => 'medium'],
        self::METENOX         => ['name' => 'Metenox Moon Drill', 'category' => 'deployable', 'size' => 'medium'],
        self::ORBITAL_SKYHOOK => ['name' => 'Orbital Skyhook',     'category' => 'orbital',    'size' => 'medium'],
    ];

    // ===================================================================
    // POS Control Towers — typeIDs, sizes, faction tiers, fuel modifiers
    // ===================================================================
    //
    // 42 control tower variants from the Player Owned Starbase era. Three
    // tiers, all hardcoded:
    //
    //   T1       (modifier 1.0)  — base fuel consumption per size
    //   Faction  (modifier 0.9)  — 10% fuel reduction (5 pirate factions × 3 sizes)
    //   Officer  (modifier 0.8)  — 20% fuel reduction (5 pirate factions × 3 sizes)
    //
    // Base fuel consumption per size (verified against invControlTowerResources):
    //
    //   small   = 10 blocks / hour
    //   medium  = 20 blocks / hour
    //   large   = 40 blocks / hour
    //
    // Effective rate = POS_BASE_FUEL_RATES[size] × tower modifier.
    //
    // Race intentionally NOT stored here — use racialFuelForTower(typeId)
    // which queries invControlTowerResources for the authoritative racial
    // fuel block. The SDE is reliable for that one lookup; storing race
    // here would just be a hand-curated map waiting to drift.
    //
    // IMPORTANT: community wikis often claimed faction towers had 25%/50%
    // bonuses. Those numbers are WRONG — actual modifiers from the SDE
    // quantity column are 10% and 20%. Do not "correct" these values to
    // match a wiki.

    /** Base fuel consumption per tower size (blocks/hour, no modifier applied). */
    public const POS_BASE_FUEL_RATES = [
        'small'  => 10,
        'medium' => 20,
        'large'  => 40,
    ];

    /**
     * POS tower metadata. Each entry:
     *
     *   'name'     => display name
     *   'size'     => 'small' | 'medium' | 'large'
     *   'faction'  => 'T1' | 'Faction' | 'Officer'
     *   'modifier' => 1.0 | 0.9 | 0.8 (multiplier on the size base rate)
     *
     * Race is NOT stored — use racialFuelForTower($typeId) to query the SDE.
     */
    public const POS_TOWERS = [
        // ---------- T1 Racial Towers (modifier 1.0) ----------
        // Large
        12235 => ['name' => 'Amarr Control Tower',           'size' => 'large',  'faction' => 'T1', 'modifier' => 1.0],
        16213 => ['name' => 'Caldari Control Tower',         'size' => 'large',  'faction' => 'T1', 'modifier' => 1.0],
        12236 => ['name' => 'Gallente Control Tower',        'size' => 'large',  'faction' => 'T1', 'modifier' => 1.0],
        16214 => ['name' => 'Minmatar Control Tower',        'size' => 'large',  'faction' => 'T1', 'modifier' => 1.0],
        // Medium
        20059 => ['name' => 'Amarr Control Tower Medium',    'size' => 'medium', 'faction' => 'T1', 'modifier' => 1.0],
        20061 => ['name' => 'Caldari Control Tower Medium',  'size' => 'medium', 'faction' => 'T1', 'modifier' => 1.0],
        20063 => ['name' => 'Gallente Control Tower Medium', 'size' => 'medium', 'faction' => 'T1', 'modifier' => 1.0],
        20065 => ['name' => 'Minmatar Control Tower Medium', 'size' => 'medium', 'faction' => 'T1', 'modifier' => 1.0],
        // Small
        20060 => ['name' => 'Amarr Control Tower Small',     'size' => 'small',  'faction' => 'T1', 'modifier' => 1.0],
        20062 => ['name' => 'Caldari Control Tower Small',   'size' => 'small',  'faction' => 'T1', 'modifier' => 1.0],
        20064 => ['name' => 'Gallente Control Tower Small',  'size' => 'small',  'faction' => 'T1', 'modifier' => 1.0],
        20066 => ['name' => 'Minmatar Control Tower Small',  'size' => 'small',  'faction' => 'T1', 'modifier' => 1.0],

        // ---------- Faction Towers (modifier 0.9, -10% fuel) ----------
        // Large
        27539 => ['name' => 'Angel Control Tower',           'size' => 'large',  'faction' => 'Faction', 'modifier' => 0.9],
        27530 => ['name' => 'Blood Control Tower',           'size' => 'large',  'faction' => 'Faction', 'modifier' => 0.9],
        27533 => ['name' => 'Guristas Control Tower',        'size' => 'large',  'faction' => 'Faction', 'modifier' => 0.9],
        27780 => ['name' => 'Sansha Control Tower',          'size' => 'large',  'faction' => 'Faction', 'modifier' => 0.9],
        27536 => ['name' => 'Serpentis Control Tower',       'size' => 'large',  'faction' => 'Faction', 'modifier' => 0.9],
        // Medium
        27607 => ['name' => 'Angel Control Tower Medium',    'size' => 'medium', 'faction' => 'Faction', 'modifier' => 0.9],
        27589 => ['name' => 'Blood Control Tower Medium',    'size' => 'medium', 'faction' => 'Faction', 'modifier' => 0.9],
        27595 => ['name' => 'Guristas Control Tower Medium', 'size' => 'medium', 'faction' => 'Faction', 'modifier' => 0.9],
        27782 => ['name' => 'Sansha Control Tower Medium',   'size' => 'medium', 'faction' => 'Faction', 'modifier' => 0.9],
        27601 => ['name' => 'Serpentis Control Tower Medium','size' => 'medium', 'faction' => 'Faction', 'modifier' => 0.9],
        // Small
        27610 => ['name' => 'Angel Control Tower Small',     'size' => 'small',  'faction' => 'Faction', 'modifier' => 0.9],
        27592 => ['name' => 'Blood Control Tower Small',     'size' => 'small',  'faction' => 'Faction', 'modifier' => 0.9],
        27598 => ['name' => 'Guristas Control Tower Small',  'size' => 'small',  'faction' => 'Faction', 'modifier' => 0.9],
        27784 => ['name' => 'Sansha Control Tower Small',    'size' => 'small',  'faction' => 'Faction', 'modifier' => 0.9],
        27604 => ['name' => 'Serpentis Control Tower Small', 'size' => 'small',  'faction' => 'Faction', 'modifier' => 0.9],

        // ---------- Officer Towers (modifier 0.8, -20% fuel) ----------
        // Large
        27532 => ['name' => 'Dark Blood Control Tower',           'size' => 'large',  'faction' => 'Officer', 'modifier' => 0.8],
        27540 => ['name' => 'Domination Control Tower',           'size' => 'large',  'faction' => 'Officer', 'modifier' => 0.8],
        27535 => ['name' => 'Dread Guristas Control Tower',       'size' => 'large',  'faction' => 'Officer', 'modifier' => 0.8],
        27538 => ['name' => 'Shadow Control Tower',               'size' => 'large',  'faction' => 'Officer', 'modifier' => 0.8],
        27786 => ['name' => 'True Sansha Control Tower',          'size' => 'large',  'faction' => 'Officer', 'modifier' => 0.8],
        // Medium
        27591 => ['name' => 'Dark Blood Control Tower Medium',    'size' => 'medium', 'faction' => 'Officer', 'modifier' => 0.8],
        27609 => ['name' => 'Domination Control Tower Medium',    'size' => 'medium', 'faction' => 'Officer', 'modifier' => 0.8],
        27597 => ['name' => 'Dread Guristas Control Tower Medium','size' => 'medium', 'faction' => 'Officer', 'modifier' => 0.8],
        27603 => ['name' => 'Shadow Control Tower Medium',        'size' => 'medium', 'faction' => 'Officer', 'modifier' => 0.8],
        27788 => ['name' => 'True Sansha Control Tower Medium',   'size' => 'medium', 'faction' => 'Officer', 'modifier' => 0.8],
        // Small
        27594 => ['name' => 'Dark Blood Control Tower Small',     'size' => 'small',  'faction' => 'Officer', 'modifier' => 0.8],
        27612 => ['name' => 'Domination Control Tower Small',     'size' => 'small',  'faction' => 'Officer', 'modifier' => 0.8],
        27600 => ['name' => 'Dread Guristas Control Tower Small', 'size' => 'small',  'faction' => 'Officer', 'modifier' => 0.8],
        27606 => ['name' => 'Shadow Control Tower Small',         'size' => 'small',  'faction' => 'Officer', 'modifier' => 0.8],
        27790 => ['name' => 'True Sansha Control Tower Small',    'size' => 'small',  'faction' => 'Officer', 'modifier' => 0.8],
    ];

    /**
     * Look up a POS tower's full metadata.
     * Returns null when the typeID isn't a known control tower.
     */
    public static function posTower(int $typeId): ?array
    {
        return self::POS_TOWERS[$typeId] ?? null;
    }

    /**
     * Tower size: 'small' | 'medium' | 'large' | null (unknown).
     */
    public static function posTowerSize(int $typeId): ?string
    {
        return self::POS_TOWERS[$typeId]['size'] ?? null;
    }

    /**
     * Faction tier: 'T1' | 'Faction' | 'Officer' | null (unknown).
     */
    public static function posTowerFaction(int $typeId): ?string
    {
        return self::POS_TOWERS[$typeId]['faction'] ?? null;
    }

    /**
     * Fuel-consumption modifier (1.0 / 0.9 / 0.8). Returns 1.0 for
     * unknown towers (safe assumption: no bonus).
     */
    public static function posTowerModifier(int $typeId): float
    {
        return (float) (self::POS_TOWERS[$typeId]['modifier'] ?? 1.0);
    }

    /**
     * Computed hourly fuel rate (blocks/hour) for a tower.
     *
     * Prefers the SDE's invControlTowerResources.quantity since CCP keeps
     * that current and it's the authoritative game value. Falls back to
     * (POS_BASE_FUEL_RATES[size] × modifier) when the tower isn't in our
     * POS_TOWERS map AND the SDE doesn't have a row (truly unknown tower).
     *
     * Returns null only when the tower is completely unknown to both
     * sources.
     */
    public static function posTowerHourlyRate(int $typeId): ?float
    {
        // Authoritative path: SDE
        $sdeRate = self::hourlyFuelRateForTower($typeId);
        if ($sdeRate !== null) {
            return (float) $sdeRate;
        }

        // Hardcoded path: size base × modifier
        $tower = self::POS_TOWERS[$typeId] ?? null;
        if ($tower !== null) {
            $base = self::POS_BASE_FUEL_RATES[$tower['size']] ?? null;
            if ($base !== null) {
                return (float) ($base * $tower['modifier']);
            }
        }

        return null;
    }

    public static function isPosTower(int $typeId): bool
    {
        return array_key_exists($typeId, self::POS_TOWERS);
    }

    // ===================================================================
    // Upwell service modules + fuel consumption rates
    // ===================================================================
    //
    // EVE static data describing what each service module consumes per hour.
    // Used by FuelCalculator to estimate consumption from active services
    // when the SDE doesn't give us a direct typeID-to-rate mapping.
    //
    // Structure of the data:
    //   SERVICE_TO_MODULE_MAP    — what CCP calls the service over ESI
    //                              (case-sensitive, e.g. 'Manufacturing
    //                              (Capitals)') → our internal module slug
    //   SERVICE_FUEL_RATES       — module slug → fuel-rate config keyed by
    //                              context (base / engineering_bonus /
    //                              athanor_bonus / etc). The 'base' key is
    //                              always present; the bonus keys apply
    //                              when the service runs on a structure
    //                              type that grants that bonus.
    //
    // Why these live here (not in FuelCalculator constants any more):
    //   - Single source of truth, mirrors how Mining Manager's
    //     TypeIdRegistry centralizes ore consumption / refining yields.
    //   - Lets future plugins (e.g. an Industry plugin tracking job
    //     costs) consume the same data without depending on SM's
    //     calculator class.

    /**
     * Service name (CCP ESI string) → internal module slug.
     *
     * One module can provide multiple services (e.g. a Research Lab
     * provides Blueprint Copying + Material Efficiency Research + Time
     * Efficiency Research, but consumes fuel ONCE). Mapping by service
     * name lets us deduplicate to module slug before summing fuel rates.
     *
     * Service names are case-sensitive and must match the strings ESI
     * returns in the corp structures service array.
     */
    public const SERVICE_TO_MODULE_MAP = [
        // Research Lab (3 services share 1 module)
        'Blueprint Copying'             => 'research_lab',
        'Material Efficiency Research'  => 'research_lab',
        'Time Efficiency Research'      => 'research_lab',
        // Invention Lab
        'Invention'                     => 'invention_lab',
        // Manufacturing
        'Manufacturing (Standard)'      => 'manufacturing_plant',
        'Manufacturing (Capitals)'      => 'capital_shipyard',
        'Manufacturing (Supercapitals)' => 'supercapital_shipyard',
        // Refinery
        'Reprocessing'                  => 'reprocessing_facility',
        'Moon Drilling'                 => 'moon_drill',
        // Metenox deployable (separate from refinery moon drill)
        'Automatic Moon Drilling'       => 'metenox_moon_drill',
        // Reactors (refinery-only, low-sec/null-sec)
        'Composite Reactions'           => 'composite_reactor',
        'Biochemical Reactions'         => 'biochemical_reactor',
        'Hybrid Reactions'              => 'hybrid_reactor',
        // Citadel-flavor
        'Market'                        => 'market_hub',
        'Clone Bay'                     => 'cloning_center',
        // Navigation (flex structures)
        'Jump Gate'                     => 'ansiblex_jump_bridge',
        'Cynosural Beacon'              => 'pharolux_cyno_beacon',
        'Cynosural Jammer'              => 'tenebrex_cyno_jammer',
    ];

    /**
     * Module slug → fuel-rate config (blocks per hour).
     *
     * Bonus keys describe what rate the module uses on a given structure
     * type. 'base' is the fallback when no specific bonus applies.
     *
     *   citadel_bonus      — applies on citadel-class structures
     *   engineering_bonus  — applies on engineering-complex structures
     *   athanor_bonus      — applies on Athanor refineries (smaller bonus)
     *   tatara_bonus       — applies on Tatara refineries (larger bonus)
     *
     * Special case: metenox_moon_drill includes a `magmatic_gas` field
     * (200/hour) since Metenox is dual-fuel.
     *
     * Sources: EVE Online mechanics as of 2025; verified against
     * invControlTowerResources where applicable.
     */
    public const SERVICE_FUEL_RATES = [
        // Citadel services
        'cloning_center' => [
            'base'          => 10,
            'citadel_bonus' => 7.5,  // -25% on Citadels
        ],
        'market_hub' => [
            'base'          => 40,
            'citadel_bonus' => 30,   // -25% on Citadels
            'restrictions'  => 'Large/X-Large only',
        ],
        // Manufacturing & research
        'manufacturing_plant' => [
            'base'             => 12,
            'engineering_bonus' => 9, // -25% on Engineering Complexes
        ],
        'research_lab' => [
            'base'                       => 12,
            'engineering_bonus'          => 9,
            'faction_base'               => 10,  // Hyasyoda Research Lab variant
            'faction_engineering_bonus'  => 7.5,
        ],
        'invention_lab' => [
            'base'             => 12,
            'engineering_bonus' => 9,
        ],
        'capital_shipyard' => [
            'base'             => 24,
            'engineering_bonus' => 18,
            'restrictions'     => 'Large/X-Large only, no high-sec',
        ],
        'supercapital_shipyard' => [
            'base'             => 36,
            'engineering_bonus' => 27,
            'restrictions'     => 'Sotiyo only, sov null-sec only',
        ],
        // Refinery services
        'reprocessing_facility' => [
            'base'          => 10,
            'athanor_bonus' => 8,    // -20% on Athanor
            'tatara_bonus'  => 7.5,  // -25% on Tatara
        ],
        'moon_drill' => [
            'base'         => 5,
            // No bonuses — Moon Drill always 5 blocks/hour on ALL refineries
            'restrictions' => 'Refineries only',
        ],
        'composite_reactor' => [
            'base'          => 15,
            'athanor_bonus' => 12,    // -20% on Athanor
            'tatara_bonus'  => 11.25, // -25% on Tatara
            'restrictions'  => 'Refineries only, no high-sec',
        ],
        'biochemical_reactor' => [
            'base'          => 15,
            'athanor_bonus' => 12,
            'tatara_bonus'  => 11.25,
            'restrictions'  => 'Refineries only, no high-sec',
        ],
        'hybrid_reactor' => [
            'base'          => 15,
            'athanor_bonus' => 12,
            'tatara_bonus'  => 11.25,
            'restrictions'  => 'Refineries only, no high-sec',
        ],
        // Navigation (flex) structures
        'ansiblex_jump_bridge' => [
            'base'         => 30,
            'restrictions' => 'Requires sov, one per system',
        ],
        'pharolux_cyno_beacon' => [
            'base'         => 15,
            'restrictions' => 'Requires sov, one per system',
        ],
        'tenebrex_cyno_jammer' => [
            'base'         => 40,
            'restrictions' => 'Requires sov, up to 3 per system',
        ],
        // Metenox Moon Drill (deployable, not Upwell, dual-fuel)
        'metenox_moon_drill' => [
            'base'         => 5,    // 5 fuel blocks per hour
            'magmatic_gas' => 200,  // 200 magmatic gas per hour
            'note'         => 'Dual-fuel: 5 blocks/hour + 200 magmatic gas/hour (4,800/day). Magmatic gas often runs out BEFORE fuel blocks.',
            'restrictions' => 'Deployable structure, requires magmatic gas in addition to fuel blocks',
        ],
    ];

    /**
     * Look up a service's module slug. Returns null when the service
     * name isn't recognised (e.g. a brand-new service CCP added that
     * we haven't catalogued yet — in which case FuelCalculator falls
     * back to a typical-config estimate).
     */
    public static function moduleForService(string $serviceName): ?string
    {
        return self::SERVICE_TO_MODULE_MAP[$serviceName] ?? null;
    }

    /**
     * Look up a module's fuel-rate config. Returns null when the slug
     * isn't recognised. The returned array is the same shape as the
     * SERVICE_FUEL_RATES entry — caller picks 'base' or whichever bonus
     * key applies to the structure type it's running on.
     */
    public static function fuelRatesForModule(string $moduleSlug): ?array
    {
        return self::SERVICE_FUEL_RATES[$moduleSlug] ?? null;
    }

    /**
     * Convenience: deduplicate a list of CCP service names into the unique
     * module slugs they correspond to. Used by FuelCalculator to compute
     * "fuel cost per hour" without double-counting modules that provide
     * multiple services.
     *
     *   ['Blueprint Copying', 'Time Efficiency Research', 'Invention']
     *   → ['research_lab', 'invention_lab']
     *
     * @param string[] $serviceNames
     * @return string[] unique module slugs
     */
    public static function uniqueModulesForServices(array $serviceNames): array
    {
        $modules = [];
        foreach ($serviceNames as $service) {
            $slug = self::moduleForService($service);
            if ($slug !== null && !in_array($slug, $modules, true)) {
                $modules[] = $slug;
            }
        }
        return $modules;
    }

    // ===================================================================
    // SDE static-data lookup helpers
    // ===================================================================

    /**
     * Resolve a Control Tower's racial fuel block via the SDE.
     *
     * Queries `invControlTowerResources` (the authoritative table CCP
     * ships in the SDE). Every control tower — T1 racial, faction,
     * pirate, officer — has a row at purpose=1 (Power) listing its
     * fuel-block resourceTypeID. Looking that up directly avoids any
     * hand-curated racial map and stays correct as CCP adds new
     * tower variants.
     *
     * Returns null when the tower typeID isn't in the SDE or has no
     * Power row. The `invControlTowerResources` table absence falls
     * through to null too (e.g. SDE not yet imported on a fresh install).
     *
     * In-process cache keeps repeated calls cheap (e.g. a sweep over
     * 30 POSes hits the DB at most 30 times across distinct typeIDs).
     */
    public static function racialFuelForTower(int $towerTypeId): ?int
    {
        static $cache = [];
        if (array_key_exists($towerTypeId, $cache)) {
            return $cache[$towerTypeId];
        }

        $cache[$towerTypeId] = null;

        if (!Schema::hasTable('invControlTowerResources')) {
            return null;
        }

        $blockTypeId = DB::table('invControlTowerResources')
            ->where('controlTowerTypeID', $towerTypeId)
            ->whereIn('resourceTypeID', self::FUEL_BLOCK_IDS)
            ->where('purpose', 1) // Power = online fuel
            ->value('resourceTypeID');

        if ($blockTypeId !== null) {
            $cache[$towerTypeId] = (int) $blockTypeId;
        }
        return $cache[$towerTypeId];
    }

    /**
     * Resolve a Control Tower's hourly fuel consumption rate via the SDE.
     *
     * Same source as `racialFuelForTower`. Returns the quantity field on
     * the Power row, which is "fuel blocks consumed per cycle" and CCP
     * cycles are 1 hour, so this directly equals "blocks per hour".
     *
     * Returns null when the tower isn't in the SDE.
     */
    public static function hourlyFuelRateForTower(int $towerTypeId): ?int
    {
        static $cache = [];
        if (array_key_exists($towerTypeId, $cache)) {
            return $cache[$towerTypeId];
        }

        $cache[$towerTypeId] = null;

        if (!Schema::hasTable('invControlTowerResources')) {
            return null;
        }

        $rate = DB::table('invControlTowerResources')
            ->where('controlTowerTypeID', $towerTypeId)
            ->whereIn('resourceTypeID', self::FUEL_BLOCK_IDS)
            ->where('purpose', 1)
            ->value('quantity');

        if ($rate !== null) {
            $cache[$towerTypeId] = (int) $rate;
        }
        return $cache[$towerTypeId];
    }

    // ===================================================================
    // Type predicates
    // ===================================================================

    public static function isFuelBlock(int $typeId): bool
    {
        return in_array($typeId, self::FUEL_BLOCK_IDS, true);
    }

    public static function isCharter(int $typeId): bool
    {
        return in_array($typeId, self::CHARTER_IDS, true);
    }

    public static function isUpwellStructure(int $typeId): bool
    {
        return array_key_exists($typeId, self::UPWELL_TYPE_IDS);
    }

    public static function isMetenox(int $typeId): bool
    {
        return $typeId === self::METENOX;
    }

    public static function isMagmaticGas(int $typeId): bool
    {
        return $typeId === self::MAGMATIC_GAS;
    }

    public static function isStrontium(int $typeId): bool
    {
        return $typeId === self::STRONTIUM;
    }

    // ===================================================================
    // Pricing helpers (Upwell + Metenox can substitute among the 4 blocks)
    // ===================================================================

    /**
     * Pick the cheapest fuel-block typeID from a price map. Returns null
     * when none of the four blocks are priced.
     *
     * Returns:
     *   ['type_id' => int, 'type_name' => string, 'price' => float,
     *    'all_prices' => [type_id => ['name' => ..., 'price' => ...], ...]]
     *
     * Used by the Economics page to decide which fuel block to suggest
     * for substitutable structures. Keeping the chooser in this registry
     * (vs in the page service) lets future tools reuse it — e.g. the
     * Logistics Report could surface the same suggestion next to its
     * "blocks needed" totals.
     */
    public static function cheapestFuelBlock(array $prices): ?array
    {
        $available = [];
        foreach (self::FUEL_BLOCK_NAMES as $id => $name) {
            if (isset($prices[$id]) && $prices[$id] !== null) {
                $available[$id] = ['name' => $name, 'price' => (float) $prices[$id]];
            }
        }
        if (empty($available)) {
            return null;
        }

        $cheapestId = array_key_first($available);
        foreach ($available as $id => $info) {
            if ($info['price'] < $available[$cheapestId]['price']) {
                $cheapestId = $id;
            }
        }

        return [
            'type_id'    => $cheapestId,
            'type_name'  => $available[$cheapestId]['name'],
            'price'      => $available[$cheapestId]['price'],
            'all_prices' => $available,
        ];
    }
}
