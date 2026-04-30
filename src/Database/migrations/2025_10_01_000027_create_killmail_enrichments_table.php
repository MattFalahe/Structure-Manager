<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Killmail enrichment table — supports Tier C Stage 2 of the cross-plugin
 * structure.alert.* event family. When SM publishes structure.alert.destroyed
 * (either via the CCP-notification path or the grace-period path), an async
 * EnrichKillmailJob runs against zKB to find the killmail and resolve full
 * attacker / ISK-value details, then publishes a follow-up
 * structure.alert.destroyed_confirmed event correlated by original_event_id.
 *
 * One row per ever-destroyed structure. Survives plugin restarts. Not synced
 * to MC — purely SM-owned tracking.
 *
 * Status lifecycle:
 *   pending   → on first dispatch (job hasn't run, or last attempt failed
 *               but retries remain)
 *   enriched  → zKB returned a matching killmail; full details captured;
 *               destroyed_confirmed event published with enrichment_outcome=enriched
 *   not_found → retry budget exhausted without finding a match; published
 *               destroyed_confirmed event with enrichment_outcome=not_found_in_zkb
 *               so subscribers know enrichment ran and failed (vs still pending)
 *
 * The published_at column is the publish-idempotency guard — set when the
 * stage 2 event is dispatched on the EventBus, used to skip republishing on
 * job retry edge cases.
 *
 * Indexes:
 *   - structure_id UNIQUE — primary lookup, enforces 1-row-per-structure
 *   - status — for "find pending enrichments" admin queries / cleanup jobs
 *   - corporation_id — for "show me this corp's structure losses" reports
 *   - killmail_id — for cross-reference back to the killmail itself
 *
 * @see project_structure_manager_v2.md (Tier C Stage 2 design)
 * @see EnrichKillmailJob
 */
class CreateStructureManagerKillmailEnrichmentsTable extends Migration {
    public function up(): void
    {
        Schema::create('structure_manager_killmail_enrichments', function (Blueprint $table) {
            $table->id();

            // Identity + correlation
            $table->bigInteger('structure_id')->unique('smke_structure_unique');
            $table->bigInteger('corporation_id')->comment('Owner corp at time of destruction');
            $table->integer('structure_type_id');
            $table->integer('system_id')->nullable();
            $table->timestamp('destroyed_at')->nullable()
                ->comment('Best-known time of destruction; from CCP timestamp or last-seen+15min midpoint');
            $table->string('original_event_id', 64)
                ->comment('Stage 1 event_id (sm-evt-{uuid}) — subscribers correlate stage 1 + stage 2 via this');

            // Job lifecycle
            $table->string('status', 16)->default('pending')
                ->comment('pending | enriched | not_found');
            $table->integer('attempts')->default(0);
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('enriched_at')->nullable();
            $table->timestamp('gave_up_at')->nullable()
                ->comment('Set when retry budget exhausted, transitions status to not_found');
            $table->timestamp('published_at')->nullable()
                ->comment('Stage 2 event publish guard — set when destroyed_confirmed fired');

            // Killmail core (nullable until enriched)
            $table->bigInteger('killmail_id')->nullable();
            $table->string('killmail_hash', 64)->nullable();
            $table->string('killmail_url', 255)->nullable();
            $table->timestamp('killmail_time')->nullable();

            // Final-blow attacker
            $table->bigInteger('final_blow_character_id')->nullable();
            $table->string('final_blow_character_name', 255)->nullable();
            $table->bigInteger('final_blow_corporation_id')->nullable();
            $table->string('final_blow_corporation_name', 255)->nullable();
            $table->bigInteger('final_blow_alliance_id')->nullable();
            $table->string('final_blow_alliance_name', 255)->nullable();
            $table->integer('final_blow_ship_type_id')->nullable();
            $table->string('final_blow_ship_type', 128)->nullable();

            // Top damage attacker (often more meaningful than final blow on structures)
            $table->bigInteger('top_damage_character_id')->nullable();
            $table->string('top_damage_character_name', 255)->nullable();
            $table->integer('top_damage_ship_type_id')->nullable();
            $table->string('top_damage_ship_type', 128)->nullable();

            // Aggregate killmail data
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

    public function down(): void
    {
        Schema::dropIfExists('structure_manager_killmail_enrichments');
    }
}
