<?php

namespace StructureManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

/**
 * One-off compaction of rows the tracking jobs used to write and no longer do.
 *
 * Two kinds of row are removed:
 *
 *   1. Reserve snapshots that recorded no change. Before v2.0.4 the POS tracker
 *      wrote a row for every tuple on every 10-minute poll whether or not the
 *      quantity had moved. Those rows are self-identifying: an unchanged poll
 *      stored quantity_change = 0, a real move stored non-zero, and the first
 *      sighting of a tuple stored NULL.
 *
 *   2. History for towers that were not online. Before v2.0.4 an offline tower
 *      was polled like any other, so it accumulated 144 identical rows a day
 *      describing a tower that was consuming nothing.
 *
 * Deliberately NOT a migration. Migrations run unattended on every operator's
 * container restart, and a multi-million-row delete during boot turns an
 * upgrade into an outage. This is opt-in, reports before it acts, and is
 * resumable, so a large install can drain in stages.
 */
class CompactHistoryCommand extends Command
{
    protected $signature = 'structure-manager:compact-history
                            {--force : Actually delete. Without this the command only reports what it would remove}
                            {--days=7 : Leave anything newer than this alone}
                            {--chunk=5000 : Rows deleted per statement}
                            {--budget=300 : Seconds to spend per table before deferring the rest to the next run}';

    protected $description = 'Remove redundant reserve snapshots and offline-tower history left behind by pre-v2.0.4 tracking';

    public function handle()
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = Carbon::now()->subDays($days);
        $live = (bool) $this->option('force');

        $this->info($live
            ? "Compacting rows older than {$days} days ({$cutoff})."
            : "DRY RUN. Nothing will be deleted. Re-run with --force to apply.");
        $this->newLine();

        $total = 0;
        $total += $this->compactReserves('starbase_fuel_reserves', $cutoff, $live);
        $total += $this->compactReserves('structure_fuel_reserves', $cutoff, $live);
        $total += $this->compactOfflineHistory($cutoff, $live);

        $this->newLine();
        $this->info($live
            ? "Done. Removed " . number_format($total) . " rows."
            : "Would remove " . number_format($total) . " rows. Re-run with --force to apply.");

        return 0;
    }

    /**
     * Reserve rows that recorded no change.
     *
     * The cutoff is what makes this safe. Every reader wants the newest row per
     * tuple, and that row is never in range: before the upgrade the old code
     * wrote one every 10 minutes, and after it the 24-hour heartbeat keeps one
     * fresh. A tuple that genuinely emptied has a depletion row, which carries
     * is_refuel_event = 1 and is excluded here along with the rest of the
     * refuel and withdrawal trail.
     */
    private function compactReserves(string $table, Carbon $cutoff, bool $live): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        $filter = fn () => DB::table($table)
            ->where('quantity_change', 0)
            ->where('is_refuel_event', false)
            ->where('created_at', '<', $cutoff);

        $candidates = (clone $filter())->count();
        $keep = DB::table($table)->count() - $candidates;

        $this->line(sprintf(
            '  %-24s %s redundant of %s total, %s would remain',
            $table,
            number_format($candidates),
            number_format($candidates + $keep),
            number_format($keep)
        ));

        if (! $live || $candidates === 0) {
            return $candidates;
        }

        return $this->deleteInChunks($table, $filter);
    }

    /**
     * History rows for towers that were not online or reinforced.
     *
     * The most recent row per tower is always kept, whatever its state, so the
     * record of when a tower went down survives. That single row is exactly
     * what v2.0.4 writes now in place of the old 144-a-day.
     */
    private function compactOfflineHistory(Carbon $cutoff, bool $live): int
    {
        $table = 'starbase_fuel_history';

        if (! Schema::hasTable($table)) {
            return 0;
        }

        // One id per tower. Resolved once and passed as literals rather than a
        // correlated subquery re-run on every chunk.
        $keepIds = DB::table($table)
            ->selectRaw('MAX(id) AS id')
            ->groupBy('starbase_id')
            ->pluck('id')
            ->all();

        $filter = function () use ($table, $cutoff, $keepIds) {
            $q = DB::table($table)
                ->whereNotIn('state', [3, 4])
                ->where('created_at', '<', $cutoff);

            return empty($keepIds) ? $q : $q->whereNotIn('id', $keepIds);
        };

        $candidates = (clone $filter())->count();

        $this->line(sprintf(
            '  %-24s %s offline-tower rows of %s total, keeping the newest row for each of %d tower(s)',
            $table,
            number_format($candidates),
            number_format(DB::table($table)->count()),
            count($keepIds)
        ));

        if (! $live || $candidates === 0) {
            return $candidates;
        }

        return $this->deleteInChunks($table, $filter);
    }

    /**
     * Delete in bounded chunks, stopping when the per-table time budget is
     * spent so a large backlog drains across runs instead of holding one long
     * transaction.
     *
     * @param callable $filter Returns a fresh query builder for the rows to remove
     */
    private function deleteInChunks(string $table, callable $filter): int
    {
        $chunk = max(100, (int) $this->option('chunk'));
        $deadline = microtime(true) + max(5, (int) $this->option('budget'));

        $removed = 0;

        do {
            $deleted = $filter()->limit($chunk)->delete();
            $removed += $deleted;

            if ($deleted > 0 && microtime(true) >= $deadline) {
                $this->warn(sprintf(
                    '    %s: time budget reached after %s rows, run again to continue.',
                    $table,
                    number_format($removed)
                ));
                break;
            }
        } while ($deleted > 0);

        if ($removed > 0) {
            $this->line(sprintf('    removed %s from %s', number_format($removed), $table));
        }

        return $removed;
    }
}
