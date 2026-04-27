<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structure disappearance tracking — supports `structure.alert.destroyed`
 * detection via the MEDIUM-confidence "row vanished from corporation_structures
 * with last-known combat state" path. The HIGH-confidence path (CCP
 * StructureDestroyed notification) doesn't need this table — it fires via
 * StructureEventHandler immediately on notification arrival.
 *
 * The `TrackStructurePresence` job runs every 10 minutes and:
 *   1. Upserts a row per structure currently in corporation_structures
 *      (refresh `last_seen_at` + last-known fields)
 *   2. For tracked structures NOT currently present, increments
 *      consecutive_misses
 *   3. At 3 consecutive misses (~30 min absent), classifies the disappearance:
 *        - last_known_state was *vulnerable / *_reinforce → 'destroyed'
 *          (combat was active when row vanished — high enough confidence to fire)
 *        - corp had >= 2 trackings AND zero present rows at this poll → 'bulk_vanished'
 *          (token loss / corp disbanded — do NOT publish destroyed event)
 *        - otherwise → 'likely_transferred' (healthy state when last seen,
 *          probably ownership change — do NOT publish)
 *   4. Reappearance within 24h flips status back to 'watching'
 *      (handles transient ESI glitches)
 *
 * Tracking table is SM-owned. Persists across SM restarts. Not synced to MC.
 *
 * See `project_structure_manager_destruction_detection.md` for the full
 * design including the four causes of "row gone" and signal-source ranking.
 */
class CreateStructureManagerDisappearanceTrackingTable extends Migration {
    public function up(): void
    {
        Schema::create('structure_manager_disappearance_tracking', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('structure_id')->unique('smdt_structure_unique');

            // When did we last confirm this structure was present in corporation_structures?
            $table->timestamp('last_seen_at')->nullable();

            // State snapshot at last sighting — used to classify the cause of disappearance
            $table->string('last_known_state', 64)->nullable()
                ->comment('shield_vulnerable | armor_vulnerable | hull_vulnerable | shield_reinforce | armor_reinforce | hull_reinforce | anchoring | online | etc.');
            $table->timestamp('last_known_fuel_expires')->nullable();
            $table->bigInteger('last_known_corporation_id')->nullable();
            $table->integer('last_known_type_id')->nullable();
            $table->string('last_known_structure_name', 255)->nullable();
            $table->integer('last_known_system_id')->nullable();
            $table->string('last_known_system_name', 128)->nullable();
            $table->decimal('last_known_system_security', 5, 4)->nullable();

            // Disappearance progression
            $table->integer('consecutive_misses')->default(0)
                ->comment('Number of polls in a row where structure was absent. Reset to 0 on reappearance.');
            $table->string('status', 32)->default('watching')
                ->comment('watching | destroyed | likely_transferred | bulk_vanished | reappeared');
            $table->string('detection_source', 32)->nullable()
                ->comment('notification (high) | grace_period (medium) | reappeared (cleared) — null while watching');
            $table->timestamp('resolved_at')->nullable()
                ->comment('When status transitioned out of watching');

            $table->timestamps();

            // Filter-path indexes (status is heavily queried; corp_id for bulk-vanish detection)
            $table->index('status', 'smdt_status_idx');
            $table->index('last_known_corporation_id', 'smdt_corp_idx');
            $table->index('last_seen_at', 'smdt_last_seen_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('structure_manager_disappearance_tracking');
    }
}
