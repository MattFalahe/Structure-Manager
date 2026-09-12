<?php

namespace StructureManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class CleanupHistoryCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'structure-manager:cleanup-history
                            {--days=60 : Number of days to retain Upwell structure history}
                            {--pos-days=60 : Number of days to retain POS history}
                            {--reserve-days=60 : Number of days to retain Upwell reserve records}
                            {--pos-reserve-days=60 : Number of days to retain POS reserve records}
                            {--consumption-days=365 : Number of days to retain daily consumption totals}
                            {--chunk=5000 : Rows deleted per statement}
                            {--budget=120 : Seconds to spend per table before deferring the rest to the next run}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up old fuel history records (Upwell structures and POSes)';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Two different jobs, so two different windows.
        //
        // The per-poll tables (history, reserves) are the bulky ones, and
        // nothing reads them beyond 30 days: the structure and POS detail
        // charts look back 30, refuel history 30, withdrawal forensics 7.
        // 60 gives that headroom and keeps Upwell and POS on the same span,
        // which matters because the Fuel Economics "offline days" column
        // walks both tables and used to cover 180 days of Upwell against 90
        // of POS on the same row.
        //
        // The daily consumption totals are one row per structure per day,
        // and they are what the Fuel Economics trend chart and ISK columns
        // are built from. That page offers a 365-day window, and a day with
        // no row renders as zero ISK rather than as a gap, so anything
        // shorter than the longest window it offers reads as "this tower
        // cost nothing". A year of them is a few thousand rows.
        $retentionDays = (int) $this->option('days');
        $posRetentionDays = (int) $this->option('pos-days');
        $reserveRetentionDays = (int) $this->option('reserve-days');
        $posReserveRetentionDays = (int) $this->option('pos-reserve-days');
        $consumptionRetentionDays = (int) $this->option('consumption-days');

        // Clean up Upwell structures
        $this->info("Cleaning up Upwell structure history older than {$retentionDays} days...");
        $deletedCount = $this->prune('structure_fuel_history', $retentionDays);
        $this->info("Deleted {$deletedCount} old Upwell structure history records.");

        // Clean up POS data
        $this->info("Cleaning up POS history older than {$posRetentionDays} days...");
        $deletedPosCount = $this->prune('starbase_fuel_history', $posRetentionDays);
        $this->info("Deleted {$deletedPosCount} old POS history records.");

        // Reserve records. These used to be pruned inline by the tracking jobs
        // on every poll, which meant an unindexed full-table delete every 10
        // minutes. They belong here, once a day, chunked.
        $this->info("Cleaning up Upwell reserve records older than {$reserveRetentionDays} days...");
        $deletedReserves = $this->prune('structure_fuel_reserves', $reserveRetentionDays);
        $this->info("Deleted {$deletedReserves} old Upwell reserve records.");

        $this->info("Cleaning up POS reserve records older than {$posReserveRetentionDays} days...");
        $deletedPosReserves = $this->prune('starbase_fuel_reserves', $posReserveRetentionDays);
        $this->info("Deleted {$deletedPosReserves} old POS reserve records.");

        // Also clean up orphaned consumption records
        $this->cleanupConsumptionRecords($consumptionRetentionDays);

        // Clean up processed ESI notifications (keep 30 days for audit trail)
        $this->cleanupEsiNotifications();

        // v2.0.0 — webhook delivery telemetry (30-day retention)
        $this->cleanupWebhookDeliveries();

        return 0;
    }

    /**
     * Delete rows older than $days from $table in bounded chunks.
     *
     * $column is the date to measure against. It defaults to created_at, but
     * the daily consumption tables carry a `date` column for the day they
     * describe and that is the one they index, so pruning them on created_at
     * both scanned the table and measured the wrong thing: a row backfilled
     * for an older day would have been kept on its write time.
     *
     * A single unbounded DELETE across a multi-million-row backlog holds one
     * long transaction and can outlive whatever is running the command. This
     * deletes in chunks and stops when the per-table time budget is spent,
     * leaving the remainder for the next nightly run. Steady-state pruning
     * finishes in the first few chunks.
     *
     * @return int Rows deleted this run
     */
    private function prune(string $table, int $days, string $column = 'created_at'): int
    {
        if ($days < 1 || ! Schema::hasTable($table)) {
            return 0;
        }

        $cutoff = Carbon::now()->subDays($days);
        $chunk = max(100, (int) $this->option('chunk'));
        $budget = max(5, (int) $this->option('budget'));
        $deadline = microtime(true) + $budget;

        $total = 0;

        do {
            $deleted = DB::table($table)
                ->where($column, '<', $cutoff)
                ->limit($chunk)
                ->delete();

            $total += $deleted;

            if ($deleted > 0 && microtime(true) >= $deadline) {
                $this->warn("  {$table}: time budget reached after {$total} rows, remainder deferred to the next run.");
                break;
            }
        } while ($deleted > 0);

        return $total;
    }

    /**
     * Clean up old webhook delivery telemetry rows.
     * 30-day retention matches the diagnostic 24h-window UI without
     * keeping the table unbounded on active alliance installs (a busy
     * corp can produce thousands of dispatches per day).
     */
    private function cleanupWebhookDeliveries()
    {
        $deleted = \StructureManager\Services\WebhookDeliveryService::pruneOldDeliveries(30);
        if ($deleted > 0) {
            $this->info("Deleted {$deleted} old webhook delivery telemetry rows.");
        }
    }

    /**
     * Daily consumption totals, one row per structure per day.
     *
     * Pruned on `date` rather than created_at: that is the day the row is
     * about, and it is the column both tables index.
     */
    private function cleanupConsumptionRecords(int $days)
    {
        $this->info("Cleaning up daily consumption totals older than {$days} days...");

        $deletedConsumption = $this->prune('structure_fuel_consumption', $days, 'date');
        $this->info("Deleted {$deletedConsumption} old structure consumption records.");

        $deletedPosConsumption = $this->prune('starbase_fuel_consumption', $days, 'date');
        $this->info("Deleted {$deletedPosConsumption} old POS consumption records.");
    }

    /**
     * Clean up old processed ESI notifications.
     * Keep unprocessed forever (shouldn't exist, but safety) and processed for 30 days.
     */
    private function cleanupEsiNotifications()
    {
        if (! Schema::hasTable('structure_manager_esi_notifications')) {
            return;
        }

        // Ranged on `timestamp`, which is indexed, rather than created_at,
        // which is not. The two differ by the delay between EVE sending the
        // notification and the poll that stored it, which is irrelevant at a
        // 30-day retention.
        $deletedEsi = DB::table('structure_manager_esi_notifications')
            ->where('processed', true)
            ->where('timestamp', '<', Carbon::now()->subDays(30))
            ->delete();

        $this->info("Deleted {$deletedEsi} old processed ESI notification records.");
    }
}
