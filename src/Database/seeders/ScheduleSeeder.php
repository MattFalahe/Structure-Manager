<?php

namespace StructureManager\Database\Seeders;

use Illuminate\Support\Facades\DB;
use Seat\Services\Seeding\AbstractScheduleSeeder;

class ScheduleSeeder extends AbstractScheduleSeeder
{
    /**
     * Override AbstractScheduleSeeder::run() so existing installs pick up
     * cron-expression changes on subsequent deploys.
     *
     * The base class only inserts when the command does not already exist
     * (insert-if-missing), which means changing an `expression` in
     * getSchedules() never propagates to installs that already have the row.
     *
     * `updateOrInsert` keyed on `command` reconciles every field on each run
     * and matches the pattern used by Buyback Manager + HR Manager.
     */
    public function run(): void
    {
        foreach ($this->getSchedules() as $job) {
            DB::table('schedules')->updateOrInsert(
                ['command' => $job['command']],
                $job
            );
        }

        $deprecated = $this->getDeprecatedSchedules();
        if (! empty($deprecated)) {
            DB::table('schedules')->whereIn('command', $deprecated)->delete();
        }
    }

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

            // Upwell Structure Notifications
            [
                'command' => 'structure-manager:notify-upwell-fuel',
                'expression' => '*/10 * * * *', // Run every 10 minutes
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],

            // Structure Event Notifications.
            // When Manager Core is installed, MC's fast-poll (every 2 min) and
            // sweep (every 10 min) handle discovery + dispatch (this command
            // detects MC and becomes a no-op).
            // When Manager Core is NOT installed, this job reads from SeAT's
            // native character_notifications table and dispatches webhooks.
            //
            // Cadence: every minute. SeAT itself only refreshes
            // character_notifications on its 15-20 minute bucket cadence, so the
            // detection floor is set by SeAT (not this job). Running every
            // minute means SM picks up new rows immediately when SeAT writes
            // them, instead of adding up to 9 extra minutes of polling delay
            // on top of SeAT's bucket. The job is cheap (bounded query,
            // local dedup table, allow_overlap=false) so the every-minute
            // cadence is safe.
            [
                'command' => 'structure-manager:process-notifications',
                'expression' => '* * * * *', // Run every minute
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],

            // Structure presence tracking.
            // Drives the MEDIUM-confidence path of destruction detection — polls
            // corporation_structures every 10 minutes, tracks last-seen + last-
            // known state, and classifies structures that vanish for 3+ polls
            // (~30 min absent) as destroyed / likely_transferred / bulk_vanished.
            // Standalone — does NOT depend on Manager Core. The HIGH-confidence
            // path (CCP StructureDestroyed notification) fires from
            // StructureEventHandler regardless of whether MC is installed.
            [
                'command' => 'structure-manager:track-structure-presence',
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

            // Family B timer-lifecycle event publishes — without this scheduled,
            // structure_manager.timer.upcoming_24h / upcoming_1h / elapsed events
            // never fire, so subscribers (Pings, etc.) get nothing.
            [
                'command' => 'structure-manager:publish-timer-schedule-events',
                'expression' => '*/5 * * * *', // Every 5 minutes
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],

            // Structure-board timer housekeeping. Runs every 5 minutes (was
            // daily before v2.0.0 added the under_attack fast-path) because
            // under_attack rows need to auto-dismiss within minutes of their
            // 15-min auto-repair window passing, not 24 hours later.
            //
            // The job has three stages internally — fast-dismiss elapsed
            // under_attack (no grace), auto-dismiss other elapsed timers
            // (after operator-configured grace hours), permanent delete of
            // long-dismissed rows (after operator-configured retention days).
            // All three stages run on every tick; stages 2 and 3 are no-ops
            // when no matching rows exist, so the 5-min cadence is wasteless.
            [
                'command' => 'structure-manager:prune-structure-board-timers',
                'expression' => '*/5 * * * *', // Every 5 minutes
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
     * Removed in v3.1: the fast-poll + sweep moved to Manager Core. Structure
     * Manager now uses a single fallback command that defers to MC when present.
     *
     * @return array
     */
    public function getDeprecatedSchedules(): array
    {
        return [
            'structure-manager:poll-structure-notifications',
            'structure-manager:sweep-seat-notifications',
        ];
    }
}
