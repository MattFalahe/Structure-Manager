<?php

namespace StructureManager\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use StructureManager\Models\StructureFuelReserves;

/**
 * Detect + delete phantom mirror-pair rows in structure_fuel_reserves.
 *
 * BACKGROUND
 * ----------
 * v2.0.0 had a bug in trackStructureReserves + trackExternalReserves
 * where multiple physical stacks of the same fuel in the same CorpSAG
 * hangar would oscillate through the dedup logic each poll, producing
 * a mirror pair of rows per poll:
 *
 *   • Row +N (quantity_change = +N, is_refuel_event = false)
 *   • Row -N (quantity_change = -N, is_refuel_event = true)
 *
 * Both rows in the SAME (structure_id, fuel_type_id, location_flag)
 * tuple, written within seconds of each other (same poll iteration).
 * No physical fuel ever moved — the rows are an artifact of the
 * dedup-vs-multi-stack mismatch.
 *
 * The write-side fix (aggregate stacks by tuple, SUM quantities) ships
 * in the same release as this command, so no NEW phantom pairs are
 * generated. This command cleans up the historical pairs already in
 * the database.
 *
 * SIGNATURES
 * ----------
 * Two distinct phantom shapes are caught:
 *
 *   (A) Symmetric mirror pair — two rows in the same tuple with
 *       quantity_change values that are sign-flipped equal magnitudes,
 *       created within --window-seconds of each other. Both halves are
 *       deleted (both rows are equally phantom).
 *
 *   (B) First-poll phantom — the very first observation of a tuple has
 *       quantity_change=NULL and previous_quantity=NULL by definition.
 *       If the dual-stack bug fired on that same poll, a -N row appears
 *       paired against the NULL-change first-observation. The
 *       mathematical fingerprint is A.previous_quantity = B.reserve_quantity.
 *       Only the phantom -N row is deleted; the first-observation row
 *       is kept as legitimate audit history (its is_refuel_event=0 so
 *       it never appears in Fuel Withdrawals anyway).
 *
 * In BOTH signatures we additionally require:
 *   • Neither row's metadata mentions 'depletion_reconciliation'. Those
 *     rows have their own debounce now and a legitimate move-out should
 *     never be wiped just because someone happens to refuel within
 *     5 minutes. LIKE-based match used to survive legacy double-encoded
 *     metadata where JSON_EXTRACT returns NULL.
 *
 * SAFETY
 * ------
 *   • Default mode is dry-run. Pass --force to actually delete.
 *   • Both rows of each pair are deleted (the +N and the -N).
 *   • Optional --corp scope so a single corp can be cleaned without
 *     touching others on a multi-corp install.
 *   • Optional --days bounds the scan window (default 90).
 */
class CleanupPhantomWithdrawalsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'structure-manager:cleanup-phantom-withdrawals
                            {--corp= : Limit to a single corporation ID (default: all corps)}
                            {--days=90 : How far back to scan (default: 90 days)}
                            {--window-seconds=300 : Two rows pair as phantom if created within this many seconds of each other (default: 300 = 5 minutes — same-poll pairs are typically within 1-3 seconds)}
                            {--force : Actually delete the phantom rows (default: dry-run only — shows what would be deleted)}';

    /**
     * @var string
     */
    protected $description = 'Detect + delete phantom mirror-pair rows (+N / -N within the same tuple and time window) caused by the pre-v2.0.0 dual-stack-not-aggregated bug. Default is dry-run; pass --force to actually delete.';

    public function handle(): int
    {
        $corpId = $this->option('corp') !== null ? (int) $this->option('corp') : null;
        $days = max(1, (int) $this->option('days'));
        $windowSeconds = max(1, (int) $this->option('window-seconds'));
        $force = (bool) $this->option('force');

        $cutoff = Carbon::now()->subDays($days);

        $this->info('--------------------------------------------------------');
        $this->info('Structure Manager — Phantom Mirror-Pair Cleanup');
        $this->info('--------------------------------------------------------');
        $this->line("  Corp scope:     " . ($corpId !== null ? "corp_id = {$corpId}" : 'all corps'));
        $this->line("  Time window:    last {$days} days (since {$cutoff->toDateTimeString()})");
        $this->line("  Pair window:    {$windowSeconds} seconds (rows created within this window of each other)");
        $this->line("  Mode:           " . ($force ? 'DELETE' : 'DRY-RUN (no rows will be deleted — pass --force to delete)'));
        $this->line('');

        // ========================================================
        // STEP 1: SIGNATURE A — symmetric mirror pairs
        // ========================================================
        // Two rows with mirror-sign deltas of equal magnitude in the
        // same tuple within `windowSeconds`. The `a.id < b.id` predicate
        // dedupes each pair to a single row (otherwise each pair would
        // appear twice with a/b swapped). Both rows of each pair are
        // dual-stack phantoms and BOTH get deleted.
        //
        // Metadata exclusion uses LIKE rather than JSON_EXTRACT because
        // legacy rows (pre-v2.0.0-metadata-fix) are double-JSON-encoded,
        // so JSON_EXTRACT(metadata, '$.tracking_method') returns NULL on
        // them. A LIKE on the raw string survives the encoding form.
        $mirrorQuery = DB::table('structure_fuel_reserves AS a')
            ->join('structure_fuel_reserves AS b', function ($join) use ($windowSeconds) {
                $join->on('a.structure_id', '=', 'b.structure_id')
                     ->on('a.fuel_type_id', '=', 'b.fuel_type_id')
                     ->on('a.location_flag', '=', 'b.location_flag')
                     ->on('a.id', '<', 'b.id')
                     ->whereRaw('a.quantity_change = -b.quantity_change')
                     ->whereRaw('a.quantity_change != 0')
                     ->whereRaw('ABS(TIMESTAMPDIFF(SECOND, a.created_at, b.created_at)) <= ?', [$windowSeconds]);
            })
            ->where('a.created_at', '>=', $cutoff)
            ->where('b.created_at', '>=', $cutoff)
            ->where(function ($q) {
                $q->whereNull('a.metadata')
                  ->orWhere('a.metadata', 'NOT LIKE', '%depletion_reconciliation%');
            })
            ->where(function ($q) {
                $q->whereNull('b.metadata')
                  ->orWhere('b.metadata', 'NOT LIKE', '%depletion_reconciliation%');
            })
            ->select(
                'a.id AS id_a',
                'a.quantity_change AS delta_a',
                'a.created_at AS at_a',
                'b.id AS id_b',
                'b.quantity_change AS delta_b',
                'b.created_at AS at_b',
                'a.corporation_id',
                'a.structure_id',
                'a.fuel_type_id',
                'a.location_flag',
                'a.location_name'
            );

        if ($corpId !== null) {
            $mirrorQuery->where('a.corporation_id', $corpId);
        }

        $mirrorPairs = $mirrorQuery->orderBy('a.created_at')->get();

        // ========================================================
        // STEP 2: SIGNATURE B — first-poll asymmetric phantoms
        // ========================================================
        // Edge case for tuples whose VERY FIRST observation coincided
        // with the dual-stack bug:
        //
        //   Row B (first observation): qty=X, prev=NULL, change=NULL
        //     (no lastReserve existed, so change column is NULL)
        //   Row A (phantom withdrawal): qty=Y, prev=X, change=-(X-Y)
        //     (next stack iterated → compared against row B → spurious −Δ)
        //
        // The mathematical fingerprint: A.previous_quantity = B.reserve_quantity
        // and B has both quantity_change AND previous_quantity NULL. Created
        // in the same poll, so same timestamp within `windowSeconds`.
        //
        // Cleanup difference vs Signature A: we delete A (the phantom)
        // but KEEP B (the legitimate "first observation of this tuple"
        // audit row — its is_refuel_event is 0 anyway so it never
        // appears in Fuel Withdrawals).
        $firstPollQuery = DB::table('structure_fuel_reserves AS a')
            ->join('structure_fuel_reserves AS b', function ($join) use ($windowSeconds) {
                $join->on('a.structure_id', '=', 'b.structure_id')
                     ->on('a.fuel_type_id', '=', 'b.fuel_type_id')
                     ->on('a.location_flag', '=', 'b.location_flag')
                     ->whereColumn('a.id', '!=', 'b.id')
                     ->whereNull('b.quantity_change')
                     ->whereNull('b.previous_quantity')
                     ->whereColumn('a.previous_quantity', '=', 'b.reserve_quantity')
                     ->whereRaw('a.quantity_change < 0')
                     ->whereRaw('ABS(TIMESTAMPDIFF(SECOND, a.created_at, b.created_at)) <= ?', [$windowSeconds]);
            })
            ->where('a.created_at', '>=', $cutoff)
            ->where('b.created_at', '>=', $cutoff)
            ->where(function ($q) {
                $q->whereNull('a.metadata')
                  ->orWhere('a.metadata', 'NOT LIKE', '%depletion_reconciliation%');
            })
            ->select(
                'a.id AS phantom_id',
                'a.quantity_change AS phantom_delta',
                'a.created_at AS phantom_at',
                'b.id AS first_obs_id',
                'b.reserve_quantity AS first_obs_qty',
                'b.created_at AS first_obs_at',
                'a.corporation_id',
                'a.structure_id',
                'a.fuel_type_id',
                'a.location_flag',
                'a.location_name'
            );

        if ($corpId !== null) {
            $firstPollQuery->where('a.corporation_id', $corpId);
        }

        $firstPollPhantoms = $firstPollQuery->orderBy('a.created_at')->get();

        // ========================================================
        // STEP 3: short-circuit if neither found anything
        // ========================================================
        if ($mirrorPairs->isEmpty() && $firstPollPhantoms->isEmpty()) {
            $this->info('No phantom rows detected in scope. Nothing to do.');
            return 0;
        }

        // ========================================================
        // STEP 4: report Signature A (mirror pairs)
        // ========================================================
        if ($mirrorPairs->isNotEmpty()) {
            $this->warn(sprintf(
                '[Mirror pairs] Detected %d phantom +N / -N pair(s) (both halves deleted per pair).',
                $mirrorPairs->count()
            ));
            $this->line('');

            $sample = $mirrorPairs->take(10);
            $this->table(
                ['Pair IDs', 'Corp', 'Structure', 'Fuel', 'Hangar', 'Δ', 'Window', 'When'],
                $sample->map(function ($p) {
                    $deltaSec = (int) abs(Carbon::parse($p->at_a)->diffInSeconds(Carbon::parse($p->at_b)));
                    return [
                        "{$p->id_a}/{$p->id_b}",
                        $p->corporation_id,
                        $p->location_name ?: $p->structure_id,
                        $p->fuel_type_id,
                        $p->location_flag,
                        sprintf('±%s', number_format(abs($p->delta_a))),
                        "{$deltaSec}s",
                        Carbon::parse($p->at_a)->toDateTimeString(),
                    ];
                })->all()
            );

            if ($mirrorPairs->count() > $sample->count()) {
                $this->line(sprintf('  ... and %d more', $mirrorPairs->count() - $sample->count()));
            }
            $this->line('');
        }

        // ========================================================
        // STEP 5: report Signature B (first-poll phantoms)
        // ========================================================
        if ($firstPollPhantoms->isNotEmpty()) {
            $this->warn(sprintf(
                '[First-poll phantoms] Detected %d phantom -N row(s) whose mirror is the first-ever observation of the tuple (only the phantom is deleted; the first-observation row is kept as legitimate audit data).',
                $firstPollPhantoms->count()
            ));
            $this->line('');

            $sample = $firstPollPhantoms->take(10);
            $this->table(
                ['Phantom ID', 'Keep ID', 'Corp', 'Structure', 'Fuel', 'Hangar', 'Δ', 'When'],
                $sample->map(function ($p) {
                    return [
                        $p->phantom_id,
                        $p->first_obs_id,
                        $p->corporation_id,
                        $p->location_name ?: $p->structure_id,
                        $p->fuel_type_id,
                        $p->location_flag,
                        sprintf('%s', number_format($p->phantom_delta)),
                        Carbon::parse($p->phantom_at)->toDateTimeString(),
                    ];
                })->all()
            );

            if ($firstPollPhantoms->count() > $sample->count()) {
                $this->line(sprintf('  ... and %d more', $firstPollPhantoms->count() - $sample->count()));
            }
            $this->line('');
        }

        // ========================================================
        // STEP 6: tally + dispatch
        // ========================================================
        $mirrorRowsToDelete = $mirrorPairs->count() * 2;
        $firstPollRowsToDelete = $firstPollPhantoms->count();
        $totalRows = $mirrorRowsToDelete + $firstPollRowsToDelete;

        $this->info(sprintf(
            'Total rows that would be deleted: %d (%d from mirror pairs × 2 + %d first-poll phantoms × 1)',
            $totalRows,
            $mirrorPairs->count(),
            $firstPollPhantoms->count()
        ));
        $this->line('');

        if (!$force) {
            $this->info('DRY-RUN complete. To actually delete these rows, re-run with --force:');
            $this->line('');
            $cmd = '  php artisan structure-manager:cleanup-phantom-withdrawals';
            if ($corpId !== null) $cmd .= " --corp={$corpId}";
            $cmd .= " --days={$days} --window-seconds={$windowSeconds} --force";
            $this->line($cmd);
            return 0;
        }

        // STEP 7: actually delete.
        $allIds = [];
        foreach ($mirrorPairs as $p) {
            $allIds[] = $p->id_a;
            $allIds[] = $p->id_b;
        }
        foreach ($firstPollPhantoms as $p) {
            // Only the phantom row — keep the first-observation row as
            // legitimate audit history.
            $allIds[] = $p->phantom_id;
        }

        $deleted = 0;
        foreach (array_chunk(array_unique($allIds), 500) as $chunk) {
            $deleted += StructureFuelReserves::whereIn('id', $chunk)->delete();
        }

        $this->info(sprintf(
            'Deleted %d phantom row(s): %d from mirror pairs + %d first-poll phantoms.',
            $deleted,
            $mirrorRowsToDelete,
            $firstPollRowsToDelete
        ));
        return 0;
    }
}
