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
        // Add scheduled fuel tracking
        Schedule::firstOrCreate(
            ['command' => 'structure-manager:track-fuel'],
            [
                'expression'        => '0 * * * *', // Every hour
                'allow_overlap'     => false,
                'allow_maintenance' => false,
            ]
        );

        // Add history cleanup
        Schedule::firstOrCreate(
            ['command' => 'structure-manager:cleanup-history'],
            [
                'expression'        => '0 3 * * *', // Daily at 3 AM
                'allow_overlap'     => false,
                'allow_maintenance' => false,
            ]
        );
        
        // Add consumption analysis
        Schedule::firstOrCreate(
            ['command' => 'structure-manager:analyze-consumption'],
            [
                'expression'        => '*/30 * * * *', // Every 30 minutes
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
        Schedule::where('command', 'structure-manager:track-fuel')->delete();
        Schedule::where('command', 'structure-manager:cleanup-history')->delete();
        Schedule::where('command', 'structure-manager:analyze-consumption')->delete();
    }
};
