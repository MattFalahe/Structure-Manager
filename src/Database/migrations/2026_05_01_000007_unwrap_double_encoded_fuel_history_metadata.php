<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Structure Manager v2.0.2 — unwrap double-JSON-encoded metadata in
 * structure_fuel_history (and defensively in starbase_fuel_history).
 *
 * BACKGROUND
 * ----------
 *
 * The `StructureFuelHistory` model has `metadata` cast to `array`, which
 * means Laravel handles JSON-encoding on save / decoding on read.
 * Pre-v2.0.2, `TrackFuelConsumption::trackMetenoxFuel()` and
 * `trackStructureFuel()` were passing `json_encode($metadata)` into the
 * column instead of the raw array. The cast then encoded the already-
 * encoded string a second time, leaving the column with a JSON string
 * wrapping a JSON object, e.g.
 *
 *   "{\"tracking_method\":\"metenox_fuel_bay\",\"fuel_blocks\":18290,...}"
 *
 * The full background, including how it surfaced (Discord embeds reading
 * `0` for one of the Metenox fuel halves), is in the v2.0.2 squash commit
 * message + the inline comment on the metadata column write in
 * `TrackFuelConsumption`.
 *
 * Consequences while the column was in this state:
 *   - `JSON_EXTRACT(metadata, '$.fuel_blocks')` returned NULL in MariaDB
 *     because the column value is a string, not a JSON object.
 *   - Eloquent's `array` cast does one `json_decode` pass which returns
 *     a string (the inner JSON), not an array. Code like
 *     `$snapshot->metadata['fuel_blocks']` returns NULL. Downstream
 *     `FuelConsumptionTracker::analyzeFuelConsumption()` silently falls
 *     through to the `days_remaining` fallback for the affected rows.
 *
 * WHAT THIS MIGRATION DOES
 * ------------------------
 *
 * For every row where `metadata` starts with the double-encoded marker
 * `"{`, unwrap it via `JSON_UNQUOTE()` so the column ends up as a
 * proper JSON object the cast can decode normally on subsequent reads.
 *
 * Runs against both `structure_fuel_history` and `starbase_fuel_history`
 * — the POS path never had the json_encode() bug in current code, but
 * the migration is cheap and idempotent so we scan the POS table too in
 * case any historical version did.
 *
 * IDEMPOTENCY
 * -----------
 *
 * The WHERE clause filters on `metadata LIKE '"{%'` which only matches
 * the double-encoded shape. After unwrapping, the row no longer matches
 * and re-running the migration on the same data is a no-op. New writes
 * post-v2.0.2 are already arrays handled by the cast and never match
 * the filter.
 *
 * SAFETY
 * ------
 *
 *   - Only rewrites rows that match the double-encoded marker. Rows
 *     written by v2.0.2+ (correctly stored as JSON objects) start with
 *     `{` and are not touched.
 *   - `JSON_UNQUOTE()` is a no-op on a value that isn't a JSON string,
 *     so a misclassification (extremely unlikely given the LIKE pattern)
 *     would leave the row unchanged rather than corrupt it.
 *   - Each table guarded by `Schema::hasTable` so the migration is safe
 *     on fresh installs / partial schemas / cleaned-down environments.
 *   - Per-table try/catch so a failure on one table doesn't poison the
 *     other and doesn't fail the overall upgrade — the app code already
 *     tolerates the double-encoded shape (callers `json_decode` directly
 *     and the type-mismatch path returns gracefully), so the cleanup is
 *     quality-of-life rather than correctness-critical.
 *
 * NOT BOUNDED BY ROW CAP
 * ----------------------
 *
 * Unlike `2026_05_01_000006_cleanup_phantom_dual_stack_pairs.php` which
 * uses a 100k cap to keep complex multi-row pair-matching fast, this
 * migration runs a single `UPDATE ... WHERE LIKE` over each table.
 * `JSON_UNQUOTE` is cheap and the WHERE is an index-friendly prefix
 * pattern that mostly matches the leading-quote case. Six months of
 * history on a large install (1000+ structures) is comfortably under
 * a second.
 *
 * Forward-only. No `down()`. Released migrations are immutable.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['structure_fuel_history', 'starbase_fuel_history'] as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            if (!Schema::hasColumn($table, 'metadata')) {
                continue;
            }

            try {
                $affected = DB::update(
                    "UPDATE {$table}
                     SET metadata = JSON_UNQUOTE(metadata)
                     WHERE metadata LIKE ?",
                    ['"{%']
                );

                if ($affected > 0) {
                    Log::info(sprintf(
                        'Structure Manager v2.0.2: unwrapped %d double-encoded metadata row(s) in %s',
                        $affected,
                        $table
                    ));
                }
            } catch (\Throwable $e) {
                Log::warning(sprintf(
                    'Structure Manager v2.0.2: metadata cleanup on %s hit an error and was skipped: %s',
                    $table,
                    $e->getMessage()
                ));
            }
        }
    }

    public function down(): void
    {
        // Forward-only cleanup. Cannot meaningfully reverse — the original
        // double-encoded state was a bug and restoring it would be
        // anti-progress.
    }
};
