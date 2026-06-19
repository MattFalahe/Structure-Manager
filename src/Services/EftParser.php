<?php

namespace StructureManager\Services;

use Illuminate\Support\Facades\DB;

/**
 * Parse an EFT-format fit into a structured doctrine. Ported from HR Manager.
 *
 * EFT shape:
 *   [HullType, Fit name]
 *   <fitted module>             (one per line, no quantity)
 *   <fitted module>, <charge>   (module with a loaded charge)
 *
 *   <item> xN                   (quantity items: cargo / ammo / fighters / drones)
 *
 * Blank lines delimit the in-game export blocks (low, med, high, rig, service),
 * which we use to tag each module with its slot section for the grouped diff.
 *
 * Names resolve to type_ids via the SDE (invTypes.typeName). Unresolved names
 * are returned so the editor can flag typos before saving.
 */
class EftParser
{
    /**
     * @return array{
     *   ok: bool, hull_type_id: ?int, hull_type_name: ?string,
     *   required: array<int,array{type_id:int,type_name:string,section:string,also_accept:array}>,
     *   optional: array<int,array{type_id:int,type_name:string,quantity:int}>,
     *   slot_counts: array<string,int>,
     *   unresolved: array<int,string>, error: ?string
     * }
     */
    public function parse(string $eft): array
    {
        $out = [
            'ok' => false, 'hull_type_id' => null, 'hull_type_name' => null,
            'required' => [], 'optional' => [], 'unresolved' => [], 'error' => null,
        ];

        $lines = preg_split('/\r\n|\r|\n/', trim($eft));
        $firstFound = false;
        $hullName = null;
        $requiredRaw = [];   // [['name'=>, 'section'=>], ...]
        $optionalRaw = [];   // [['name'=>, 'quantity'=>], ...]
        $chargeNames = [];

        // EFT separates fitted slots into blank-line-delimited blocks. The
        // in-game structure export order is fixed: low, med, high, rig,
        // service. We count blocks (empty-slot placeholders still hold their
        // block's position) so each module can be tagged with its slot.
        $currentBlock = 0;
        $pendingNewBlock = false;
        $slotCounts = [];   // section => total slots (filled + empty placeholders)

        foreach ($lines as $raw) {
            $line = trim($raw);
            if ($line === '') {
                if ($firstFound) {
                    $pendingNewBlock = true;
                }
                continue;
            }

            if (!$firstFound) {
                if (!preg_match('/^\[(.+?),\s*(.*)\]$/', $line, $m)) {
                    $out['error'] = 'The first line must be the hull in EFT form: [StructureType, Fit name].';
                    return $out;
                }
                $hullName = trim($m[1]);
                $firstFound = true;
                $pendingNewBlock = true;   // the next content begins block 1
                continue;
            }

            if ($pendingNewBlock) {
                $currentBlock++;
                $pendingNewBlock = false;
            }

            $section = $this->blockToSection($currentBlock);

            // EFT empty-slot placeholder ([Empty Rig slot], etc.) — counts as a
            // slot (it holds the position, so the section's slot total is right)
            // but is not a module to resolve.
            if (preg_match('/^\[.*\]$/', $line)) {
                $slotCounts[$section] = ($slotCounts[$section] ?? 0) + 1;
                continue;
            }

            // Quantity item: "Name xN" (cargo / ammo / fighters) — not a fitted slot.
            if (preg_match('/^(.*?)\s+x(\d+)$/i', $line, $m)) {
                $optionalRaw[] = ['name' => trim($m[1]), 'quantity' => (int) $m[2]];
                continue;
            }

            // A fitted module — occupies a slot.
            $slotCounts[$section] = ($slotCounts[$section] ?? 0) + 1;

            // Module with a loaded charge: "Module, Charge".
            if (strpos($line, ',') !== false) {
                $parts = explode(',', $line, 2);
                $requiredRaw[] = ['name' => trim($parts[0]), 'section' => $section];
                $charge = trim($parts[1]);
                if ($charge !== '') {
                    $chargeNames[] = $charge;
                }
                continue;
            }

            $requiredRaw[] = ['name' => $line, 'section' => $section];
        }

        if (!$firstFound || $hullName === null || $hullName === '') {
            $out['error'] = 'Could not find a hull line. Paste a full EFT fit starting with [StructureType, Fit name].';
            return $out;
        }

        $allNames = array_values(array_unique(array_filter(array_merge(
            [$hullName], array_column($requiredRaw, 'name'), array_column($optionalRaw, 'name'), $chargeNames
        ))));
        $map = $this->resolveNames($allNames);

        $unresolved = [];
        $resolve = function (string $name) use ($map, &$unresolved) {
            $id = $map[$this->norm($name)] ?? null;
            if ($id === null) {
                $unresolved[$name] = true;
            }
            return $id;
        };

        $hullId = $resolve($hullName);

        $required = [];
        foreach ($requiredRaw as $r) {
            $id = $resolve($r['name']);
            if ($id !== null) {
                $required[] = ['type_id' => $id, 'type_name' => $r['name'], 'section' => $r['section'], 'also_accept' => []];
            }
        }

        $optional = [];
        foreach ($optionalRaw as $o) {
            $id = $resolve($o['name']);
            if ($id !== null) {
                $optional[] = ['type_id' => $id, 'type_name' => $o['name'], 'quantity' => $o['quantity']];
            }
        }

        // Resolve charges for completeness (informational only).
        foreach ($chargeNames as $c) {
            $resolve($c);
        }

        $out['hull_type_id'] = $hullId;
        $out['hull_type_name'] = $hullName;
        $out['required'] = $required;
        $out['optional'] = $optional;
        $out['slot_counts'] = $slotCounts;
        $out['unresolved'] = array_keys($unresolved);
        $out['ok'] = $hullId !== null && empty($unresolved);

        return $out;
    }

    /**
     * Map an EFT block index to a slot section, per the in-game structure
     * export order (low, med, high, rig, service). Anything past that is
     * cargo / fighters, handled separately.
     */
    private function blockToSection(int $block): string
    {
        return [1 => 'low', 2 => 'med', 3 => 'high', 4 => 'rig', 5 => 'service'][$block] ?? 'other';
    }

    /**
     * @param array<int,string> $names
     * @return array<string,int> normalized-name => type_id
     */
    private function resolveNames(array $names): array
    {
        if (empty($names)) {
            return [];
        }
        // The SDE can hold MULTIPLE types with the same typeName — e.g. "Azbel"
        // is both the published Engineering Complex (35826) and an unpublished
        // duplicate (58735). Order published-ascending so the PUBLISHED row is
        // processed last and wins the map, otherwise the doctrine could be keyed
        // to the unpublished id and never match the real structure.
        $rows = DB::table('invTypes')
            ->whereIn('typeName', $names)
            ->orderByRaw('COALESCE(published, 0) ASC')
            ->get(['typeID', 'typeName', 'published']);

        $map = [];
        foreach ($rows as $r) {
            $map[$this->norm($r->typeName)] = (int) $r->typeID;
        }
        return $map;
    }

    private function norm(string $name): string
    {
        return mb_strtolower(trim($name));
    }
}
