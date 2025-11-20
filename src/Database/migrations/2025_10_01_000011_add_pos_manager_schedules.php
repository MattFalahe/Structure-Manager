<?php

use Illuminate\Database\Migrations\Migration;
use Seat\Services\Models\Schedule;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        // Add scheduled POS fuel tracking - runs hourly at :20 past the hour
        // This gives SeAT time to update corporation_starbases and corporation_starbase_fuels tables first
        // Runs 5 minutes after Upwell structure tracking to spread out the load
        Schedule::firstOrCreate(
            ['command' => 'structure-manager:track-poses-fuel'],
            [
                'expression'        => '20 * * * *', // Every hour at :20 past
                'allow_overlap'     => false,
                'allow_maintenance' => false,
            ]
        );

        // Add POS consumption analysis - runs daily at 1:00 AM
        // This runs after all tracking data has been collected for the day
        Schedule::firstOrCreate(
            ['command' => 'structure-manager:analyze-pos-consumption'],
            [
                'expression'        => '0 1 * * *', // Daily at 1:00 AM
                'allow_overlap'     => false,
                'allow_maintenance' => false,
            ]
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        Schedule::where('command', 'structure-manager:track-poses-fuel')->delete();
        Schedule::where('command', 'structure-manager:analyze-pos-consumption')->delete();
    }
};
