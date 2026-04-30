<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tags on Structure Board timers — free-form labels (raikia-style) for
 * categorization beyond the fixed event_type enum.
 *
 * Use cases:
 *   - Mark related ops with a campaign code ("op-stillwater")
 *   - Tag attackers / defenders by alliance ("vs-tribe", "ally-trc")
 *   - Group fuel timers by structure tier ("citadel-l", "citadel-xl")
 *   - Custom workflow tags ("triage", "blue-bowl-special", "doctrine-armor")
 *
 * Schema is a simple many-to-many join between timers and free-form tag
 * strings. No tag dictionary table — tags are just strings, normalized
 * to lowercase at insert time. Operators discover existing tags via the
 * filter UI on the Structure Board.
 *
 * On timer delete, ON DELETE CASCADE removes the tag rows. No need for
 * housekeeping jobs.
 */
class CreateStructureManagerTimerTagsTable extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('structure_manager_timers')) {
            // Defensive — base table should exist via migration 000023.
            return;
        }

        Schema::create('structure_manager_timer_tags', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('timer_id');
            $table->string('tag', 64)
                ->comment('Lowercased label string. Operators see them as chips on timer cards.');
            $table->timestamp('created_at')->nullable();

            // Lookup paths
            $table->index('timer_id', 'smtt_timer_idx');
            $table->index('tag', 'smtt_tag_idx');

            // No duplicate tags per timer
            $table->unique(['timer_id', 'tag'], 'smtt_timer_tag_unique');

            // Cascade delete: when a timer is destroyed, its tags go with it
            $table->foreign('timer_id', 'smtt_timer_fk')
                ->references('id')->on('structure_manager_timers')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('structure_manager_timer_tags');
    }
}
