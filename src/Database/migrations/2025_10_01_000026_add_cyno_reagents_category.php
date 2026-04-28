<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add `upwell.cyno_reagents` notification category for Standup Cyno Generator
 * (Liquid Ozone) and Standup Cyno Jammer (Strontium Clathrate) low-quantity
 * alerts in fuel bays.
 *
 * These modules consume their secondary reagent on-demand per cyno-fired or
 * per-jam-cycle (not steady drain like Metenox magmatic gas), so the alert
 * model is "warn when reagent quantity drops below threshold," not
 * time-to-empty. NotifyUpwellLowFuel polls the fuel bay every 10 minutes
 * and dispatches via this category.
 *
 * Backfill: existing webhooks bound to `upwell.fuel` are auto-bound to
 * `upwell.cyno_reagents` so admins who already configured fuel routing
 * don't need to do anything to start receiving cyno reagent alerts.
 */
class AddCynoReagentsCategory extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('structure_manager_notification_categories')) {
            return;
        }

        $now = now();

        DB::table('structure_manager_notification_categories')->updateOrInsert(
            ['namespace' => 'upwell', 'category_key' => 'cyno_reagents'],
            [
                'display_name' => 'Cyno Reagents',
                'description'  => 'Liquid Ozone (Cyno Generator) and Strontium Clathrate (Cyno Jammer) running low in the fuel bay. Polled every 10 minutes; dispatched on threshold crossing (warning / critical).',
                'enabled'      => true,
                'sort_order'   => 30,
                'updated_at'   => $now,
                'created_at'   => $now,
            ]
        );

        // Backfill: bind to webhooks already on upwell.fuel so existing
        // routing carries over without admin intervention
        $cynoReagentsId = DB::table('structure_manager_notification_categories')
            ->where('namespace', 'upwell')->where('category_key', 'cyno_reagents')->value('id');
        $upwellFuelId = DB::table('structure_manager_notification_categories')
            ->where('namespace', 'upwell')->where('category_key', 'fuel')->value('id');

        if ($cynoReagentsId && $upwellFuelId && Schema::hasTable('structure_manager_category_webhook')) {
            $webhookIds = DB::table('structure_manager_category_webhook')
                ->where('category_id', $upwellFuelId)
                ->pluck('webhook_id');

            foreach ($webhookIds as $whId) {
                DB::table('structure_manager_category_webhook')->updateOrInsert(
                    ['category_id' => $cynoReagentsId, 'webhook_id' => $whId],
                    [
                        'enabled'    => true,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('structure_manager_notification_categories')) {
            return;
        }

        $cynoReagentsId = DB::table('structure_manager_notification_categories')
            ->where('namespace', 'upwell')->where('category_key', 'cyno_reagents')->value('id');

        if ($cynoReagentsId && Schema::hasTable('structure_manager_category_webhook')) {
            DB::table('structure_manager_category_webhook')
                ->where('category_id', $cynoReagentsId)
                ->delete();
        }

        DB::table('structure_manager_notification_categories')
            ->where('namespace', 'upwell')->where('category_key', 'cyno_reagents')
            ->delete();
    }
}
