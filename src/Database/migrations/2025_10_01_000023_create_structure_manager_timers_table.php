<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structure Board timer table (v2 Phase 1).
 *
 * Unified store for every structure lifecycle event the Structure Board
 * surfaces — auto-detected fuel warnings, auto-detected reinforce timers
 * from ESI notifications, anchor/unanchor events, and manually-entered
 * hostile/defense ops. One row per event.
 *
 * Visibility model:
 *   - corporation_id NULL  = global (only used for manually-entered hostile
 *     ops broadcast to all corps)
 *   - corporation_id set   = visible to users who have a character in that corp
 *   - role_id              = orthogonal opsec gate on top of corp visibility
 *   - group_id             = UUID linking clone rows created by 'All my
 *     corporations' manual-entry expansion, so editing one can propagate
 *     to the others if needed
 *
 * Deduplication:
 *   Auto-generated rows are upserted keyed on (source, event_type,
 *   structure_id, eve_time) — so the fuel tracker re-running doesn't
 *   create duplicate fuel_warning entries for the same structure at the
 *   same threshold-crossing moment.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('structure_manager_timers', function (Blueprint $table) {
            $table->id();

            // Event classification
            $table->string('source', 32)
                ->comment('auto_fuel | auto_reinforce | auto_anchor | auto_unanchor | manual_defense | manual_offense');
            $table->string('event_type', 48)
                ->comment('fuel_warning | fuel_critical | fuel_final | reinforce_shield | reinforce_armor | reinforce_hull | anchor_start | anchor_complete | unanchor_start | unanchor_complete | hostile_op | defense_op');
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

            // Dedup key for auto-events (notification_id for reinforce, structure_id+event_type for fuel, etc.)
            $table->string('source_reference', 255)->nullable();

            $table->timestamps();

            // Dedup: auto-upsert key. For manual rows we allow NULL + duplicates
            // (a MySQL unique index treats NULL as "not equal" so manual rows with
            //  NULL source_reference stack freely; auto rows with matching reference
            //  upsert via updateOrCreate).
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
    }

    public function down(): void
    {
        Schema::dropIfExists('structure_manager_timers');
    }
};
