<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * One-time migration: copy Structure Manager's ESI key pool into Manager Core's pool.
 *
 * Only runs when both tables exist (i.e., Manager Core is installed alongside
 * Structure Manager v3.1+). If Manager Core is absent this migration is a no-op
 * and remains replayable — whenever Manager Core is installed later, this same
 * migration can be re-run manually or the Admin can use the key-pool UI directly.
 *
 * Structure Manager's own table is LEFT IN PLACE. If the admin later uninstalls
 * Manager Core, their historical data is still there (though SM v3.1 will no
 * longer use it — there is no rollback path back to SM-local polling).
 */
class MigrateKeyHoldersToManagerCore extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('manager_core_esi_key_holders')) {
            Log::info('[Structure Manager] Key pool migration: Manager Core not installed, skipping.');
            return;
        }

        if (!Schema::hasTable('structure_manager_esi_key_holders')) {
            return;
        }

        $migrated = 0;
        $skipped = 0;

        DB::table('structure_manager_esi_key_holders')
            ->orderBy('id')
            ->get()
            ->each(function ($row) use (&$migrated, &$skipped) {
                $exists = DB::table('manager_core_esi_key_holders')
                    ->where('character_id', $row->character_id)
                    ->exists();

                if ($exists) {
                    $skipped++;
                    return;
                }

                DB::table('manager_core_esi_key_holders')->insert([
                    'character_id' => $row->character_id,
                    'corporation_id' => $row->corporation_id,
                    'character_name' => $row->character_name,
                    'enabled' => $row->enabled,
                    'last_polled_at' => $row->last_polled_at,
                    'last_poll_status' => $row->last_poll_status,
                    'last_error' => $row->last_error,
                    'consecutive_failures' => $row->consecutive_failures ?? 0,
                    'total_polls' => $row->total_polls ?? 0,
                    'total_notifications_found' => $row->total_notifications_found ?? 0,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ]);

                $migrated++;
            });

        if ($migrated > 0 || $skipped > 0) {
            Log::info("[Structure Manager] Key pool migration to Manager Core: migrated {$migrated}, skipped {$skipped} (already present).");
        }
    }

    public function down(): void
    {
        // Not reversible — copied rows would need a source marker to unwind cleanly,
        // and we explicitly avoid tracking that so the admin's edits in MC survive.
    }
}
