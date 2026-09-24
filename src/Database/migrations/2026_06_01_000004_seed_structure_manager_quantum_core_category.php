<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seed the events.quantum_core notification category.
 *
 * When anchoring finishes, EVE sends StructureOnline (despite the name, the
 * structure is not online yet) and the structure waits for its Quantum Core.
 * It stays vulnerable and cannot come online until the core goes in, and
 * nothing in EVE ends that wait on a clock. This category carries the alert
 * when the wait begins and the reminders while it lasts.
 *
 * It is separate from events.structure_lifecycle because it is the one
 * lifecycle event somebody has to act on, so it is routed and pinged like an
 * alert rather than logged like a state change.
 *
 * Enabled by default, but no webhook is auto-bound. The operator binds it in
 * the Notifications panel like every other category.
 */
class SeedStructureManagerQuantumCoreCategory extends Migration
{
    private const NAMESPACE = 'events';
    private const KEY = 'quantum_core';

    public function up(): void
    {
        if (! Schema::hasTable('structure_manager_notification_categories')) {
            return;
        }

        $existing = DB::table('structure_manager_notification_categories')
            ->where('namespace', self::NAMESPACE)
            ->where('category_key', self::KEY)
            ->first();

        $now = now();

        $displayName = 'Quantum Core';
        // The column is string(255), so the full explanation lives in Help.
        $description = 'Anchoring has finished and the structure is waiting for its Quantum Core, '
            . 'vulnerable until it goes in. Alerts when the wait begins and reminds while it lasts.';

        if ($existing) {
            // Preserve whatever the operator has configured. Only the fields
            // they would not have edited get refreshed.
            DB::table('structure_manager_notification_categories')
                ->where('id', $existing->id)
                ->update([
                    'display_name' => $displayName,
                    'description'  => $description,
                    'sort_order'   => 25,
                    'updated_at'   => $now,
                ]);

            return;
        }

        DB::table('structure_manager_notification_categories')->insert([
            'namespace'    => self::NAMESPACE,
            'category_key' => self::KEY,
            'display_name' => $displayName,
            'description'  => $description,
            'enabled'      => true,
            'role_mention' => null,
            'role_source'  => null,
            // Straight after Lifecycle (20), where the rest of anchoring sits.
            'sort_order'   => 25,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('structure_manager_notification_categories')) {
            return;
        }

        $category = DB::table('structure_manager_notification_categories')
            ->where('namespace', self::NAMESPACE)
            ->where('category_key', self::KEY)
            ->first();

        if (! $category) {
            return;
        }

        if (Schema::hasTable('structure_manager_category_webhook')) {
            DB::table('structure_manager_category_webhook')
                ->where('category_id', $category->id)
                ->delete();
        }

        DB::table('structure_manager_notification_categories')
            ->where('id', $category->id)
            ->delete();
    }
}
