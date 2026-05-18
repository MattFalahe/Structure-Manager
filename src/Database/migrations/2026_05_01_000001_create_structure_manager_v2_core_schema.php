<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Structure Manager v2.0.0 — core schema consolidation.
 *
 * First migration of the v2 series (2026_05_01_*). Consolidates the
 * granular schema-evolution migrations that landed incrementally during
 * dev-4.0 development into one create-tables migration that's easier to
 * read, easier to deploy on fresh installs, and identifies the v2 schema
 * baseline by the date-prefix bump.
 *
 * Tables created in this migration:
 *   - structure_notification_status            (Upwell fuel alert tracking)
 *   - structure_manager_esi_notifications      (ESI notification dedup + audit)
 *   - structure_manager_notification_categories (per-namespace category toggles)
 *   - structure_manager_category_webhook        (category↔webhook pivot)
 *   - structure_manager_timers                  (Structure Board events)
 *   - structure_manager_disappearance_tracking  (destruction MEDIUM-confidence detection)
 *   - structure_manager_killmail_enrichments    (zKB-enriched destruction details)
 *   - structure_manager_timer_tags              (free-form board labels)
 *
 * Table dropped (legacy dev-4.0 residue):
 *   - structure_manager_esi_key_holders  — pre-v2.0.0 SM-local fast-poll pool.
 *     v2.0.0 architecture: Manager Core owns the key pool when installed
 *     (manager_core_esi_key_holders). SM standalone uses SeAT native sweep
 *     and doesn't need an SM-local key pool. The original create migration
 *     was on dev-4.0 only; operators on existing dev-4.0 test installs get
 *     the dormant table removed; fresh installs never see it.
 *
 * Idempotency: every CREATE TABLE is wrapped in Schema::hasTable to skip
 * cleanly on dev-4.0 installs that already ran the granular migrations.
 * Existing data is preserved.
 *
 * For category seeds (initial 8 base + 9 v2-era extensions) see
 * companion migration 2026_05_01_000002_seed_structure_manager_v2_notification_categories.
 *
 * For forward-only data cleanups (legacy pos_strontium board rows, stale
 * under_attack rows from pre-fix mapping) see migration
 * 2026_05_01_000003_run_structure_manager_v2_forward_cleanups.
 *
 * Filename and class name match Laravel's filename → StudlyCase derivation
 * (`create_structure_manager_v2_core_schema` → `CreateStructureManagerV2CoreSchema`)
 * so Laravel's Migrator resolves the class by name without workarounds.
 * The name is intentionally verbose: it's globally-unique across the SeAT
 * plugin ecosystem (every plugin loads migrations into PHP's global
 * namespace), and identifies this as Structure Manager v2 schema.
 */
class CreateStructureManagerV2CoreSchema extends Migration {
    public function up(): void
    {
        // ============================================================
        // 1. structure_notification_status — Upwell fuel alert tracking
        // ============================================================
        if (!Schema::hasTable('structure_notification_status')) {
            Schema::create('structure_notification_status', function (Blueprint $table) {
                $table->id();
                $table->bigInteger('structure_id')->unique()
                    ->comment('Upwell structure ID from corporation_structures');
                $table->bigInteger('corporation_id')->index()
                    ->comment('Corporation ID for corp-scoped queries');

                // Fuel notification tracking
                $table->string('last_fuel_notification_status', 20)->nullable()
                    ->comment('Last fuel notification status: good/warning/critical');
                $table->timestamp('last_fuel_notification_at')->nullable()
                    ->comment('When last fuel notification was sent');
                $table->boolean('fuel_final_alert_sent')->default(false)
                    ->comment('True if the 1-hour final alert was sent');

                // Gas notification tracking (Metenox only)
                $table->string('last_gas_notification_status', 20)->nullable()
                    ->comment('Last magmatic gas notification status (Metenox only)');
                $table->timestamp('last_gas_notification_at')->nullable()
                    ->comment('When last gas notification was sent');

                $table->timestamps();

                $table->index('last_fuel_notification_status', 'sns_fuel_status_idx');
            });
        }

        // ============================================================
        // 2. structure_manager_esi_notifications — dedup + audit
        // ============================================================
        if (!Schema::hasTable('structure_manager_esi_notifications')) {
            Schema::create('structure_manager_esi_notifications', function (Blueprint $table) {
                $table->id();
                $table->bigInteger('notification_id')->unique()
                    ->comment('CCP globally unique notification ID — dedup key');
                $table->bigInteger('character_id')
                    ->comment('Director character we polled this from');
                $table->bigInteger('corporation_id')->index()
                    ->comment('Corporation this notification pertains to');
                $table->string('type', 100)->index()
                    ->comment('CCP notification type string (e.g. StructureUnderAttack)');
                $table->bigInteger('sender_id')->nullable()
                    ->comment('Entity that sent the notification');
                $table->string('sender_type', 50)->nullable()
                    ->comment('character, corporation, alliance, faction, other');
                $table->dateTime('timestamp')
                    ->comment('When CCP generated the notification');
                $table->text('text')->nullable()
                    ->comment('Raw YAML payload from CCP');
                $table->json('parsed_data')->nullable()
                    ->comment('Plugin-extracted key fields for quick access');
                $table->string('source', 20)->default('fast_poll')
                    ->comment('fast_poll | seat_fallback | seat_native');
                $table->boolean('processed')->default(false)
                    ->comment('True after webhook dispatch sent');
                $table->dateTime('processed_at')->nullable()
                    ->comment('When webhook dispatch was sent');
                $table->timestamps();

                $table->index(['corporation_id', 'type']);
                $table->index(['processed', 'type']);
                $table->index('timestamp');
            });
        }

        // ============================================================
        // 3. structure_manager_notification_categories — category toggles
        // ============================================================
        if (!Schema::hasTable('structure_manager_notification_categories')) {
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
        }

        // ============================================================
        // 4. structure_manager_category_webhook — pivot
        // ============================================================
        if (!Schema::hasTable('structure_manager_category_webhook')) {
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
        }

        // ============================================================
        // 5. structure_manager_timers — Structure Board events
        //    Includes ALL latch columns up-front (emitted_upcoming_24h_at,
        //    emitted_upcoming_6h_at, emitted_upcoming_1h_at, emitted_elapsed_at)
        //    that the granular dev-4.0 migrations added after the initial
        //    create. Consolidated here for fresh installs.
        // ============================================================
        if (!Schema::hasTable('structure_manager_timers')) {
            Schema::create('structure_manager_timers', function (Blueprint $table) {
                $table->id();

                // Event classification
                $table->string('source', 32)
                    ->comment('auto_fuel | auto_reinforce | auto_anchor | auto_unanchor | auto_sov | manual_defense | manual_offense');
                $table->string('event_type', 48)
                    ->comment('fuel_warning | fuel_critical | fuel_final | under_attack | reinforce_shield | reinforce_armor | reinforce_hull | destroyed | anchor_start | anchor_complete | unanchor_start | unanchor_complete | ownership_transferred | sov_reinforced | command_node_spawned | entosis_in_progress | hostile_op | defense_op');
                $table->string('severity', 16)->default('info')
                    ->comment('info | warning | critical');

                // Structure reference — nullable for manual ops on untracked enemy structures
                $table->bigInteger('structure_id')->nullable();
                $table->string('structure_name', 255)->nullable();
                $table->string('structure_type', 128)->nullable();
                $table->integer('structure_type_id')->nullable()
                    ->comment('For image rendering via images.evetech.net');
                $table->integer('system_id')->nullable();
                $table->string('system_name', 128)->nullable();
                $table->decimal('system_security', 5, 4)->nullable();

                // Visibility layer 1: corp scope (NULL = global)
                $table->bigInteger('corporation_id')->nullable();

                // Visibility layer 2: orthogonal role gate (opsec on top of corp)
                $table->unsignedInteger('role_id')->nullable();

                // Link clones from 'All my corporations' manual-entry expansion
                $table->uuid('group_id')->nullable();

                // Attribution metadata (displayed only — not used for visibility filtering)
                $table->string('owner_corporation_name', 255)->nullable()
                    ->comment('Owner of the structure. Our corp for auto-events; enemy corp for hostile_op.');
                $table->string('attacker_corporation_name', 255)->nullable()
                    ->comment('Aggressor. NULL for fuel/anchor/unanchor; hostile for reinforce; us for hostile_op.');

                // When the event happens
                $table->timestamp('eve_time')->index('smt_eve_time_idx');

                // Admin notes
                $table->text('notes')->nullable();

                // Lifecycle
                $table->timestamp('dismissed_at')->nullable();
                $table->unsignedInteger('created_by_user_id')->nullable();

                // Dedup key for auto-events
                $table->string('source_reference', 255)->nullable();

                // Family B (timer.* event) emission latches — fire-once-per-window
                // markers so the scheduler doesn't re-emit on subsequent ticks
                // while a timer is still inside the upcoming/elapsed window.
                $table->timestamp('emitted_upcoming_24h_at')->nullable();
                $table->timestamp('emitted_upcoming_6h_at')->nullable();
                $table->timestamp('emitted_upcoming_1h_at')->nullable();
                $table->timestamp('emitted_elapsed_at')->nullable();

                $table->timestamps();

                // Dedup: auto-upsert key
                $table->unique(
                    ['source', 'event_type', 'structure_id', 'eve_time'],
                    'smt_dedup_unique'
                );

                // Filter-path indexes
                $table->index(['corporation_id', 'eve_time'], 'smt_corp_time_idx');
                $table->index('structure_id', 'smt_structure_idx');
                $table->index('role_id', 'smt_role_idx');
                $table->index('group_id', 'smt_group_idx');
                $table->index(['dismissed_at', 'eve_time'], 'smt_active_time_idx');
            });
        } else {
            // Existing dev-4.0 install — ensure the four Family B emission
            // latch columns (24h / 6h / 1h / elapsed) are present. They were
            // added incrementally by since-removed granular migrations;
            // hasColumn guards skip cleanly if already in place.
            Schema::table('structure_manager_timers', function (Blueprint $table) {
                if (!Schema::hasColumn('structure_manager_timers', 'emitted_upcoming_24h_at')) {
                    $table->timestamp('emitted_upcoming_24h_at')->nullable()->after('dismissed_at');
                }
                if (!Schema::hasColumn('structure_manager_timers', 'emitted_upcoming_6h_at')) {
                    $table->timestamp('emitted_upcoming_6h_at')->nullable()->after('emitted_upcoming_24h_at');
                }
                if (!Schema::hasColumn('structure_manager_timers', 'emitted_upcoming_1h_at')) {
                    $table->timestamp('emitted_upcoming_1h_at')->nullable()->after('emitted_upcoming_6h_at');
                }
                if (!Schema::hasColumn('structure_manager_timers', 'emitted_elapsed_at')) {
                    $table->timestamp('emitted_elapsed_at')->nullable()->after('emitted_upcoming_1h_at');
                }
            });
        }

        // ============================================================
        // 6. structure_manager_disappearance_tracking
        // ============================================================
        if (!Schema::hasTable('structure_manager_disappearance_tracking')) {
            Schema::create('structure_manager_disappearance_tracking', function (Blueprint $table) {
                $table->id();
                $table->bigInteger('structure_id')->unique('smdt_structure_unique');

                $table->timestamp('last_seen_at')->nullable();
                $table->string('last_known_state', 64)->nullable()
                    ->comment('shield_vulnerable | armor_vulnerable | hull_vulnerable | shield_reinforce | armor_reinforce | hull_reinforce | anchoring | online | etc.');
                $table->timestamp('last_known_fuel_expires')->nullable();
                $table->bigInteger('last_known_corporation_id')->nullable();
                $table->integer('last_known_type_id')->nullable();
                $table->string('last_known_structure_name', 255)->nullable();
                $table->integer('last_known_system_id')->nullable();
                $table->string('last_known_system_name', 128)->nullable();
                $table->decimal('last_known_system_security', 5, 4)->nullable();

                $table->integer('consecutive_misses')->default(0)
                    ->comment('Number of polls in a row where structure was absent. Reset to 0 on reappearance.');
                $table->string('status', 32)->default('watching')
                    ->comment('watching | destroyed | likely_transferred | bulk_vanished | reappeared');
                $table->string('detection_source', 32)->nullable()
                    ->comment('notification (high) | grace_period (medium) | reappeared (cleared) — null while watching');
                $table->timestamp('resolved_at')->nullable()
                    ->comment('When status transitioned out of watching');

                $table->timestamps();

                $table->index('status', 'smdt_status_idx');
                $table->index('last_known_corporation_id', 'smdt_corp_idx');
                $table->index('last_seen_at', 'smdt_last_seen_idx');
            });
        }

        // ============================================================
        // 7. structure_manager_killmail_enrichments
        // ============================================================
        if (!Schema::hasTable('structure_manager_killmail_enrichments')) {
            Schema::create('structure_manager_killmail_enrichments', function (Blueprint $table) {
                $table->id();

                $table->bigInteger('structure_id')->unique('smke_structure_unique');
                $table->bigInteger('corporation_id')->comment('Owner corp at time of destruction');
                $table->integer('structure_type_id');
                $table->integer('system_id')->nullable();
                $table->timestamp('destroyed_at')->nullable()
                    ->comment('Best-known time of destruction; from CCP timestamp or last-seen+15min midpoint');
                $table->string('original_event_id', 64)
                    ->comment('Stage 1 event_id (sm-evt-{uuid}) — subscribers correlate stage 1 + stage 2 via this');

                $table->string('status', 16)->default('pending')
                    ->comment('pending | enriched | not_found');
                $table->integer('attempts')->default(0);
                $table->timestamp('last_attempted_at')->nullable();
                $table->timestamp('enriched_at')->nullable();
                $table->timestamp('gave_up_at')->nullable()
                    ->comment('Set when retry budget exhausted, transitions status to not_found');
                $table->timestamp('published_at')->nullable()
                    ->comment('Stage 2 event publish guard — set when destroyed_confirmed fired');

                $table->bigInteger('killmail_id')->nullable();
                $table->string('killmail_hash', 64)->nullable();
                $table->string('killmail_url', 255)->nullable();
                $table->timestamp('killmail_time')->nullable();

                $table->bigInteger('final_blow_character_id')->nullable();
                $table->string('final_blow_character_name', 255)->nullable();
                $table->bigInteger('final_blow_corporation_id')->nullable();
                $table->string('final_blow_corporation_name', 255)->nullable();
                $table->bigInteger('final_blow_alliance_id')->nullable();
                $table->string('final_blow_alliance_name', 255)->nullable();
                $table->integer('final_blow_ship_type_id')->nullable();
                $table->string('final_blow_ship_type', 128)->nullable();

                $table->bigInteger('top_damage_character_id')->nullable();
                $table->string('top_damage_character_name', 255)->nullable();
                $table->integer('top_damage_ship_type_id')->nullable();
                $table->string('top_damage_ship_type', 128)->nullable();

                $table->integer('attacker_count')->nullable();
                $table->decimal('isk_value', 20, 2)->nullable()
                    ->comment('Total ISK value of the destroyed structure per zKB');
                $table->integer('zkb_points')->nullable();

                $table->timestamps();

                $table->index('status', 'smke_status_idx');
                $table->index('corporation_id', 'smke_corp_idx');
                $table->index('killmail_id', 'smke_killmail_idx');
            });
        }

        // ============================================================
        // 8. structure_manager_timer_tags
        // ============================================================
        if (Schema::hasTable('structure_manager_timers')
            && !Schema::hasTable('structure_manager_timer_tags')) {
            Schema::create('structure_manager_timer_tags', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('timer_id');
                $table->string('tag', 64)
                    ->comment('Lowercased label string. Operators see them as chips on timer cards.');
                $table->timestamp('created_at')->nullable();

                $table->index('timer_id', 'smtt_timer_idx');
                $table->index('tag', 'smtt_tag_idx');
                $table->unique(['timer_id', 'tag'], 'smtt_timer_tag_unique');

                $table->foreign('timer_id', 'smtt_timer_fk')
                    ->references('id')->on('structure_manager_timers')
                    ->onDelete('cascade');
            });
        }

        // ============================================================
        // 9. Drop legacy SM-local key_holders table (MC owns the pool now)
        // ============================================================
        // The structure_manager_esi_key_holders table existed only on dev-4.0
        // test installs (it was created by a since-removed granular migration
        // before Manager Core took over key pool ownership). v2.0.0 ships
        // with MC as the canonical key pool owner; SM standalone uses SeAT
        // native sweep and has no need for an SM-local key pool table.
        //
        // For operators upgrading from dev-4.0 test installs: the dormant
        // table is removed. Any data still in it has either been migrated
        // to MC's pool already (via the old 000021 migration that we're
        // also dropping) or was never used. No data loss possible for
        // production paths.
        //
        // For fresh v2.0.0 installs: this dropIfExists is a no-op (the
        // table was never created).
        if (Schema::hasTable('structure_manager_esi_key_holders')) {
            Schema::dropIfExists('structure_manager_esi_key_holders');
            Log::info('[Structure Manager] v2 schema migration: dropped legacy structure_manager_esi_key_holders (MC owns the key pool in v2.0.0+).');
        }
    }

    public function down(): void
    {
        // Forward-only consolidation. Rolling back to the granular dev-4.0
        // migration set is not supported — operators wanting an older state
        // should re-deploy a v1.x release of the plugin, not roll back this
        // migration.
        //
        // The down() does still drop the v2-era tables this migration
        // created, in case an operator wants to fully reset the v2 schema
        // for development purposes.
        Schema::dropIfExists('structure_manager_timer_tags');
        Schema::dropIfExists('structure_manager_killmail_enrichments');
        Schema::dropIfExists('structure_manager_disappearance_tracking');
        Schema::dropIfExists('structure_manager_timers');
        Schema::dropIfExists('structure_manager_category_webhook');
        Schema::dropIfExists('structure_manager_notification_categories');
        Schema::dropIfExists('structure_manager_esi_notifications');
        Schema::dropIfExists('structure_notification_status');
    }
}
