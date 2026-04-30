<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Family B (cross-plugin timer.* events) — adds emission-tracking columns
 * to structure_manager_timers so the scheduler job can fire `upcoming_24h`,
 * `upcoming_1h`, and `elapsed` events at most once per timer.
 *
 * Without these latches, the scheduler would re-emit the same upcoming_24h
 * event every poll cycle the timer is still in the 24-hour window. Each
 * column is set when the corresponding event has been emitted; null = not
 * emitted yet.
 *
 * The columns are intentionally additive — existing rows get null defaults
 * which means the scheduler will treat them as "not yet emitted" and may
 * emit retroactively for timers already in the upcoming window at deploy
 * time. That's the correct behavior: subscribers freshly come online and
 * SEE these timers.
 *
 * @see project_plugin_integration_contracts.md (memory) for Family B contract
 * @see PublishTimerScheduleEvents (the job that reads + writes these columns)
 */
class AddTimerEventEmissionTracking extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('structure_manager_timers')) {
            // Defensive — base table should always exist via migration 000023,
            // but skip cleanly if it doesn't (e.g. fresh install order quirk).
            return;
        }

        Schema::table('structure_manager_timers', function (Blueprint $table) {
            // Set when structure_manager.timer.upcoming_24h has fired for this
            // timer. Cleared if the timer's eve_time changes (handled in the
            // scheduler logic, not via DB constraint).
            if (!Schema::hasColumn('structure_manager_timers', 'emitted_upcoming_24h_at')) {
                $table->timestamp('emitted_upcoming_24h_at')->nullable()->after('dismissed_at');
            }

            // Set when structure_manager.timer.upcoming_1h has fired.
            if (!Schema::hasColumn('structure_manager_timers', 'emitted_upcoming_1h_at')) {
                $table->timestamp('emitted_upcoming_1h_at')->nullable()->after('emitted_upcoming_24h_at');
            }

            // Set when structure_manager.timer.elapsed has fired (eve_time
            // passed). Distinct from dismissed — a timer can be elapsed AND
            // not dismissed (admin hasn't cleared it yet).
            if (!Schema::hasColumn('structure_manager_timers', 'emitted_elapsed_at')) {
                $table->timestamp('emitted_elapsed_at')->nullable()->after('emitted_upcoming_1h_at');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('structure_manager_timers')) {
            return;
        }

        Schema::table('structure_manager_timers', function (Blueprint $table) {
            $cols = [];
            if (Schema::hasColumn('structure_manager_timers', 'emitted_upcoming_24h_at')) {
                $cols[] = 'emitted_upcoming_24h_at';
            }
            if (Schema::hasColumn('structure_manager_timers', 'emitted_upcoming_1h_at')) {
                $cols[] = 'emitted_upcoming_1h_at';
            }
            if (Schema::hasColumn('structure_manager_timers', 'emitted_elapsed_at')) {
                $cols[] = 'emitted_elapsed_at';
            }
            if (!empty($cols)) {
                $table->dropColumn($cols);
            }
        });
    }
}
