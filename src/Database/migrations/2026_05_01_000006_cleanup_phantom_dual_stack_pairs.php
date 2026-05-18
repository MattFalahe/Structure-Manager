<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Structure Manager v2.0.0 — automated phantom-pair cleanup for upgrades.
 *
 * v2.0.0 fixes a dedup bug in TrackFuelConsumption's reserve trackers:
 * pre-fix, multiple physical stacks of the same fuel in the same CorpSAG
 * hangar would oscillate through the per-tuple lastReserve comparison,
 * writing a mirror pair of rows (+N / -N) into structure_fuel_reserves
 * every poll even though nothing physically moved. The aggregation fix
 * (group stacks by tuple, SUM before dedup) prevents NEW phantom pairs
 * from being created.
 *
 * Operators upgrading from v1.x can have hundreds-to-thousands of
 * historical phantom rows polluting the Fuel Withdrawals UI. The
 * structure-manager:cleanup-phantom-withdrawals artisan command exists
 * for manual cleanup, but most operators won't read the upgrade notes
 * carefully enough to know they need to run it. This migration applies
 * the same cleanup automatically during the upgrade so the UI is clean
 * on first load.
 *
 * SIGNATURES CLEANED
 * ------------------
 *
 *   (A) Symmetric mirror pairs — two rows in the same
 *       (structure_id, fuel_type_id, location_flag) tuple with
 *       quantity_change values that are sign-flipped equal magnitudes,
 *       created within 300 seconds of each other. Both halves deleted.
 *
 *   (B) First-poll phantoms — a -N withdrawal row whose mirror is the
 *       VERY FIRST observation of the tuple (quantity_change=NULL,
 *       previous_quantity=NULL). Paired by the mathematical fingerprint
 *       a.previous_quantity = b.reserve_quantity. Only the phantom -N
 *       row is deleted; the legitimate "first observation" row is kept
 *       as audit history (is_refuel_event=0, never appears in the UI
 *       anyway).
 *
 * Depletion-reconciliation rows are explicitly excluded from BOTH
 * signatures (LIKE-based metadata check survives the legacy double-
 * JSON-encoded form). The reconciliation pass has its own debounce now;
 * a legitimate move-out + same-day refuel must never be wiped.
 *
 * SAFETY CAPS
 * -----------
 *
 *   • Time window: last 365 days. Older rows aren't visible in any
 *     default UI window so wiping them adds no value, and large
 *     installs with multi-year history don't pay the scan cost.
 *
 *   • Per-signature row cap: 100,000. Bounded delete keeps the migration
 *     fast (<5s typical) even on the largest installs. Operators with
 *     pathological backlogs can re-run the artisan command afterwards
 *     to mop up.
 *
 * IDEMPOTENCY
 * -----------
 * Both queries are deterministic SELECTs. Re-running this migration on
 * an already-cleaned install returns empty result sets — no-op.
 *
 * Forward-only: no down() implementation. The deleted rows were always
 * semantically incorrect; restoring them would be a regression.
 *
 * Filename → class name match (Laravel migration resolver requirement):
 *   2026_05_01_000006_cleanup_phantom_dual_stack_pairs
 *   → CleanupPhantomDualStackPairs
 */
class CleanupPhantomDualStackPairs extends Migration
{
    private const WINDOW_SECONDS  = 300;
    private const TIME_WINDOW_DAYS = 365;
    private const MAX_ROWS_PER_SIGNATURE = 100000;

    public function up(): void
    {
        if (!Schema::hasTable('structure_fuel_reserves')) {
            Log::info('[SM 2026_05_01_000006] structure_fuel_reserves table missing — fresh install or pre-v1 schema. Skipping cleanup.');
            return;
        }

        $cutoff = now()->subDays(self::TIME_WINDOW_DAYS);

        // ============================================================
        // STEP 1: Signature A — symmetric mirror pairs
        // ============================================================
        $mirrorPairs = DB::table('structure_fuel_reserves AS a')
            ->join('structure_fuel_reserves AS b', function ($join) {
                $join->on('a.structure_id', '=', 'b.structure_id')
                     ->on('a.fuel_type_id', '=', 'b.fuel_type_id')
                     ->on('a.location_flag', '=', 'b.location_flag')
                     ->on('a.id', '<', 'b.id')
                     ->whereRaw('a.quantity_change = -b.quantity_change')
                     ->whereRaw('a.quantity_change != 0')
                     ->whereRaw('ABS(TIMESTAMPDIFF(SECOND, a.created_at, b.created_at)) <= ?', [self::WINDOW_SECONDS]);
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
            ->select('a.id AS id_a', 'b.id AS id_b')
            ->limit(self::MAX_ROWS_PER_SIGNATURE)
            ->get();

        // ============================================================
        // STEP 2: Signature B — first-poll phantoms
        // ============================================================
        $firstPollPhantoms = DB::table('structure_fuel_reserves AS a')
            ->join('structure_fuel_reserves AS b', function ($join) {
                $join->on('a.structure_id', '=', 'b.structure_id')
                     ->on('a.fuel_type_id', '=', 'b.fuel_type_id')
                     ->on('a.location_flag', '=', 'b.location_flag')
                     ->whereColumn('a.id', '!=', 'b.id')
                     ->whereNull('b.quantity_change')
                     ->whereNull('b.previous_quantity')
                     ->whereColumn('a.previous_quantity', '=', 'b.reserve_quantity')
                     ->whereRaw('a.quantity_change < 0')
                     ->whereRaw('ABS(TIMESTAMPDIFF(SECOND, a.created_at, b.created_at)) <= ?', [self::WINDOW_SECONDS]);
            })
            ->where('a.created_at', '>=', $cutoff)
            ->where('b.created_at', '>=', $cutoff)
            ->where(function ($q) {
                $q->whereNull('a.metadata')
                  ->orWhere('a.metadata', 'NOT LIKE', '%depletion_reconciliation%');
            })
            ->select('a.id AS phantom_id')
            ->limit(self::MAX_ROWS_PER_SIGNATURE)
            ->get();

        if ($mirrorPairs->isEmpty() && $firstPollPhantoms->isEmpty()) {
            Log::info('[SM 2026_05_01_000006] No phantom dual-stack rows detected — install is clean.');
            return;
        }

        // ============================================================
        // STEP 3: collect ids and delete
        // ============================================================
        $mirrorIds = [];
        foreach ($mirrorPairs as $p) {
            $mirrorIds[] = $p->id_a;
            $mirrorIds[] = $p->id_b;
        }
        $firstPollIds = $firstPollPhantoms->pluck('phantom_id')->all();

        $allIds = array_unique(array_merge($mirrorIds, $firstPollIds));

        $deleted = 0;
        foreach (array_chunk($allIds, 500) as $chunk) {
            $deleted += DB::table('structure_fuel_reserves')->whereIn('id', $chunk)->delete();
        }

        Log::info(sprintf(
            '[SM 2026_05_01_000006] Phantom dual-stack cleanup on upgrade: deleted %d row(s) — %d from mirror pairs (%d pairs × 2) + %d first-poll phantoms. Run structure-manager:cleanup-phantom-withdrawals for further cleanup if needed.',
            $deleted,
            $mirrorPairs->count() * 2,
            $mirrorPairs->count(),
            $firstPollPhantoms->count()
        ));

        // Hard-cap warning so operators with huge backlogs know they need
        // to re-run the manual command.
        if ($mirrorPairs->count() >= self::MAX_ROWS_PER_SIGNATURE
            || $firstPollPhantoms->count() >= self::MAX_ROWS_PER_SIGNATURE) {
            Log::warning(sprintf(
                '[SM 2026_05_01_000006] Hit per-signature row cap of %d. There may be additional phantom rows beyond this batch. Run: php artisan structure-manager:cleanup-phantom-withdrawals --days=365 --force',
                self::MAX_ROWS_PER_SIGNATURE
            ));
        }
    }

    public function down(): void
    {
        // Forward-only — see file docblock for rationale.
    }
}
