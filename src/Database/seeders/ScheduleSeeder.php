<?php

namespace StructureManager\Database\Seeders;

use Seat\Services\Seeding\AbstractScheduleSeeder;

class ScheduleSeeder extends AbstractScheduleSeeder
{
    public function getSchedules(): array
    {
        return [
            // Upwell Structures tracking
            [
                'command' => 'structure-manager:track-fuel',
                'expression' => '15 * * * *', // Run every hour at :15 past
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
            [
                'command' => 'structure-manager:analyze-consumption',
                'expression' => '30 * * * *', // Run every hour at :30 past
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
            
            // POS (Player Owned Starbases) tracking
            [
                'command' => 'structure-manager:track-poses-fuel',
                'expression' => '*/10 * * * *', // Run every 10 minutes
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
            [
                'command' => 'structure-manager:analyze-pos-consumption',
                'expression' => '0 1 * * *', // Run daily at 1:00 AM
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
            
            // POS Notifications
            [
                'command' => 'structure-manager:notify-pos-fuel',
                'expression' => '*/10 * * * *', // Run every 10 minutes
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
            
            // Cleanup
            [
                'command' => 'structure-manager:cleanup-history',
                'expression' => '0 3 * * *', // Run daily at 3 AM
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
        ];
    }

    /**
     * Returns a list of commands to remove from the schedule.
     *
     * @return array
     */
    public function getDeprecatedSchedules(): array
    {
        return [];
    }
}
