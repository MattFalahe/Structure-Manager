<?php

namespace StructureManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use StructureManager\Services\TestDataGenerator;

/**
 * Symmetric teardown for everything the test-data family of commands creates.
 *
 * Covers the surface produced by:
 *   - structure-manager:create-test-upwell-structures
 *   - structure-manager:create-test-poses
 *   - structure-manager:create-test-metenox
 *   - structure-manager:inject-test-notification
 *
 * Delegates the bulk of the cleanup to TestDataGenerator::cleanupAll(), then
 * extends the sweep to Manager Core's notification dedup table (which the
 * service-layer cleanup intentionally doesn't touch because that table is
 * owned by Manager Core, not Structure Manager).
 *
 * Safety: all deletes are bounded by the safe ID ranges declared on
 * TestDataGenerator. The command refuses to delete anything outside those
 * ranges, so it cannot accidentally remove real CCP-allocated data even if
 * an admin runs it on a production install.
 *
 * Usage:
 *   php artisan structure-manager:cleanup-test-data
 *   php artisan structure-manager:cleanup-test-data --force   (skip prompt)
 *   php artisan structure-manager:cleanup-test-data --dry-run (show counts, delete nothing)
 */
class CleanupTestDataCommand extends Command
{
    protected $signature = 'structure-manager:cleanup-test-data
                            {--force : Skip the confirmation prompt}
                            {--dry-run : Show what would be deleted without actually deleting}';

    protected $description = 'Delete all test data created by the create-test-* and inject-test-notification commands (bounded by TestDataGenerator safe ranges).';

    public function handle(): int
    {
        $this->info('Structure Manager test data cleanup');
        $this->line('');

        // Phase 1: inventory — show the admin what's currently sitting in the
        // test ranges. If everything is zero, exit cheerfully without bothering
        // anyone.
        $inventory = TestDataGenerator::inventory();
        $mcInventory = $this->getManagerCoreInventory();

        $this->renderInventory($inventory, $mcInventory);

        $totalBefore = array_sum($inventory) + array_sum($mcInventory);
        if ($totalBefore === 0) {
            $this->info('No test data found. Nothing to clean up.');
            return 0;
        }

        // Phase 2: confirm — destructive operations should always be
        // double-checked unless the operator explicitly waives the prompt
        // (--force, useful in scripts and CI). --dry-run skips confirmation
        // because it never deletes anything.
        if (!$this->option('dry-run') && !$this->option('force')) {
            $this->line('');
            if (!$this->confirm('Proceed with deletion?', false)) {
                $this->warn('Cleanup cancelled.');
                return 1;
            }
        }

        // Phase 3: dry-run short-circuit. We've shown what's there, the admin
        // knows the impact, and we exit without touching the DB.
        if ($this->option('dry-run')) {
            $this->line('');
            $this->info('Dry run — no rows deleted. ' . $totalBefore . ' row(s) would have been removed.');
            return 0;
        }

        // Phase 4: actually delete. cleanupAll handles all the SM/SeAT tables;
        // we follow up with the MC-owned dedup table because cleanupAll
        // doesn't reach across plugin boundaries.
        $this->line('');
        $this->info('Cleaning up...');

        $counts = TestDataGenerator::cleanupAll();
        $mcCounts = $this->cleanupManagerCoreTables();

        $this->line('');
        $this->renderResults($counts, $mcCounts);

        return 0;
    }

    /**
     * Count test data rows in Manager Core's dedup table.
     *
     * Wrapped in a Schema::hasTable check because MC is optional. When MC is
     * absent, this returns zero counts (no error). When MC's schema is
     * present but its version pre-dates the dedup table, ditto.
     */
    protected function getManagerCoreInventory(): array
    {
        $inv = ['mc_esi_notifications' => 0];

        if (!Schema::hasTable('manager_core_esi_notifications')) {
            return $inv;
        }

        $inv['mc_esi_notifications'] = (int) DB::table('manager_core_esi_notifications')
            ->whereBetween('notification_id', [
                TestDataGenerator::NOTIFICATION_ID_MIN,
                TestDataGenerator::NOTIFICATION_ID_MAX,
            ])
            ->count();

        return $inv;
    }

    /**
     * Delete test rows from MC's dedup table. Same safe-range guard as the
     * SM-owned cleanup. Returns the row-count for the result table.
     */
    protected function cleanupManagerCoreTables(): array
    {
        $counts = ['mc_esi_notifications' => 0];

        if (!Schema::hasTable('manager_core_esi_notifications')) {
            return $counts;
        }

        $counts['mc_esi_notifications'] = (int) DB::table('manager_core_esi_notifications')
            ->whereBetween('notification_id', [
                TestDataGenerator::NOTIFICATION_ID_MIN,
                TestDataGenerator::NOTIFICATION_ID_MAX,
            ])
            ->delete();

        return $counts;
    }

    /**
     * Render the pre-cleanup inventory as a table. Two-column layout:
     * which table holds test data, how many rows are in there.
     */
    protected function renderInventory(array $inventory, array $mcInventory): void
    {
        $rows = [];
        foreach ($inventory as $key => $count) {
            $rows[] = [$this->humanize($key), number_format($count)];
        }
        foreach ($mcInventory as $key => $count) {
            $rows[] = [$this->humanize($key), number_format($count)];
        }

        $this->table(['Test data category', 'Rows'], $rows);

        $this->line('');
        $this->line('  Safe ID ranges (deletions bounded to these — real data cannot be touched):');
        $this->line('    Test corporations: '   . TestDataGenerator::CORP_ID_MIN . ' - ' . TestDataGenerator::CORP_ID_MAX);
        $this->line('    Test characters:   '   . TestDataGenerator::CHARACTER_ID_MIN . ' - ' . TestDataGenerator::CHARACTER_ID_MAX);
        $this->line('    Test structures:   '   . TestDataGenerator::STRUCTURE_ID_MIN . ' - ' . TestDataGenerator::STRUCTURE_ID_MAX);
        $this->line('    Test POSes:        '   . TestDataGenerator::POS_ID_MIN . ' - ' . TestDataGenerator::POS_ID_MAX);
        $this->line('    Test notifications: '  . TestDataGenerator::NOTIFICATION_ID_MIN . ' - ' . TestDataGenerator::NOTIFICATION_ID_MAX);
    }

    /**
     * Render the post-cleanup result. Mirrors the inventory layout but with
     * the actual delete counts that came back from the underlying queries.
     */
    protected function renderResults(array $counts, array $mcCounts): void
    {
        $rows = [];
        $total = 0;
        foreach ($counts as $key => $count) {
            $rows[] = [$this->humanize($key), number_format($count)];
            $total += $count;
        }
        foreach ($mcCounts as $key => $count) {
            $rows[] = [$this->humanize($key), number_format($count)];
            $total += $count;
        }

        $this->table(['Table', 'Rows deleted'], $rows);
        $this->info("Cleanup complete. Total rows deleted: " . number_format($total));
    }

    /**
     * Convert snake_case table keys to a Title Case description for the
     * table header rendering. Keeps the human-readable form in one place
     * rather than scattered across the inventory/results methods.
     */
    protected function humanize(string $key): string
    {
        return ucwords(str_replace('_', ' ', $key));
    }
}
