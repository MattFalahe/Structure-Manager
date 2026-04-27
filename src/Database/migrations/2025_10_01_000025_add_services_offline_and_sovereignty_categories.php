<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add two new notification categories so admins can route service-offline
 * alerts and sovereignty-event alerts to dedicated webhooks (separate from
 * generic fuel/attack alerts):
 *
 *   events.services_offline — Engineering Complex / Refinery service modules
 *     going offline (Manufacturing, Market, Cloning, Research, Moon Drill).
 *     Operators want this routed to industry-team channels, not just FC channels.
 *
 *   events.sovereignty — TCU/IHUB events: EntosisCaptureStarted,
 *     SovStructureReinforced, SovStructureDestroyed, SovCommandNodeEventStarted.
 *     Operators want this routed to sov-ops channels.
 *
 * Backfill rules:
 *   - Webhooks currently bound to events.structure_fuel_events are
 *     auto-bound to events.services_offline (preserves existing behavior:
 *     services-offline events used to ride the fuel category)
 *   - Webhooks currently bound to events.structure_attack are auto-bound
 *     to events.sovereignty (sov events used to fall through unrouted —
 *     binding to attack channels is a sensible default for FC-facing alerts)
 *
 * Both categories ship enabled=true so out-of-the-box behavior is consistent
 * with previous releases. Admins can disable or re-route freely on the
 * Notifications page.
 */
class AddServicesOfflineAndSovereigntyCategories extends Migration {
    public function up(): void
    {
        $now = now();

        // Skip cleanly if categories table doesn't exist (shouldn't happen —
        // this migration runs after 000022 which creates it — but defensive)
        if (!Schema::hasTable('structure_manager_notification_categories')) {
            return;
        }

        // Insert the two new categories. updateOrInsert in case admin re-ran.
        $newCategories = [
            [
                'namespace'    => 'events',
                'category_key' => 'services_offline',
                'display_name' => 'Services Offline',
                'description'  => 'Engineering Complex / Refinery service modules going offline (Manufacturing, Market, Cloning, Research, Moon Drill). Operators usually route to industry-team channels.',
                'sort_order'   => 40,
            ],
            [
                'namespace'    => 'events',
                'category_key' => 'sovereignty',
                'display_name' => 'Sovereignty',
                'description'  => 'TCU / IHUB sov events: entosis capture started, sov reinforced, sov destroyed, command node spawned. Operators route to sov-ops channels.',
                'sort_order'   => 50,
            ],
        ];

        foreach ($newCategories as $cat) {
            DB::table('structure_manager_notification_categories')->updateOrInsert(
                ['namespace' => $cat['namespace'], 'category_key' => $cat['category_key']],
                [
                    'display_name' => $cat['display_name'],
                    'description'  => $cat['description'],
                    'enabled'      => true,
                    'sort_order'   => $cat['sort_order'],
                    'updated_at'   => $now,
                    'created_at'   => $now,
                ]
            );
        }

        // Resolve category IDs we just inserted/updated
        $servicesOfflineId = DB::table('structure_manager_notification_categories')
            ->where('namespace', 'events')->where('category_key', 'services_offline')->value('id');
        $sovereigntyId = DB::table('structure_manager_notification_categories')
            ->where('namespace', 'events')->where('category_key', 'sovereignty')->value('id');
        $fuelEventsId = DB::table('structure_manager_notification_categories')
            ->where('namespace', 'events')->where('category_key', 'structure_fuel_events')->value('id');
        $attackId = DB::table('structure_manager_notification_categories')
            ->where('namespace', 'events')->where('category_key', 'structure_attack')->value('id');

        // Backfill: webhooks bound to fuel_events → also bind to services_offline
        if ($servicesOfflineId && $fuelEventsId && Schema::hasTable('structure_manager_category_webhook')) {
            $webhooks = DB::table('structure_manager_category_webhook')
                ->where('category_id', $fuelEventsId)
                ->pluck('webhook_id');

            foreach ($webhooks as $whId) {
                DB::table('structure_manager_category_webhook')->updateOrInsert(
                    ['category_id' => $servicesOfflineId, 'webhook_id' => $whId],
                    [
                        'enabled'    => true,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );
            }
        }

        // Backfill: webhooks bound to attack → also bind to sovereignty
        if ($sovereigntyId && $attackId && Schema::hasTable('structure_manager_category_webhook')) {
            $webhooks = DB::table('structure_manager_category_webhook')
                ->where('category_id', $attackId)
                ->pluck('webhook_id');

            foreach ($webhooks as $whId) {
                DB::table('structure_manager_category_webhook')->updateOrInsert(
                    ['category_id' => $sovereigntyId, 'webhook_id' => $whId],
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

        // Drop pivot rows for these two categories
        $newCategoryIds = DB::table('structure_manager_notification_categories')
            ->where('namespace', 'events')
            ->whereIn('category_key', ['services_offline', 'sovereignty'])
            ->pluck('id');

        if ($newCategoryIds->isNotEmpty() && Schema::hasTable('structure_manager_category_webhook')) {
            DB::table('structure_manager_category_webhook')
                ->whereIn('category_id', $newCategoryIds)
                ->delete();
        }

        DB::table('structure_manager_notification_categories')
            ->where('namespace', 'events')
            ->whereIn('category_key', ['services_offline', 'sovereignty'])
            ->delete();
    }
}
