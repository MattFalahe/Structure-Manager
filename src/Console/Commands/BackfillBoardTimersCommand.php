<?php

namespace StructureManager\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use StructureManager\Handlers\StructureEventHandler;

/**
 * Backfill the Structure Board's timer table from existing notifications.
 *
 * Use case: a code change adds new event_type mappings (e.g. the 2026-05-13
 * addition of sov_reinforced / entosis_in_progress / command_node_spawned)
 * but the matching notifications already fired and got marked
 * dispatched=true. Without this command, the board stays empty for the new
 * event types until brand-new live notifications arrive — operators have
 * to wait days for the next sov campaign or anchoring event.
 *
 * What it does:
 *   - Walks manager_core_esi_notifications for the last N days (default 30)
 *   - Filters to notification types the handler knows about
 *   - For each, calls StructureEventHandler::backfillTimerOnly() which
 *     writes ONLY the Structure Board timer row (no Discord webhook, no
 *     EventBus republish — those already fired the first time)
 *   - Idempotent: existing timer rows are detected via source_reference
 *     ('esi-notif:{notification_id}') and skipped, so safe to re-run
 *
 * What it does NOT do:
 *   - No webhook dispatch (would double-ping operators)
 *   - No EventBus publish (subscribers like Mining Manager already received
 *     the original event)
 *   - No SeAT character_notifications sweep — operates on MC's already-
 *     polled notifications cache only
 *
 * Safe to run any time on a server that has Manager Core installed and
 * has at least one ESI fast-poll cycle's worth of notifications cached.
 */
class BackfillBoardTimersCommand extends Command
{
    protected $signature = 'structure-manager:backfill-board-timers
                            {--days=30 : Look back this many days in manager_core_esi_notifications}
                            {--dry-run : Show what would be backfilled without writing rows}
                            {--force-update : Re-process notifications even if a timer row already exists (refreshes structure_type / owner / etc. with the latest code paths)}';

    protected $description = 'Retro-populate Structure Board timer rows from already-polled ESI notifications (idempotent unless --force-update is set).';

    /**
     * Every notification type StructureEventHandler::notificationTypeToBoardEvent
     * maps to a non-null board event. Kept in sync with that method; adding a
     * new type to the handler means adding it here too.
     */
    private const SUPPORTED_TYPES = [
        // Attack progression
        'StructureUnderAttack', 'SkyhookUnderAttack',
        'StructureLostShields', 'SkyhookLostShields',
        'StructureLostArmor',
        'StructureDestroyed', 'SkyhookDestroyed',
        // Lifecycle
        'StructureAnchoring', 'AllAnchoringMsg', 'SkyhookDeployed',
        'StructureUnanchoring',
        'OwnershipTransferred',
        // Sovereignty (added 2026-05-13 — the motivating gap for this command)
        'SovStructureReinforced',
        'EntosisCaptureStarted',
        'SovCommandNodeEventStarted',
    ];

    public function handle(): int
    {
        // The MC notifications table is the canonical source. If MC isn't
        // installed (so the table doesn't exist), the operator should be
        // running the native sweep instead — bail with a clear message.
        if (!\Schema::hasTable('manager_core_esi_notifications')) {
            $this->error('manager_core_esi_notifications table does not exist.');
            $this->line('  Manager Core appears not to be installed, or its migrations have not run.');
            $this->line('  This command operates on MC\'s notification cache — without it there\'s nothing to backfill from.');
            $this->line('  If you\'re running SM standalone (no MC), board timers are written live as character_notifications arrives; no backfill is possible for past events.');
            return 1;
        }

        $days = (int) $this->option('days');
        $dryRun = (bool) $this->option('dry-run');
        $forceUpdate = (bool) $this->option('force-update');
        $cutoff = Carbon::now()->subDays($days);

        $this->info(sprintf(
            'Scanning manager_core_esi_notifications for the last %d days (since %s)...',
            $days,
            $cutoff->toIso8601String()
        ));

        $rows = DB::table('manager_core_esi_notifications')
            ->where('created_at', '>=', $cutoff)
            ->whereIn('type', self::SUPPORTED_TYPES)
            ->orderBy('created_at')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('No matching notifications found in the window. Nothing to backfill.');
            return 0;
        }

        $this->info(sprintf('Found %d candidate notifications.', $rows->count()));

        // Count per type for the operator's awareness
        $byType = $rows->groupBy('type')->map->count();
        $this->line('Breakdown by type:');
        foreach ($byType as $type => $count) {
            $this->line(sprintf('  %s: %d', $type, $count));
        }

        if ($dryRun) {
            $this->warn('Dry-run mode: no timer rows will be written.');
            return 0;
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = 0;

        $bar = $this->output->createProgressBar($rows->count());
        $bar->start();

        foreach ($rows as $row) {
            $ref = "esi-notif:{$row->notification_id}";
            $exists = DB::table('structure_manager_timers')
                ->where('source_reference', $ref)
                ->exists();

            // Skip existing rows UNLESS --force-update was passed. With force-update
            // the existing row is re-processed through the same handler logic and
            // its fields are overwritten (upsertAuto matches on source_reference
            // and fills the existing row with the latest attrs). Useful when a code
            // change adds new resolution logic (e.g. campaignEventType -> typeId
            // fallback, alliance-name owner override) and you want existing rows
            // to pick up the new values without deleting them first.
            if ($exists && !$forceUpdate) {
                $skipped++;
                $bar->advance();
                continue;
            }

            try {
                // Reconstruct the model object that the handler expects. The
                // handler reads ->type, ->notification_id, ->parsed_data,
                // ->timestamp, ->corporation_id — all present on the row.
                // parsed_data is stored as JSON and the handler expects a PHP
                // array, so decode here.
                $notification = (object) [
                    'type'             => $row->type,
                    'notification_id'  => $row->notification_id,
                    'corporation_id'   => $row->corporation_id,
                    'timestamp'        => $row->timestamp,
                    'parsed_data'      => is_string($row->parsed_data)
                        ? (json_decode($row->parsed_data, true) ?: [])
                        : ($row->parsed_data ?? []),
                ];

                StructureEventHandler::backfillTimerOnly($notification);
                if ($exists) {
                    // We re-processed an existing row (only reachable with
                    // --force-update because of the skip-check above)
                    $updated++;
                } else {
                    $created++;
                }
            } catch (\Throwable $e) {
                $errors++;
                $this->newLine();
                $this->warn(sprintf(
                    'Failed for notification #%s (%s): %s',
                    $row->notification_id,
                    $row->type,
                    $e->getMessage()
                ));
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info(sprintf(
            'Backfill complete: %d created, %d updated, %d skipped (already existed), %d errors.',
            $created,
            $updated,
            $skipped,
            $errors
        ));

        if ($created > 0 || $updated > 0) {
            $this->line('Visit the Structure Board to see the new or refreshed timer rows.');
        }

        return $errors > 0 ? 1 : 0;
    }
}
