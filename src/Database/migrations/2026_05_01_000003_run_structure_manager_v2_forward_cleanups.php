<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Structure Manager v2.0.0 — forward-only data cleanups.
 *
 * Replaces the granular incremental cleanup migrations that landed during
 * dev-4.0 development. Both are forward-only data operations that fix
 * semantic-mismatch rows from earlier code paths.
 *
 * Cleanups performed:
 *
 *   1. Remove legacy POS strontium board rows
 *
 *      Older NotifyPosLowFuel created Structure Board entries with
 *      source_reference='pos-strontium:{starbase_id}' classified as
 *      fuel_warning / fuel_critical. Semantic mismatch — POS strontium is
 *      a defensive reagent (extends reinforcement), not a fuel resource
 *      whose depletion takes the structure offline. The 'fuel' event
 *      group is reserved for Upwell fuel, POS fuel, and POS charters.
 *
 *      Webhook notifications for low strontium continue to fire normally
 *      (different code path); only the misleading board entry is removed.
 *
 *   2. Dismiss stale under_attack mislabeled rows
 *
 *      Pre-v2.0.0 mapping: StructureUnderAttack notifications mapped to
 *      event_type='reinforce_shield' on the Structure Board. Wrong —
 *      StructureUnderAttack just means "being shot RIGHT NOW", may
 *      auto-repair in 15 min. Post-fix: maps to event_type='under_attack'
 *      with eve_time = notification_timestamp + 15 min and auto-dismiss
 *      on elapse.
 *
 *      Operators upgrading from dev-4.0 test installs may have stale rows
 *      with the old mislabeling. This step dismisses them so the board
 *      reflects the new semantics from day one.
 *
 * Forward-only: no down() implementation. The rows being cleaned up were
 * always semantically incorrect; restoring them would be a regression,
 * not a rollback.
 *
 * Idempotency: both operations use WHERE clauses that won't match anything
 * on subsequent runs (rows are deleted / dismissed, so re-running is a
 * no-op on already-cleaned data).
 *
 * For fresh v2.0.0 installs: both operations are no-ops (the buggy rows
 * never existed because the broken code paths never ran).
 *
 * Filename and class name match Laravel's filename → StudlyCase derivation
 * (`run_structure_manager_v2_forward_cleanups` →
 * `RunStructureManagerV2ForwardCleanups`) so Laravel's Migrator resolves
 * the class by name without workarounds.
 */
class RunStructureManagerV2ForwardCleanups extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('structure_manager_timers')) {
            Log::warning('[Structure Manager] v2 cleanup migration: timers table missing — run 2026_05_01_000001_create_core_schema first. Skipping cleanups.');
            return;
        }

        // ============================================================
        // 1. Remove legacy POS strontium board rows
        // ============================================================
        // Identified by source_reference prefix 'pos-strontium:'. Hard delete
        // (not dismiss) because these rows were always semantically wrong
        // and shouldn't appear even in dismissed-row history.
        $strontiumDeleted = DB::table('structure_manager_timers')
            ->where('source_reference', 'like', 'pos-strontium:%')
            ->delete();

        if ($strontiumDeleted > 0) {
            Log::info(sprintf(
                '[Structure Manager] v2 cleanup: removed %d legacy pos-strontium board row(s)',
                $strontiumDeleted
            ));
        }

        // ============================================================
        // 2. Dismiss stale under_attack mislabeled rows
        // ============================================================
        // Pre-v2.0.0 mapping created event_type='reinforce_shield' from
        // StructureUnderAttack notifications with source='auto_reinforce'.
        // Soft-dismiss (set dismissed_at) so the rows stay in the table for
        // audit but leave the active board view. Standard retention prune
        // will permanently delete them after retentionDays days.
        $now = now();
        $underAttackDismissed = DB::table('structure_manager_timers')
            ->where('event_type', 'reinforce_shield')
            ->where('source', 'auto_reinforce')
            ->whereNull('dismissed_at')
            ->where('eve_time', '<', $now)
            ->update([
                'dismissed_at' => $now,
                'updated_at'   => $now,
            ]);

        if ($underAttackDismissed > 0) {
            Log::info(sprintf(
                '[Structure Manager] v2 cleanup: dismissed %d stale under-attack timer row(s) that were mislabeled as reinforce_shield under the pre-v2.0.0 mapping.',
                $underAttackDismissed
            ));
        }
    }

    public function down(): void
    {
        // Forward-only — see file docblock for rationale.
    }
}
