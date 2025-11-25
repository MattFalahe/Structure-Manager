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
        // Add scheduled POS low fuel notifications - runs every 10 minutes
        // This job checks all POSes for critical fuel levels and sends Discord/Slack notifications
        // Separate cooldown timers for fuel/charter (6 hours) and strontium (2 hours) alerts
        // prevent notification spam while ensuring timely warnings
        Schedule::firstOrCreate(
            ['command' => 'structure-manager:notify-pos-fuel'],
            [
                'expression'        => '*/10 * * * *', // Every 10 minutes
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
        Schedule::where('command', 'structure-manager:notify-pos-fuel')->delete();
    }
};
