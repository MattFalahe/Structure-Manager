<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seed the pos.attack notification category.
 *
 * POS attacks are driven by EVE's own TowerAlertMsg notification rather than
 * by polling tower state. That distinction is why this is its own category and
 * not part of pos.lifecycle: a tower being shot and a tower changing state are
 * different events on very different timescales, and operators route them
 * differently. Attacks belong wherever people are pinged at three in the
 * morning; state changes belong with logistics.
 *
 * Measured on a live attack: the notification landed immediately, while every
 * SeAT corporation table was around an hour stale. State polling cannot warn
 * about an attack in progress, only report one afterwards, so this category
 * carries the only timely signal available.
 *
 * Enabled by default, but no webhook is auto-bound. The operator binds it in
 * the Notifications panel like every other category.
 */
class SeedStructureManagerPosAttackCategory extends Migration
{
    private const NAMESPACE = 'pos';
    private const KEY = 'attack';

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

        $displayName = 'Under Attack';
        // The column is string(255), so the full explanation lives in Help.
        $description = 'Alerts when a Player Owned Starbase is being shot, from the in-game '
            . 'notification rather than tower state. Carries shield, armor and hull at the time '
            . 'of the attack, plus the aggressor. The only alert that arrives mid-attack.';

        if ($existing) {
            // Preserve whatever the operator has configured. Only the fields
            // they would not have edited get refreshed.
            DB::table('structure_manager_notification_categories')
                ->where('id', $existing->id)
                ->update([
                    'display_name' => $displayName,
                    'description'  => $description,
                    'sort_order'   => 5,
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
            // Ahead of fuel (10) and strontium (20): an attack outranks a
            // consumable running low.
            'sort_order'   => 5,
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
