<?php

use Illuminate\Database\Migrations\Migration;
use Seat\Services\Models\Schedule;

/**
 * Align the track-poses-fuel schedule to the every-10-minute cadence that the
 * seeder and the rest of the plugin (POS notifications, analysis) were designed
 * around.
 *
 * Migration 2025_10_01_000011 originally registered this command at `20 * * * *`
 * (hourly at :20) while the ScheduleSeeder registered it at `*/10 * * * *`. The
 * two sources disagreed and installations ended up on whichever ran last. The
 * notifications job (also every 10 min) depends on fresh history rows, so the
 * hourly cadence left alerts up to an hour stale.
 */
return new class extends Migration {
    public function up()
    {
        $schedule = Schedule::where('command', 'structure-manager:track-poses-fuel')->first();

        if ($schedule) {
            if ($schedule->expression !== '*/10 * * * *') {
                $schedule->expression = '*/10 * * * *';
                $schedule->save();
            }
        } else {
            Schedule::create([
                'command'           => 'structure-manager:track-poses-fuel',
                'expression'        => '*/10 * * * *',
                'allow_overlap'     => false,
                'allow_maintenance' => false,
            ]);
        }
    }

    public function down()
    {
        // Revert to the earlier hourly-at-:20 expression from migration 000011.
        $schedule = Schedule::where('command', 'structure-manager:track-poses-fuel')->first();

        if ($schedule) {
            $schedule->expression = '20 * * * *';
            $schedule->save();
        }
    }
};
