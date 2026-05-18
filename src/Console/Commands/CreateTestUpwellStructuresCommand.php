<?php

namespace StructureManager\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use StructureManager\Services\TestDataGenerator;

/**
 * Generate the full menu of test Upwell structures used by the
 * Test Notification Lab.
 *
 * Creates one structure of each type SM cares about, in deterministic
 * IDs so the diagnostic page can target each one for fake-notification
 * injection by name.
 *
 * Each structure is owned by a single test corporation (creating the corp
 * + a primary test character + their affiliation along the way), so the
 * resulting set forms a complete, self-contained test environment:
 *
 *   2100000000  Test Corporation (corporation_infos)
 *   2400000000  Test Pilot       (character_infos + character_affiliations)
 *   2300000001  Astrahus
 *   2300000002  Fortizar
 *   ... (12 types total)
 *
 * All IDs come from TestDataGenerator's safe ranges. Cleanup via
 * structure-manager:cleanup-test-data (or the diagnostic page's "Clean up
 * test data" button) removes everything.
 *
 * Usage:
 *   php artisan structure-manager:create-test-upwell-structures
 *   php artisan structure-manager:create-test-upwell-structures --types=astrahus,fortizar
 *   php artisan structure-manager:create-test-upwell-structures --fuel-days=3
 */
class CreateTestUpwellStructuresCommand extends Command
{
    protected $signature = 'structure-manager:create-test-upwell-structures
                            {--types= : Comma-separated list of structure type slugs to create (default: all 12)}
                            {--fuel-days=14 : Days of fuel to seed each structure with}
                            {--corp= : Specific test corp ID to own the structures (defaults to 2100000000)}';

    protected $description = 'Generate the full set of test Upwell structures used by the Test Notification Lab';

    /**
     * Type catalog. Slug => [type_id, type_name, structure_id_offset, fuel_block_type_id]
     *
     * `structure_id_offset` is added to TestDataGenerator::STRUCTURE_ID_MIN to
     * produce a deterministic structure_id per type, so callers can refer to
     * "the test Astrahus" without round-tripping through a lookup.
     *
     * `fuel_block_type_id`:
     *   4051 = Nitrogen Fuel Block (Caldari)
     *   4246 = Hydrogen Fuel Block (Gallente)
     *   4247 = Helium Fuel Block (Minmatar)
     *   4312 = Oxygen Fuel Block (Amarr) [also Metenox]
     */
    private const TYPES = [
        'astrahus'   => ['type_id' => 35832, 'name' => 'Astrahus',                'offset' => 1,  'fuel_type' => 4312],
        'fortizar'   => ['type_id' => 35833, 'name' => 'Fortizar',                'offset' => 2,  'fuel_type' => 4312],
        'keepstar'   => ['type_id' => 35834, 'name' => 'Keepstar',                'offset' => 3,  'fuel_type' => 4312],
        'raitaru'    => ['type_id' => 35825, 'name' => 'Raitaru',                 'offset' => 11, 'fuel_type' => 4051],
        'azbel'      => ['type_id' => 35826, 'name' => 'Azbel',                   'offset' => 12, 'fuel_type' => 4051],
        'sotiyo'     => ['type_id' => 35827, 'name' => 'Sotiyo',                  'offset' => 13, 'fuel_type' => 4051],
        'athanor'    => ['type_id' => 35835, 'name' => 'Athanor',                 'offset' => 21, 'fuel_type' => 4246],
        'tatara'     => ['type_id' => 35836, 'name' => 'Tatara',                  'offset' => 22, 'fuel_type' => 4246],
        'metenox'    => ['type_id' => 81826, 'name' => 'Metenox Moon Drill',      'offset' => 31, 'fuel_type' => 4312],
        'ansiblex'   => ['type_id' => 35841, 'name' => 'Ansiblex Jump Gate',      'offset' => 41, 'fuel_type' => 4247],
        'pharolux'   => ['type_id' => 35840, 'name' => 'Pharolux Cyno Beacon',    'offset' => 42, 'fuel_type' => 4247],
        'tenebrex'   => ['type_id' => 37534, 'name' => 'Tenebrex Cyno Jammer',    'offset' => 43, 'fuel_type' => 4247],
    ];

    public function handle(): int
    {
        // Pick or create the owning test corp + character
        $corpId = (int) ($this->option('corp') ?? TestDataGenerator::CORP_ID_MIN);
        if (!TestDataGenerator::isTestCorp($corpId)) {
            $this->error("Refusing to use corp {$corpId}: must be in test range "
                . TestDataGenerator::CORP_ID_MIN . '..' . TestDataGenerator::CORP_ID_MAX);
            return 1;
        }

        $charId = TestDataGenerator::primaryCharacterIdForCorp($corpId);

        $this->info("Creating test corporation #{$corpId} and character #{$charId}...");
        TestDataGenerator::ensureTestCharacter($charId, $corpId);

        // Also create a "secondary" test corp (CORP_ID_MIN + 1) so
        // OwnershipTransferred test injections have a known-name "new owner"
        // to transfer to. Without this, the embed would show "Corp #98000099
        // (name not cached)" for the new owner — functional but ugly.
        // Cleanup wipes both via the 2.1B corp range query.
        $secondaryCorpId = TestDataGenerator::CORP_ID_MIN + 1;
        if ($secondaryCorpId !== $corpId) {
            TestDataGenerator::ensureTestCorporation($secondaryCorpId, 'Test Recipient Corp');
            $this->info("Creating secondary test corporation #{$secondaryCorpId} (default OwnershipTransferred recipient)...");
        }

        // Resolve a real solar system to anchor the structures in. We pick
        // one that already exists in mapDenormalize so SM's region/dotlan
        // lookups work. Jita = 30000142 is a hardcoded fallback if the SDE
        // is missing (admin would never see this on a healthy SeAT install).
        $systemId = $this->resolveAnchorSystem();

        // Pick a profile_id that's already in use by a real corporation_structures
        // row. Real EVE Industry profile IDs are corp-specific. If the user has
        // no existing structures, we fall back to NULL on the column allows it,
        // or 0 as a last-resort sentinel.
        $profileId = (int) (DB::table('corporation_structures')->value('profile_id') ?? 0);

        $fuelDays = (int) $this->option('fuel-days');
        if ($fuelDays < 1 || $fuelDays > 365) {
            $this->error("--fuel-days must be 1..365");
            return 1;
        }

        // Resolve which types to create
        $typesToCreate = $this->resolveTypes();
        if (empty($typesToCreate)) {
            $this->error('No valid structure types to create.');
            return 1;
        }

        $this->newLine();
        $this->info(sprintf('Creating %d test structure(s) anchored in system %d, owned by corp %d, fuel = %d days...',
            count($typesToCreate), $systemId, $corpId, $fuelDays));
        $this->newLine();

        $created = 0;
        foreach ($typesToCreate as $slug) {
            $cfg = self::TYPES[$slug];
            $structureId = TestDataGenerator::STRUCTURE_ID_MIN + $cfg['offset'];

            $this->createStructure($corpId, $structureId, $cfg, $systemId, $profileId, $fuelDays);
            $this->line(sprintf('  ✓ #%d  %-32s (type %d)',
                $structureId, $cfg['name'], $cfg['type_id']));
            $created++;
        }

        $this->newLine();
        $this->info("✓ Created {$created} test Upwell structure(s).");
        $this->info("Use the Test Notification Lab on the diagnostic page to inject fake events.");
        $this->newLine();
        $this->comment('Cleanup: php artisan structure-manager:cleanup-test-data');

        return 0;
    }

    /**
     * Build one corporation_structures + universe_structures row.
     */
    private function createStructure(int $corpId, int $structureId, array $cfg, int $systemId, int $profileId, int $fuelDays): void
    {
        // corporation_structures: SM reads fuel_expires, state, type_id from here
        DB::table('corporation_structures')->updateOrInsert(
            // composite primary key
            ['corporation_id' => $corpId, 'structure_id' => $structureId],
            [
                'profile_id'             => $profileId,
                'system_id'              => $systemId,
                'type_id'                => $cfg['type_id'],
                'fuel_expires'           => Carbon::now()->addDays($fuelDays),
                'state'                  => 'shield_vulnerable',
                'state_timer_start'      => null,
                'state_timer_end'        => null,
                'unanchors_at'           => null,
                'reinforce_weekday'      => 3, // Wednesday (arbitrary)
                'reinforce_hour'         => 18,
                'next_reinforce_weekday' => null,
                'next_reinforce_hour'    => null,
                'next_reinforce_apply'   => null,
                'created_at'             => Carbon::now(),
                'updated_at'             => Carbon::now(),
            ]
        );

        // universe_structures: SM reads structure name from here
        DB::table('universe_structures')->updateOrInsert(
            ['structure_id' => $structureId],
            [
                'name'            => 'TEST - ' . $cfg['name'] . ' #' . $cfg['offset'],
                'solar_system_id' => $systemId,
                'type_id'         => $cfg['type_id'],
                'x'               => 0,
                'y'               => 0,
                'z'               => 0,
                'created_at'      => Carbon::now(),
                'updated_at'      => Carbon::now(),
            ]
        );

        // Seed fuel block contents so the structure looks "live" in the UI.
        // item_id is structure_id * 10 + 1 to keep test asset IDs deterministic
        // and inside a predictable range for cleanup queries.
        DB::table('corporation_assets')->updateOrInsert(
            [
                'corporation_id' => $corpId,
                'item_id'        => $structureId * 10 + 1,
            ],
            [
                'location_id'   => $structureId,
                'location_type' => 'item',
                'type_id'       => $cfg['fuel_type'],
                // ~120 blocks/hour for a large structure, 7-day buffer baked in
                'quantity'      => 120 * 24 * $fuelDays,
                'location_flag' => 'StructureFuel',
                'is_singleton'  => 0,
                'created_at'    => Carbon::now(),
                'updated_at'    => Carbon::now(),
            ]
        );

        // Metenox needs magmatic gas in addition to fuel blocks (dual-fuel)
        if ($cfg['type_id'] === 81826) {
            DB::table('corporation_assets')->updateOrInsert(
                [
                    'corporation_id' => $corpId,
                    'item_id'        => $structureId * 10 + 2,
                ],
                [
                    'location_id'   => $structureId,
                    'location_type' => 'item',
                    'type_id'       => 81143, // Magmatic Gas
                    'quantity'      => 200 * 24 * $fuelDays, // ~200/hr consumption
                    'location_flag' => 'StructureFuel',
                    'is_singleton'  => 0,
                    'created_at'    => Carbon::now(),
                    'updated_at'    => Carbon::now(),
                ]
            );
        }
    }

    /**
     * Resolve the solar system to anchor test structures in.
     *
     * Preference order:
     *   1. The system most-used by the corp's existing structures (so test
     *      structures appear in a familiar place on the Structure Board)
     *   2. Jita (30000142) as a universally known fallback
     */
    private function resolveAnchorSystem(): int
    {
        $existing = DB::table('corporation_structures')
            ->select('system_id', DB::raw('COUNT(*) as n'))
            ->groupBy('system_id')
            ->orderByDesc('n')
            ->value('system_id');

        if ($existing) {
            return (int) $existing;
        }

        // mapDenormalize check is best-effort — if the SDE is missing, Jita
        // still works as a system_id even without it
        return 30000142; // Jita
    }

    /**
     * Resolve --types to a list of slugs from the catalog.
     */
    private function resolveTypes(): array
    {
        $optTypes = trim((string) $this->option('types'));
        if ($optTypes === '') {
            return array_keys(self::TYPES); // all 12
        }

        $requested = array_map('trim', explode(',', strtolower($optTypes)));
        $resolved = [];
        $unknown = [];
        foreach ($requested as $slug) {
            if ($slug === '') continue;
            if (isset(self::TYPES[$slug])) {
                $resolved[] = $slug;
            } else {
                $unknown[] = $slug;
            }
        }

        if (!empty($unknown)) {
            $this->warn('Unknown structure type slugs ignored: ' . implode(', ', $unknown));
            $this->line('Valid slugs: ' . implode(', ', array_keys(self::TYPES)));
        }

        return array_values(array_unique($resolved));
    }

    /**
     * Public helper for the diagnostic page so it can render "Astrahus #1" /
     * deterministic structure_id mapping in the inject UI.
     *
     * @return array<int,array{slug:string,name:string,type_id:int,structure_id:int}>
     */
    public static function catalog(): array
    {
        $out = [];
        foreach (self::TYPES as $slug => $cfg) {
            $out[] = [
                'slug'         => $slug,
                'name'         => $cfg['name'],
                'type_id'      => $cfg['type_id'],
                'structure_id' => TestDataGenerator::STRUCTURE_ID_MIN + $cfg['offset'],
            ];
        }
        return $out;
    }
}
