<?php

namespace StructureManager\Database\Seeders;

use Illuminate\Database\Seeder;
use Seat\Services\Models\Schedule;

class ScheduleSeeder extends Seeder
{
    /**
     * List of schedules to seed
     *
     * @var array
     */
    protected $schedules = [
        [
            'command'           => 'structure-manager:track-fuel',
            'expression'        => '0 * * * *', // Run every hour at minute 0
            'allow_overlap'     => false,
            'allow_maintenance' => false,
            'ping_before'       => null,
            'ping_after'        => null,
        ],
        [
            'command'           => 'structure-manager:cleanup-history',
            'expression'        => '0 3 * * *', // Run daily at 3 AM
            'allow_overlap'     => false,
            'allow_maintenance' => false,
            'ping_before'       => null,
            'ping_after'        => null,
        ],
        [
            'command'           => 'structure-manager:analyze-consumption',
            'expression'        => '*/30 * * * *', // Run every 30 minutes
            'allow_overlap'     => false,
            'allow_maintenance' => false,
            'ping_before'       => null,
            'ping_after'        => null,
        ],
    ];

    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        foreach ($this->schedules as $schedule) {
            $existing = Schedule::where('command', $schedule['command'])->first();
            
            if (!$existing) {
                Schedule::create($schedule);
                $this->command->info('Seeded schedule for: ' . $schedule['command']);
            } else {
                $this->command->info('Schedule already exists for: ' . $schedule['command']);
            }
        }
    }
}
