<?php

namespace StructureManager\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Seat\Eveapi\Models\Corporation\CorporationInfo;
use Seat\Eveapi\Models\Corporation\CorporationStructure;
use StructureManager\Models\StructureDoctrine;
use StructureManager\Models\StructureManagerSettings;

/**
 * Structure-doctrine compliance: compare a corp's Upwell structures against
 * their recommended fits (StructureDoctrine). Ported from HR Manager. Reads
 * only SeAT core (CorporationStructure -> corporation_assets, solar_systems,
 * invTypes) — no ESI, standalone-safe.
 *
 * Matching: rigs + service modules + high/med/low modules are exact-or-upgrade
 * matched against the doctrine's required list (a higher-tier same-family
 * module satisfies a lower-tier requirement, and extra modules are fine). A
 * T2-where-T1 substitution or extra rig reads as "compliant + upgraded".
 * Fighters/ammo are presence-of-any gates (the type is the player's choice);
 * offline service modules optionally fail compliance.
 */
class StructureComplianceService
{
    public const SETTING_SCOPE          = 'structure_doctrine_scope';        // 'corp' | 'alliance'
    public const SETTING_OFFLINE_STRICT = 'structure_offline_noncompliant';  // bool

    public const COMPLIANT          = 'compliant';
    public const COMPLIANT_UPGRADED = 'compliant_upgraded';
    public const PARTIAL            = 'partial';
    public const NON_COMPLIANT      = 'non_compliant';
    public const NO_DOCTRINE        = 'no_doctrine';
    public const NO_DATA            = 'no_data';

    // -----------------------------------------------------------------
    // Settings
    // -----------------------------------------------------------------

    public function scopeMode(): string
    {
        return StructureManagerSettings::get(self::SETTING_SCOPE, 'corp') === 'alliance' ? 'alliance' : 'corp';
    }

    public function offlineStrict(): bool
    {
        return (bool) StructureManagerSettings::get(self::SETTING_OFFLINE_STRICT, false);
    }

    // -----------------------------------------------------------------
    // Public report
    // -----------------------------------------------------------------

    /**
     * Whether structure-fitting data is even available for a corp (corp assets
     * synced). When false, structures read NO_DATA rather than non-compliant.
     */
    public function corpHasAssets(int $corporationId): bool
    {
        if (!Schema::hasTable('corporation_assets')) {
            return false;
        }
        return DB::table('corporation_assets')->where('corporation_id', $corporationId)->limit(1)->exists();
    }

    /**
     * Security band for a structure from its solar system. WH systems are
     * named like J123456 (a "J" immediately followed by digits).
     */
    public function bandFor($structure): string
    {
        $system = $structure->solar_system;
        $name = $system ? (string) ($system->name ?? '') : '';
        if (preg_match('/^J\d/', $name)) {
            return 'wormhole';
        }
        $sec = $system ? (float) ($system->security ?? 0) : 0.0;
        if ($sec >= 0.45) {
            return 'highsec';
        }
        if ($sec > 0.0) {
            return 'lowsec';
        }
        return 'nullsec';
    }

    /**
     * Full compliance report for every Upwell structure a corp owns.
     */
    public function forCorporation(int $corporationId): array
    {
        if (!Schema::hasTable('corporation_structures')) {
            return ['available' => false, 'reason' => 'no_structures_table'];
        }

        $scopeMode     = $this->scopeMode();   // default scope for NEW doctrines (informational at match time)
        $offlineStrict = $this->offlineStrict();
        $hasAssets     = $this->corpHasAssets($corporationId);

        // A doctrine applies to a structure when it is scoped to the structure's
        // OWN corp, OR to that corp's alliance — matched regardless of the
        // per-corp/per-alliance toggle. The toggle is only the DEFAULT scope for
        // NEW doctrines; matching is forgiving, so a doctrine still applies if
        // the toggle was flipped after it was saved, or it was created from a
        // different page's corp context. Corp doctrines beat alliance ones for
        // the same type+band (more specific).
        $allianceId = optional(CorporationInfo::find($corporationId))->alliance_id;
        $allianceId = $allianceId ? (int) $allianceId : null;

        $doctrines = [];
        $typeBands = [];
        StructureDoctrine::active()
            ->where(function ($q) use ($corporationId, $allianceId) {
                $q->where(fn ($w) => $w->where('scope_type', 'corp')->where('scope_id', $corporationId));
                if ($allianceId) {
                    $q->orWhere(fn ($w) => $w->where('scope_type', 'alliance')->where('scope_id', $allianceId));
                }
            })
            ->get()
            ->sortBy(fn ($d) => $d->scope_type === 'corp' ? 1 : 0)   // corp processed last -> overwrites alliance
            ->each(function ($d) use (&$doctrines, &$typeBands) {
                $doctrines[$d->structure_type_id . '|' . $d->security_band] = $d;
                $typeBands[$d->structure_type_id][] = $d->security_band;
            });

        // Only eager-load the FITTED items (rigs / service modules / module
        // slots / fighters / cargo) — not the structure's entire hangar, which
        // on a busy Keepstar is thousands of rows. The slot accessors filter
        // this constrained set, so they still return the right modules.
        $structures = CorporationStructure::where('corporation_id', $corporationId)
            ->with([
                'items' => function ($q) {
                    $q->where(function ($w) {
                        foreach (['RigSlot', 'ServiceSlot', 'HiSlot', 'MedSlot', 'LoSlot', 'Fighter'] as $prefix) {
                            $w->orWhere('location_flag', 'like', $prefix . '%');
                        }
                        $w->orWhere('location_flag', 'Cargo');
                    })->with('type');
                },
                'services', 'type', 'solar_system', 'info',
            ])
            ->get();

        // Cross-scope diagnostic: every active doctrine for the types this corp
        // owns, regardless of scope/band. Lets a "no doctrine" structure show
        // exactly what exists and why it didn't match (band OR scope mismatch:
        // corp-vs-alliance mode, a different corp/alliance, or an unresolved
        // alliance). This is the usual cause of "I made the doctrine but it
        // still says no doctrine".
        $ownedTypeIds = $structures->pluck('type_id')->map(fn ($t) => (int) $t)->unique()->values()->all();
        $doctrinesForOwned = [];
        if (!empty($ownedTypeIds)) {
            StructureDoctrine::active()
                ->whereIn('structure_type_id', $ownedTypeIds)
                ->get(['structure_type_id', 'scope_type', 'scope_id', 'security_band', 'name'])
                ->each(function ($d) use (&$doctrinesForOwned) {
                    $doctrinesForOwned[(int) $d->structure_type_id][] = [
                        'scope_type' => $d->scope_type,
                        'scope_id'   => (int) $d->scope_id,
                        'band'       => $d->security_band,
                        'name'       => $d->name,
                    ];
                });
        }

        $rows = [];
        $summary = [
            self::COMPLIANT => 0, self::COMPLIANT_UPGRADED => 0, self::PARTIAL => 0,
            self::NON_COMPLIANT => 0, self::NO_DOCTRINE => 0, self::NO_DATA => 0,
        ];

        foreach ($structures as $structure) {
            $band     = $this->bandFor($structure);
            $doctrine = $doctrines[$structure->type_id . '|' . $band] ?? null;
            $eval     = $this->evaluate($structure, $doctrine, $offlineStrict, $hasAssets);

            $eval['band']           = $band;
            $eval['structure_name'] = $structure->info->name ?? ('Structure #' . $structure->structure_id);
            $eval['structure_type'] = $structure->type->typeName ?? ('Type ' . $structure->type_id);
            $eval['system']         = optional($structure->solar_system)->name ?? '';
            $eval['doctrine_name']  = $doctrine->name ?? null;
            $eval['other_bands']    = [];
            $eval['diag']           = null;

            if ($eval['status'] === self::NO_DOCTRINE) {
                $eval['other_bands'] = array_values(array_unique($typeBands[$structure->type_id] ?? []));
                $eval['diag']        = $this->noDoctrineDiag(
                    $doctrinesForOwned[(int) $structure->type_id] ?? [],
                    $corporationId,
                    $allianceId,
                    $band
                );
            }

            $rows[] = $eval;
            $summary[$eval['status']] = ($summary[$eval['status']] ?? 0) + 1;
        }

        // Sort worst-first so problems float to the top.
        $order = [
            self::NON_COMPLIANT => 0, self::PARTIAL => 1, self::NO_DATA => 2,
            self::NO_DOCTRINE => 3, self::COMPLIANT_UPGRADED => 4, self::COMPLIANT => 5,
        ];
        usort($rows, fn ($a, $b) => ($order[$a['status']] ?? 9) <=> ($order[$b['status']] ?? 9));

        return [
            'available'      => true,
            'scope_mode'     => $scopeMode,
            'offline_strict' => $offlineStrict,
            'has_assets'     => $hasAssets,
            'doctrine_count' => count($doctrines),
            'structures'     => $rows,
            'summary'        => $summary,
            'total'          => count($rows),
            // Buyback Manager handoff: its public appraisal route accepts a
            // raw item list + corporation_id, so we can POST the missing-items
            // shopping list straight to it. Only when BB is installed.
            'buyback_available' => \Illuminate\Support\Facades\Route::has('buyback.appraisal.create'),
        ];
    }

    /**
     * Why does a structure have no matching doctrine, given the doctrines that
     * DO exist for its type (across all scopes/bands)? Returns null when none
     * exist for the type at all (the plain "no doctrine" case).
     *
     * @param array<int,array{scope_type:string,scope_id:int,band:string,name:string}> $existing
     */
    private function noDoctrineDiag(array $existing, int $corporationId, ?int $allianceId, string $band): ?array
    {
        if (empty($existing)) {
            return null;
        }

        $appliesHere = array_filter($existing, fn ($e) =>
            ($e['scope_type'] === 'corp' && (int) $e['scope_id'] === $corporationId)
            || ($e['scope_type'] === 'alliance' && $allianceId !== null && (int) $e['scope_id'] === $allianceId));

        return [
            // Applies to this corp/alliance but wrong band, vs a different corp/alliance.
            'reason'   => !empty($appliesHere) ? 'band' : 'scope_id',
            'band'     => $band,
            'existing' => array_values($existing),
        ];
    }

    // -----------------------------------------------------------------
    // Per-structure evaluation
    // -----------------------------------------------------------------

    /**
     * Evaluate one structure against its doctrine. Returns the status + the
     * per-required-line breakdown the view renders as the EFT diff.
     */
    public function evaluate($structure, ?StructureDoctrine $doctrine, bool $offlineStrict, bool $hasAssets): array
    {
        $base = ['sections' => [], 'missing' => [], 'missing_raw' => '', 'current_raw' => '', 'recommended_raw' => '', 'has_lower_tier' => false, 'reasons' => [], 'optional' => []];

        if (!$doctrine) {
            return ['status' => self::NO_DOCTRINE] + $base;
        }

        // Actual fitted modules, tagged with their real slot (SeAT already
        // classifies them via the asset location_flag).
        $fitted = collect()
            ->merge($structure->high_slots->map(fn ($i) => $this->fittedItem($i, 'high')))
            ->merge($structure->medium_slots->map(fn ($i) => $this->fittedItem($i, 'med')))
            ->merge($structure->low_slots->map(fn ($i) => $this->fittedItem($i, 'low')))
            ->merge($structure->rig_slots->map(fn ($i) => $this->fittedItem($i, 'rig')))
            ->merge($structure->services_slots->map(fn ($i) => $this->fittedItem($i, 'service')))
            ->values()
            ->all();

        if (empty($fitted) && !$hasAssets) {
            return ['status' => self::NO_DATA] + array_merge($base, ['reasons' => ['no_corp_assets']]);
        }

        $parsed     = $doctrine->parsed ?? [];
        $required   = $parsed['required'] ?? [];
        $slotCounts = $parsed['slot_counts'] ?? [];

        // Backfill slot sections + slot counts for doctrines saved before they
        // were tracked, so the comparison works without a manual re-save.
        if (!empty($required) && !array_key_exists('section', (array) $required[0])) {
            $reparse = app(EftParser::class)->parse((string) ($doctrine->eft_raw ?? ''));
            if (!empty($reparse['required'])) {
                $required   = $reparse['required'];
                $slotCounts = $reparse['slot_counts'] ?? $slotCounts;
            }
        }

        // Group required + fitted by slot section.
        $reqBySection = [];
        foreach ($required as $req) {
            $reqBySection[$req['section'] ?? 'other'][] = $req;
        }
        $fitBySection = [];
        foreach ($fitted as $f) {
            $fitBySection[$f['section'] ?? 'other'][] = $f;
        }

        // Canonical EFT order; keep only sections that have something.
        $order = ['high', 'med', 'low', 'rig', 'service', 'other'];
        $sectionKeys = array_values(array_filter($order, fn ($s) =>
            !empty($reqBySection[$s]) || !empty($fitBySection[$s]) || !empty($slotCounts[$s])));
        foreach (array_keys($reqBySection + $fitBySection) as $s) {
            if (!in_array($s, $sectionKeys, true)) {
                $sectionKeys[] = $s;
            }
        }

        $sections     = [];
        $missing      = [];
        $hasUpgrade   = false;
        $hasLowerTier = false;
        $hasExtra     = false;

        foreach ($sectionKeys as $sec) {
            $reqList = $reqBySection[$sec] ?? [];
            $pool    = $fitBySection[$sec] ?? [];
            $claimed = array_fill(0, count($pool), false);
            $resolved = [];   // required index => ['state', 'current']

            // Pass 1 — exact (or operator-accepted) matches claim first, so an
            // exact match always wins over a same-family tier substitution.
            foreach ($reqList as $ri => $req) {
                $reqId      = (int) ($req['type_id'] ?? 0);
                $alsoAccept = array_map('intval', $req['also_accept'] ?? []);
                $idx = $this->findUnclaimed($pool, $claimed, fn ($f) => $f['type_id'] === $reqId || in_array($f['type_id'], $alsoAccept, true));
                if ($idx !== null) {
                    $claimed[$idx]  = true;
                    $resolved[$ri]  = ['state' => 'exact', 'current' => $pool[$idx]['type_name']];
                }
            }

            // Pass 2 — same family at a different tier: higher = upgraded
            // (green), lower = lower_tier (orange warning, still present).
            foreach ($reqList as $ri => $req) {
                if (isset($resolved[$ri])) {
                    continue;
                }
                $reqName = (string) ($req['type_name'] ?? '');
                $idx = $this->findUnclaimed($pool, $claimed, fn ($f) => $this->sameFamily($reqName, $f['type_name']));
                if ($idx === null) {
                    continue;
                }
                $claimed[$idx] = true;
                $ft = $this->tierRank($pool[$idx]['type_name']);
                $rt = $this->tierRank($reqName);
                $state = $ft > $rt ? 'upgraded' : ($ft < $rt ? 'lower_tier' : 'exact');
                $resolved[$ri] = ['state' => $state, 'current' => $pool[$idx]['type_name']];
            }

            // Leftover (unclaimed) structure modules in this section.
            $extras = [];
            foreach ($pool as $pi => $f) {
                if (empty($claimed[$pi])) {
                    $extras[] = $f['type_name'];
                }
            }
            $extraPtr = 0;

            // Build rows in doctrine order. An unsatisfied requirement pairs with
            // a leftover module in the SAME section -> "mismatch" (the slot is
            // filled, but with the wrong module), so a wrong-fit slot is ONE row,
            // not a missing row plus an extra row. Pure "missing" only when no
            // leftover module is left to occupy the slot.
            $rows = [];
            foreach ($reqList as $ri => $req) {
                $reqName = (string) ($req['type_name'] ?? '');
                if (isset($resolved[$ri])) {
                    $st = $resolved[$ri]['state'];
                    $rows[] = ['state' => $st, 'current' => $resolved[$ri]['current'], 'required' => $reqName];
                    if ($st === 'upgraded') {
                        $hasUpgrade = true;
                    } elseif ($st === 'lower_tier') {
                        $hasLowerTier = true;
                    }
                } else {
                    if ($extraPtr < count($extras)) {
                        $rows[] = ['state' => 'mismatch', 'current' => $extras[$extraPtr], 'required' => $reqName];
                        $extraPtr++;
                    } else {
                        $rows[] = ['state' => 'missing', 'current' => null, 'required' => $reqName];
                    }
                    $missing[] = $reqName;
                }
            }

            // Genuinely extra modules (more fitted than the doctrine accounts for).
            for ($i = $extraPtr; $i < count($extras); $i++) {
                $rows[]   = ['state' => 'extra', 'current' => $extras[$i], 'required' => null];
                $hasExtra = true;
            }

            // Pad with empty slots up to the section's real slot count, so the
            // structure's whole slot layout shows (e.g. 5 high slots even if 1 fitted).
            $slotCount = max((int) ($slotCounts[$sec] ?? 0), count($pool), count($rows));
            for ($i = count($rows); $i < $slotCount; $i++) {
                $rows[] = ['state' => 'empty', 'current' => null, 'required' => null];
            }

            $sections[] = ['section' => $sec, 'slot_count' => $slotCount, 'rows' => $rows];
        }

        // Presence + online gates.
        $reasons = [];
        if ($doctrine->require_fighters && $structure->fighters_bay->isEmpty()) {
            $reasons[] = 'no_fighters';
        }
        if ($doctrine->require_ammo && $structure->ammo_hold->isEmpty()) {
            $reasons[] = 'no_ammo';
        }
        if ($offlineStrict && $structure->services->contains(fn ($s) => ($s->state ?? '') === 'offline')) {
            $reasons[] = 'offline_services';
        }

        $gatesOk      = empty($reasons);
        $requiredTot  = count($required);
        $missingCount = count($missing);
        $matched      = $requiredTot - $missingCount;

        // Lower-tier is a warning, not a failure — it satisfies the slot.
        if ($missingCount === 0 && $gatesOk) {
            $status = ($hasUpgrade || $hasExtra) ? self::COMPLIANT_UPGRADED : self::COMPLIANT;
        } elseif ($matched === 0 && $requiredTot > 0) {
            $status = self::NON_COMPLIANT;
        } else {
            $status = self::PARTIAL;
        }

        // Name\tQty lists for the Copy / Buyback buttons (the EVE-standard
        // multibuy format every appraiser parses): the current fit, the
        // recommended fit, and the missing-to-complete list.
        return [
            'status'          => $status,
            'sections'        => $sections,
            'missing'         => $missing,
            'missing_raw'     => $this->rawList($missing),
            'current_raw'     => $this->rawList(array_map(fn ($f) => $f['type_name'], $fitted)),
            'recommended_raw' => $this->rawList(array_map(fn ($r) => (string) ($r['type_name'] ?? ''), $required)),
            'has_lower_tier'  => $hasLowerTier,
            'reasons'         => $reasons,
            'optional'        => $parsed['optional'] ?? [],
        ];
    }

    /**
     * Aggregate a list of type names into a tab-separated "Name<TAB>Qty"
     * multibuy list (the EVE-standard format in-game multibuy, Janice,
     * Evepraisal and Buyback Manager all parse). Returns '' when empty.
     *
     * @param array<int,?string> $names
     */
    private function rawList(array $names): string
    {
        $names = array_filter($names, fn ($n) => $n !== null && $n !== '');
        if (empty($names)) {
            return '';
        }
        $agg = array_count_values($names);
        return implode("\n", array_map(
            fn ($name, $qty) => $name . "\t" . $qty,
            array_keys($agg),
            array_values($agg)
        ));
    }

    // -----------------------------------------------------------------
    // Matching helpers
    // -----------------------------------------------------------------

    private function fittedItem($item, string $section): array
    {
        return [
            'type_id'   => (int) $item->type_id,
            'type_name' => $item->type->typeName ?? ('Type ' . $item->type_id),
            'section'   => $section,
        ];
    }

    /**
     * First pool index that satisfies $pred AND isn't already claimed.
     */
    private function findUnclaimed(array $pool, array $claimed, callable $pred): ?int
    {
        foreach ($pool as $i => $item) {
            if (empty($claimed[$i]) && $pred($item)) {
                return $i;
            }
        }
        return null;
    }

    /**
     * Same module family (ignoring tier)? Name-family heuristic — structures
     * only fit consistently named "Standup …" modules. The tier comparison
     * (upgraded vs lower_tier) is done by the caller via tierRank().
     */
    private function sameFamily(string $a, string $b): bool
    {
        return $this->familyBase($a) === $this->familyBase($b);
    }

    private function familyBase(string $name): string
    {
        return $this->norm(preg_replace('/\s+[IVX]{1,4}$/', '', trim($name)));
    }

    private function tierRank(string $name): int
    {
        if (preg_match('/\s+([IVX]{1,4})$/', trim($name), $m)) {
            return $this->romanToInt($m[1]);
        }
        return 1;
    }

    private function romanToInt(string $r): int
    {
        $map = ['I' => 1, 'II' => 2, 'III' => 3, 'IV' => 4, 'V' => 5, 'VI' => 6, 'VII' => 7, 'VIII' => 8, 'IX' => 9, 'X' => 10];
        return $map[strtoupper($r)] ?? 1;
    }

    private function norm(string $s): string
    {
        return mb_strtolower(trim($s));
    }
}
