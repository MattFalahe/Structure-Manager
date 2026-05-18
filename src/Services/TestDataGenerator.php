<?php

namespace StructureManager\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Central registry of safe ID ranges + shared helpers for Structure Manager
 * test data.
 *
 * The plugin has multiple test commands (POSes, Metenox/Astrahus, the new
 * Upwell-structure set, fake-notification injection) that all need to agree
 * on the same safe ID ranges so cleanup is comprehensive and predictable.
 *
 * The ranges are picked far outside CCP's actual allocations:
 *   - Real EVE corporation IDs are in the ~98M to ~2.0B range
 *   - Real EVE character IDs are in the ~90M to ~2.2B range
 *   - Real EVE structure IDs (Upwell) are 1e18+
 *   - Real CCP notification IDs are in the ~2-20 billion range
 *
 * Our test ranges sit in the 2.1B to 8.99e18 zone so they cannot collide
 * with real data, even if CCP expands their allocations significantly.
 *
 * All test rows are written through the parent table's `updateOrInsert`
 * pattern so re-runs are idempotent.
 *
 * Foreign-key chain (this is what makes cleanup safe):
 *   character_notifications.character_id -> character_infos.character_id  (CASCADE)
 *   corporation_structures.corporation_id -> corporation_infos.corporation_id (CASCADE)
 *   corporation_starbases.corporation_id  -> corporation_infos.corporation_id (CASCADE)
 *
 * Deleting a test corp from corporation_infos cascades structures + POSes.
 * Deleting a test character from character_infos cascades notifications.
 */
class TestDataGenerator
{
    // ------------------------------------------------------------------
    // Test ID ranges (DO NOT CHANGE — cleanup queries depend on them)
    // ------------------------------------------------------------------

    /** Test corporation IDs live here. Existing CreateTestPoses already uses this range. */
    public const CORP_ID_MIN = 2100000000;
    public const CORP_ID_MAX = 2100000099;

    /** Test POS (corporation_starbases.starbase_id) range. Existing range. */
    public const POS_ID_MIN = 2200000000;
    public const POS_ID_MAX = 2200000999;

    /** Test Upwell structure_id range. NEW — used by CreateTestUpwellStructures. */
    public const STRUCTURE_ID_MIN = 2300000000;
    public const STRUCTURE_ID_MAX = 2300000999;

    /** Test character_id range (recipients of fake notifications). NEW. */
    public const CHARACTER_ID_MIN = 2400000000;
    public const CHARACTER_ID_MAX = 2400000099;

    /** Test notification_id range. NEW.
     *
     * Picked at 8e18 to be well above any real CCP notification ID
     * (~10 billion historically) but still safely under signed bigint max
     * (~9.22e18). Gives us 1e18 sequence space — overkill but unmistakable.
     */
    public const NOTIFICATION_ID_MIN = 8000000000000000000;
    public const NOTIFICATION_ID_MAX = 8999999999999999999;

    /** Legacy Metenox/Astrahus IDs from CreateTestMetenoxCommand — cleanup must still cover these. */
    public const LEGACY_METENOX_ID  = 9999999999;
    public const LEGACY_ASTRAHUS_ID = 9999999998;

    // ------------------------------------------------------------------
    // Range membership helpers (used by injection guards)
    // ------------------------------------------------------------------

    public static function isTestCorp(int $corpId): bool
    {
        return $corpId >= self::CORP_ID_MIN && $corpId <= self::CORP_ID_MAX;
    }

    public static function isTestStructure(int $structureId): bool
    {
        if ($structureId >= self::STRUCTURE_ID_MIN && $structureId <= self::STRUCTURE_ID_MAX) {
            return true;
        }
        // Legacy Metenox/Astrahus still count as test data
        return $structureId === self::LEGACY_METENOX_ID
            || $structureId === self::LEGACY_ASTRAHUS_ID;
    }

    public static function isTestCharacter(int $characterId): bool
    {
        return $characterId >= self::CHARACTER_ID_MIN && $characterId <= self::CHARACTER_ID_MAX;
    }

    public static function isTestPos(int $starbaseId): bool
    {
        return $starbaseId >= self::POS_ID_MIN && $starbaseId <= self::POS_ID_MAX;
    }

    public static function isTestNotification(int $notificationId): bool
    {
        return $notificationId >= self::NOTIFICATION_ID_MIN
            && $notificationId <= self::NOTIFICATION_ID_MAX;
    }

    // ------------------------------------------------------------------
    // Shared row creators
    // ------------------------------------------------------------------

    /**
     * Ensure a test corporation row exists in corporation_infos.
     *
     * Other test rows (structures, POSes, characters' affiliations) FK-reference
     * this row, so it must exist before they can be inserted.
     *
     * @param int    $corpId  Must be inside CORP_ID_MIN..CORP_ID_MAX
     * @param string $name    Display name; defaults to "Test Corp #{offset}"
     * @return void
     */
    public static function ensureTestCorporation(int $corpId, ?string $name = null): void
    {
        if (!self::isTestCorp($corpId)) {
            throw new \InvalidArgumentException(
                "Refusing to create test corp {$corpId}: outside safe range "
                . self::CORP_ID_MIN . '..' . self::CORP_ID_MAX
            );
        }

        $offset = $corpId - self::CORP_ID_MIN;
        $defaultName = $name ?? "Test Corporation #{$offset}";

        // updateOrInsert by primary key: idempotent re-runs.
        DB::table('corporation_infos')->updateOrInsert(
            ['corporation_id' => $corpId],
            [
                'name'         => $defaultName,
                'ticker'       => 'T' . str_pad((string) $offset, 3, '0', STR_PAD_LEFT),
                'member_count' => 10,
                'ceo_id'       => 1,
                'creator_id'   => 1, // SeAT requires this NOT NULL
                'alliance_id'  => null,
                'description'  => 'Test corporation (Structure Manager test data)',
                'tax_rate'     => 0.1,
                'url'          => 'https://structure-manager.test',
                'created_at'   => Carbon::now(),
                'updated_at'   => Carbon::now(),
            ]
        );
    }

    /**
     * Ensure a test character row exists in character_infos AND character_affiliations.
     *
     * Required before injecting any fake notification — character_notifications
     * has a FK to character_infos with ON DELETE CASCADE.
     *
     * Both tables are written:
     *   - character_infos: NOT NULL fields (name, corporation_id, birthday, etc.)
     *   - character_affiliations: SM resolves notification.corporation_id via this table
     *
     * @param int    $characterId  Must be inside CHARACTER_ID_MIN..CHARACTER_ID_MAX
     * @param int    $corporationId  The test corporation this character belongs to
     * @param string $name           Display name; defaults to "Test Pilot #{offset}"
     * @return void
     */
    public static function ensureTestCharacter(int $characterId, int $corporationId, ?string $name = null): void
    {
        if (!self::isTestCharacter($characterId)) {
            throw new \InvalidArgumentException(
                "Refusing to create test character {$characterId}: outside safe range "
                . self::CHARACTER_ID_MIN . '..' . self::CHARACTER_ID_MAX
            );
        }
        if (!self::isTestCorp($corporationId)) {
            throw new \InvalidArgumentException(
                "Refusing to link test character {$characterId} to corp {$corporationId}: "
                . 'corporation must be a test corporation'
            );
        }

        // Ensure the corporation exists first (FK requirement is implicit
        // through chain: notifications -> infos -> we set corporation here)
        self::ensureTestCorporation($corporationId);

        $offset = $characterId - self::CHARACTER_ID_MIN;
        $defaultName = $name ?? "Test Pilot #{$offset}";

        // character_infos: ALL NOT NULL fields must be populated.
        //
        // SCHEMA NOTE: The current SeAT v5 schema is significantly slimmer
        // than the original 2018 migration. Migration history:
        //   2018_01_03 CREATE: character_id, name, description, corporation_id,
        //     alliance_id, birthday, gender, race_id, bloodline_id,
        //     ancenstry_id, security_status, faction_id
        //   2019_01_05 RENAME: ancenstry_id -> ancestry_id (typo fix)
        //   2019_11_26 DROP:   corporation_id (moved to character_affiliations)
        //   2019_11_26 DROP:   alliance_id    (moved to character_affiliations)
        //   2019_11_26 DROP:   faction_id     (moved to character_affiliations)
        //   2021_03_08 ADD:    title (nullable)
        //   2021_09_30 DROP:   ancestry_id (removed entirely)
        //
        // Final shape: character_id (PK), name, description?, birthday,
        //   gender, race_id, bloodline_id, security_status?, title?
        //
        // The corporation linkage lives ENTIRELY in character_affiliations
        // (the next updateOrInsert call below).
        //
        // To survive any further SeAT schema drift without crashing, we
        // probe each optional column via Schema::hasColumn before including
        // it. NOT NULL fields are always sent.
        $row = [
            'name'            => $defaultName,
            'birthday'        => '2003-05-06 00:00:00',
            'gender'          => 'male',
            'race_id'         => 1, // Caldari
            'bloodline_id'    => 1, // Deteis
            'created_at'      => Carbon::now(),
            'updated_at'      => Carbon::now(),
        ];
        $optional = [
            'description'     => 'Test character (Structure Manager test data)',
            'security_status' => 0.0,
            'title'           => null,
        ];
        foreach ($optional as $col => $val) {
            if (\Illuminate\Support\Facades\Schema::hasColumn('character_infos', $col)) {
                $row[$col] = $val;
            }
        }

        DB::table('character_infos')->updateOrInsert(
            ['character_id' => $characterId],
            $row
        );

        // character_affiliations: SM looks up corp via this table when processing notifications
        DB::table('character_affiliations')->updateOrInsert(
            ['character_id' => $characterId],
            [
                'corporation_id' => $corporationId,
                'alliance_id'    => null,
                'faction_id'     => null,
                'created_at'     => Carbon::now(),
                'updated_at'     => Carbon::now(),
            ]
        );
    }

    /**
     * Get the canonical "primary test character" used for notification injection.
     *
     * Most test scenarios just need ONE test character per test corp. This
     * helper returns a deterministic character_id derived from the corp_id
     * so re-runs always pick up the same character.
     *
     * Mapping: corp 2100000000 -> char 2400000000, corp 2100000005 -> char 2400000005, etc.
     */
    public static function primaryCharacterIdForCorp(int $corporationId): int
    {
        if (!self::isTestCorp($corporationId)) {
            throw new \InvalidArgumentException(
                "Cannot derive test character for non-test corp {$corporationId}"
            );
        }
        $offset = $corporationId - self::CORP_ID_MIN;
        return self::CHARACTER_ID_MIN + $offset;
    }

    /**
     * Generate a unique notification_id in the safe test range.
     *
     * Strategy: use a microsecond-precision timestamp offset to guarantee
     * uniqueness across rapid injections. Fits in our 1e18 of test space
     * for the next ~31,000 years.
     */
    public static function nextNotificationId(): int
    {
        // microtime → 16 digits; add to base. Subtracting 1.7e16 first keeps
        // the number well within bigint signed range and inside our test band.
        $micro = (int) (microtime(true) * 1_000_000);
        // Anchor relative to a fixed epoch so even very far in the future we stay below MAX
        $relative = $micro - 1_700_000_000_000_000; // ~Nov 2023 anchor
        return self::NOTIFICATION_ID_MIN + max($relative, 0);
    }

    // ------------------------------------------------------------------
    // Cleanup
    // ------------------------------------------------------------------

    /**
     * Remove ALL Structure Manager test data across every safe range.
     *
     * Order matters: child rows first (notifications, structures, POSes,
     * affiliations) then parent rows (character_infos, corporation_infos).
     * The FK CASCADEs would handle this for us, but we delete explicitly so
     * the returned counts are accurate per-table.
     *
     * @return array<string,int>  table => deleted count
     */
    public static function cleanupAll(): array
    {
        $counts = [];

        // 1. Test notifications (8e18 range) — must go before character_infos
        //    even though FK cascades, so we can report accurate counts.
        $counts['character_notifications'] = (int) DB::table('character_notifications')
            ->whereBetween('notification_id', [self::NOTIFICATION_ID_MIN, self::NOTIFICATION_ID_MAX])
            ->delete();

        // 2. SM's local dedup table — same range
        $counts['structure_manager_esi_notifications'] = (int) DB::table('structure_manager_esi_notifications')
            ->whereBetween('notification_id', [self::NOTIFICATION_ID_MIN, self::NOTIFICATION_ID_MAX])
            ->delete();

        // 3. Test character affiliations + infos
        $counts['character_affiliations'] = (int) DB::table('character_affiliations')
            ->whereBetween('character_id', [self::CHARACTER_ID_MIN, self::CHARACTER_ID_MAX])
            ->delete();

        $counts['character_infos'] = (int) DB::table('character_infos')
            ->whereBetween('character_id', [self::CHARACTER_ID_MIN, self::CHARACTER_ID_MAX])
            ->delete();

        // 4. Test Upwell structures (2.3B range)
        $counts['corporation_structures'] = (int) DB::table('corporation_structures')
            ->whereBetween('structure_id', [self::STRUCTURE_ID_MIN, self::STRUCTURE_ID_MAX])
            ->delete();

        $counts['universe_structures'] = (int) DB::table('universe_structures')
            ->whereBetween('structure_id', [self::STRUCTURE_ID_MIN, self::STRUCTURE_ID_MAX])
            ->delete();

        // Legacy Metenox / Astrahus structure rows
        $counts['legacy_structures'] = (int) DB::table('corporation_structures')
            ->whereIn('structure_id', [self::LEGACY_METENOX_ID, self::LEGACY_ASTRAHUS_ID])
            ->delete();

        DB::table('universe_structures')
            ->whereIn('structure_id', [self::LEGACY_METENOX_ID, self::LEGACY_ASTRAHUS_ID])
            ->delete();

        // 5. Test POSes (2.2B range)
        $counts['corporation_starbases'] = (int) DB::table('corporation_starbases')
            ->whereBetween('starbase_id', [self::POS_ID_MIN, self::POS_ID_MAX])
            ->delete();

        $counts['starbase_fuel_history'] = (int) DB::table('starbase_fuel_history')
            ->whereBetween('starbase_id', [self::POS_ID_MIN, self::POS_ID_MAX])
            ->delete();

        // 6. Test corporation assets — multiple location-key flavors:
        //    a. Items located INSIDE test structures (location_id is a structure_id)
        $counts['corporation_assets_in_structures'] = (int) DB::table('corporation_assets')
            ->whereBetween('location_id', [self::STRUCTURE_ID_MIN, self::STRUCTURE_ID_MAX])
            ->delete();

        DB::table('corporation_assets')
            ->whereIn('location_id', [self::LEGACY_METENOX_ID, self::LEGACY_ASTRAHUS_ID])
            ->delete();

        //    b. Test corp's other assets (item_id range based on POS IDs * 10 + N)
        $counts['corporation_assets_owned'] = (int) DB::table('corporation_assets')
            ->whereBetween('corporation_id', [self::CORP_ID_MIN, self::CORP_ID_MAX])
            ->delete();

        // 7. Test corporations (CASCADE on delete handles structures + POSes already
        //    deleted above, but we delete explicitly so corporations vanish too)
        $counts['corporation_infos'] = (int) DB::table('corporation_infos')
            ->whereBetween('corporation_id', [self::CORP_ID_MIN, self::CORP_ID_MAX])
            ->delete();

        // 8. Plugin-owned tables that may hold test row references:
        //    Structure Board timers — match by structure_id range or test corp
        if (\Illuminate\Support\Facades\Schema::hasTable('structure_manager_timers')) {
            $counts['structure_manager_timers'] = (int) DB::table('structure_manager_timers')
                ->whereBetween('structure_id', [self::STRUCTURE_ID_MIN, self::STRUCTURE_ID_MAX])
                ->orWhereIn('structure_id', [self::LEGACY_METENOX_ID, self::LEGACY_ASTRAHUS_ID])
                ->orWhereBetween('corporation_id', [self::CORP_ID_MIN, self::CORP_ID_MAX])
                ->delete();
        }

        Log::info('TestDataGenerator: cleanup complete', $counts);
        return $counts;
    }

    // ------------------------------------------------------------------
    // Inventory (used by the diagnostic page)
    // ------------------------------------------------------------------

    /**
     * Return current counts of test data across all ranges.
     *
     * @return array<string,int>
     */
    public static function inventory(): array
    {
        $inv = [];

        $inv['test_corps'] = (int) DB::table('corporation_infos')
            ->whereBetween('corporation_id', [self::CORP_ID_MIN, self::CORP_ID_MAX])
            ->count();

        $inv['test_characters'] = (int) DB::table('character_infos')
            ->whereBetween('character_id', [self::CHARACTER_ID_MIN, self::CHARACTER_ID_MAX])
            ->count();

        $inv['test_upwell_structures'] = (int) DB::table('corporation_structures')
            ->whereBetween('structure_id', [self::STRUCTURE_ID_MIN, self::STRUCTURE_ID_MAX])
            ->count();

        $inv['legacy_structures'] = (int) DB::table('corporation_structures')
            ->whereIn('structure_id', [self::LEGACY_METENOX_ID, self::LEGACY_ASTRAHUS_ID])
            ->count();

        $inv['test_poses'] = (int) DB::table('corporation_starbases')
            ->whereBetween('starbase_id', [self::POS_ID_MIN, self::POS_ID_MAX])
            ->count();

        $inv['test_notifications'] = (int) DB::table('character_notifications')
            ->whereBetween('notification_id', [self::NOTIFICATION_ID_MIN, self::NOTIFICATION_ID_MAX])
            ->count();

        return $inv;
    }
}
