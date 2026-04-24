<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Split notification categories from webhook delivery.
 *
 * Before: structure_manager_webhooks was both the delivery target and carried
 *         its own role_mention, with every webhook receiving every notification
 *         type. Category on/off was a flat global setting.
 *
 * After:  Webhooks are pure delivery endpoints (unchanged table).
 *         Notification categories live in a new table with per-category master
 *         toggles and a default role mention.
 *         A pivot table binds categories to webhooks; each binding may override
 *         the category's default role mention for that specific webhook
 *         (per-binding flexibility).
 *
 * Backward compatibility:
 *  - structure_manager_webhooks is NOT modified. The existing role_mention
 *    column stays as a legacy fallback.
 *  - Existing notify_structure_* settings are NOT deleted. They're used once
 *    here as seed input, then ignored by new code (but remain as rollback data).
 *  - On first run after the migration, every existing webhook is bound to
 *    every enabled category so current fan-out behavior is exactly preserved.
 *
 * Dispatch-time role mention precedence:
 *    1. pivot.role_mention (per-binding override)
 *    2. category.role_mention (category default)
 *    3. webhook.role_mention (legacy fallback)
 *    4. no mention
 */
class AddNotificationCategoriesAndBindings extends Migration {
    public function up(): void
    {
        // --- 1. Categories table ---
        Schema::create('structure_manager_notification_categories', function (Blueprint $table) {
            $table->id();
            $table->string('namespace', 32)->comment('upwell | events | pos — groups categories and drives UI sections');
            $table->string('category_key', 64)->comment('fuel | magmatic_gas | structure_attack | etc.');
            $table->string('display_name', 128)->comment('Shown in UI');
            $table->string('description', 255)->nullable();
            $table->boolean('enabled')->default(true)->comment('Master toggle for this category');
            $table->string('role_mention', 100)->nullable()->comment('Default Discord role mention, e.g. <@&123456789>');
            $table->string('role_source', 32)->nullable()->comment('manual | seat-connector | warlof-discord');
            $table->string('role_id', 32)->nullable()->comment('Raw Discord role ID when resolved via a connector package');
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['namespace', 'category_key'], 'smnc_ns_key_unique');
            $table->index('namespace', 'smnc_namespace_idx');
        });

        // --- 2. Category ↔ Webhook pivot ---
        Schema::create('structure_manager_category_webhook', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('category_id');
            $table->unsignedBigInteger('webhook_id');
            $table->boolean('enabled')->default(true)->comment('Per-binding enable toggle');
            $table->string('role_mention', 100)->nullable()->comment('Override for this binding; null = inherit category default');
            $table->string('role_source', 32)->nullable();
            $table->string('role_id', 32)->nullable();
            $table->timestamps();

            $table->unique(['category_id', 'webhook_id'], 'smcw_cat_wh_unique');
            $table->index('webhook_id', 'smcw_webhook_idx');
        });

        // --- 3. Seed the 8 categories (namespace, category_key, display_name, description, legacy setting key) ---
        // Enabled state is copied from existing structure_manager_settings when present.
        $seed = [
            // Upwell
            ['upwell', 'fuel',                'Fuel',                    'Upwell structure fuel (warning + critical thresholds, final 1h alert)', 'notify_upwell_fuel', 10],
            ['upwell', 'magmatic_gas',        'Magmatic Gas',            'Metenox dual-fuel: gas supply for moon drilling',                        'notify_upwell_magmatic_gas', 20],

            // ESI-driven structure events
            ['events', 'structure_attack',    'Under Attack',            'Structure/skyhook under attack, shields down, armor down, destroyed',    'notify_structure_attack', 10],
            ['events', 'structure_lifecycle', 'Lifecycle',               'Anchoring, unanchoring, ownership transferred, skyhook deployed',         'notify_structure_lifecycle', 20],
            ['events', 'structure_fuel_events', 'Fuel Events',           'Low power, high power restored, services offline, CCP fuel alerts',       'notify_structure_fuel_events', 30],

            // POS (legacy)
            ['pos',    'fuel',                'Fuel',                    'Fuel blocks + sovereignty charter alerts for Player Owned Starbases',     'notify_pos_fuel', 10],
            ['pos',    'strontium',           'Strontium',               'Strontium clathrate reinforcement alerts',                                'notify_pos_strontium', 20],
            ['pos',    'lifecycle',           'Lifecycle',               'POS state changes (online, offline, reinforced)',                         'notify_pos_lifecycle', 30],
        ];

        foreach ($seed as [$ns, $key, $display, $desc, $legacySettingKey, $sort]) {
            // Read current enabled state from legacy settings if present; default true.
            $legacyEnabled = null;
            if (Schema::hasTable('structure_manager_settings')) {
                $legacyEnabled = DB::table('structure_manager_settings')
                    ->where('key', $legacySettingKey)
                    ->value('value');
            }
            $enabled = $legacyEnabled === null ? true : (bool) $legacyEnabled;

            // Seed attack role mention from esi_attack_role_mention specifically —
            // that's the one legacy setting that was already category-scoped.
            $roleMention = null;
            if ($ns === 'events' && $key === 'structure_attack' && Schema::hasTable('structure_manager_settings')) {
                $roleMention = DB::table('structure_manager_settings')
                    ->where('key', 'esi_attack_role_mention')
                    ->value('value') ?: null;
            }

            DB::table('structure_manager_notification_categories')->insert([
                'namespace'     => $ns,
                'category_key'  => $key,
                'display_name'  => $display,
                'description'   => $desc,
                'enabled'       => $enabled,
                'role_mention'  => $roleMention,
                'role_source'   => $roleMention ? 'manual' : null,
                'sort_order'    => $sort,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }

        // --- 4. Backfill pivot: every existing webhook → every enabled category ---
        // Preserves current behavior (every webhook receives every enabled notification type).
        if (Schema::hasTable('structure_manager_webhooks')) {
            $webhookIds = DB::table('structure_manager_webhooks')->pluck('id');
            $categoryIds = DB::table('structure_manager_notification_categories')
                ->where('enabled', true)
                ->pluck('id');

            $rows = [];
            foreach ($categoryIds as $catId) {
                foreach ($webhookIds as $whId) {
                    $rows[] = [
                        'category_id' => $catId,
                        'webhook_id'  => $whId,
                        'enabled'     => true,
                        'role_mention' => null, // inherit category default
                        'created_at'  => now(),
                        'updated_at'  => now(),
                    ];
                }
            }

            if (!empty($rows)) {
                // Chunked insert for large webhook counts
                foreach (array_chunk($rows, 100) as $chunk) {
                    DB::table('structure_manager_category_webhook')->insert($chunk);
                }
            }

            Log::info("[Structure Manager] Notification migration 000022: seeded 8 categories, bound " . count($rows) . " category↔webhook pairs.");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('structure_manager_category_webhook');
        Schema::dropIfExists('structure_manager_notification_categories');
    }
}
