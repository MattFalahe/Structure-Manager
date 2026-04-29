<?php

namespace StructureManager\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use StructureManager\Models\StructureDisappearanceTracking;

/**
 * Polls corporation_structures every 10 minutes and tracks Upwell-structure
 * presence over time. Drives the MEDIUM-confidence path of destruction
 * detection — rows that vanish from corporation_structures get classified
 * as destroyed / likely_transferred / bulk_vanished based on their last
 * known state and what happened to their corp's other structures.
 *
 * The HIGH-confidence path (CCP StructureDestroyed notification arriving via
 * SeAT's native or MC's fast-poll) lives in StructureEventHandler and fires
 * before this job ever runs — when both signals fire, the handler wins
 * (this job sees status='destroyed' on the tracking row and skips
 * republishing).
 *
 * Standalone — does NOT depend on Manager Core. SM owns this entirely.
 *
 * See `project_structure_manager_destruction_detection.md` for the full design.
 */
class TrackStructurePresence implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * MUST be < SeAT queue retry_after (960s). 600s gives plenty of headroom
     * for multi-corp installs polling thousands of rows.
     */
    public $timeout = 600;
    public $tries = 2;
    public $backoff = [60, 300];

    public function handle(): void
    {
        $now = Carbon::now();
        Log::info('TrackStructurePresence: starting presence sync');

        // ============================================================
        // Step 1: snapshot what's currently in corporation_structures
        // ============================================================
        $present = DB::table('corporation_structures as cs')
            ->leftJoin('universe_structures as us', 'cs.structure_id', '=', 'us.structure_id')
            ->leftJoin('mapDenormalize as md', 'cs.system_id', '=', 'md.itemID')
            ->select(
                'cs.structure_id',
                'cs.corporation_id',
                'cs.type_id',
                'cs.system_id',
                'cs.state',
                'cs.fuel_expires',
                'us.name as structure_name',
                'md.itemName as system_name',
                'md.security as system_security'
            )
            ->get()
            ->keyBy('structure_id');

        $presentIds = $present->keys()->all();

        // Per-corp present counts — used to detect bulk vanishes (token loss / corp disbanded)
        $corpPresentCounts = $present->groupBy('corporation_id')->map->count()->all();

        Log::info("TrackStructurePresence: {$present->count()} structures present across " . count($corpPresentCounts) . ' corps');

        // ============================================================
        // Step 2: upsert tracking rows for present structures
        //         (refresh last_seen + reset miss counter + check for reappearance)
        // ============================================================
        $upsertedReappearances = 0;
        $hullReinforcedFired   = 0;
        foreach ($present as $structureId => $row) {
            $existing = StructureDisappearanceTracking::where('structure_id', $structureId)->first();

            // If this row was previously marked as gone (destroyed / likely_transferred /
            // bulk_vanished) but reappeared within the grace window, flip back to watching.
            $isResetFromGone = false;
            if ($existing
                && $existing->status !== 'watching'
                && $existing->last_seen_at
                && $existing->last_seen_at->diffInHours($now) <= StructureDisappearanceTracking::REAPPEARANCE_GRACE_HOURS
            ) {
                $isResetFromGone = true;
                Log::info("TrackStructurePresence: structure {$structureId} reappeared within grace window (was '{$existing->status}'), resetting to watching");
            }

            // Detect state transition INTO 'hull_reinforce' BEFORE the upsert
            // overwrites last_known_state. This is the primary signal source for
            // hull_reinforced events because CCP doesn't reliably send a separate
            // notification when armor reinforce ends and hull begins (some installs
            // see StructureLostArmor with state=hull_reinforce in the YAML, but
            // it's not consistent — polling state column is the authoritative source).
            // Naturally idempotent: once stored as hull_reinforce, subsequent polls
            // see "current == previous" and skip.
            if ($existing
                && $existing->last_known_state !== 'hull_reinforce'
                && $row->state === 'hull_reinforce'
            ) {
                $this->publishHullReinforcedEvent($row);
                $hullReinforcedFired++;
            }

            StructureDisappearanceTracking::updateOrCreate(
                ['structure_id' => $structureId],
                [
                    'last_seen_at'                => $now,
                    'last_known_state'            => $row->state,
                    'last_known_fuel_expires'     => $row->fuel_expires,
                    'last_known_corporation_id'   => $row->corporation_id,
                    'last_known_type_id'          => $row->type_id,
                    'last_known_structure_name'   => $row->structure_name,
                    'last_known_system_id'        => $row->system_id,
                    'last_known_system_name'      => $row->system_name,
                    'last_known_system_security'  => $row->system_security,
                    'consecutive_misses'          => 0,
                    'status'                      => $isResetFromGone ? 'watching' : 'watching',
                    'detection_source'            => $isResetFromGone ? null : null,
                    'resolved_at'                 => $isResetFromGone ? null : null,
                ]
            );

            if ($isResetFromGone) {
                $upsertedReappearances++;
            }
        }

        // ============================================================
        // Step 3: find tracked-but-now-missing rows, classify them
        // ============================================================
        // The .watching() scope (status='watching') is what dedupes against the
        // notification-driven destruction path in StructureEventHandler. When
        // CCP's StructureDestroyed/SkyhookDestroyed notification arrives via
        // ESI fast-poll, StructureEventHandler latches the tracking row to
        // status='destroyed' BEFORE this job runs again — so the row falls
        // out of the watching() filter and the grace-period path can't fire
        // a duplicate structure.alert.destroyed event for the same loss.
        // DO NOT remove this filter without a replacement dedup mechanism
        // (e.g. checking status != 'destroyed' inline) or the high-confidence
        // notification path and the medium-confidence grace-period path will
        // double-publish for any loss CCP delivers a notification for.
        $missing = StructureDisappearanceTracking::watching()
            ->whereNotIn('structure_id', $presentIds)
            ->get();

        $classified = [
            'destroyed'           => 0,
            'likely_transferred'  => 0,
            'bulk_vanished'       => 0,
            'still_watching'      => 0,
        ];

        // Pre-compute corps that should be flagged "bulk vanished":
        // a corp's tracking rows ALL went missing in this poll AND none of its
        // structures are present. This catches token loss / corp disbanded /
        // CEO left without firing destroyed events for every structure.
        $missingByCorp = $missing->groupBy('last_known_corporation_id');
        $bulkVanishedCorpIds = [];
        foreach ($missingByCorp as $corpId => $missingRows) {
            $corpId = (int) $corpId;
            // Bulk = corp had multiple structures tracked AND zero present in this poll
            $missingCount = $missingRows->count();
            $presentCount = $corpPresentCounts[$corpId] ?? 0;
            if ($missingCount >= 2 && $presentCount === 0) {
                $bulkVanishedCorpIds[$corpId] = true;
                Log::warning("TrackStructurePresence: corp {$corpId} has {$missingCount} structures missing AND zero present — flagging as bulk_vanished (likely token loss or corp disbanded)");
            }
        }

        foreach ($missing as $tracking) {
            $tracking->consecutive_misses++;

            if ($tracking->consecutive_misses < StructureDisappearanceTracking::MISS_THRESHOLD) {
                // Not yet at threshold — keep watching
                $tracking->save();
                $classified['still_watching']++;
                continue;
            }

            // Threshold reached — classify
            if (isset($bulkVanishedCorpIds[(int) $tracking->last_known_corporation_id])) {
                $tracking->status = 'bulk_vanished';
                $tracking->detection_source = 'grace_period';
                $tracking->resolved_at = $now;
                $classified['bulk_vanished']++;
            } elseif ($tracking->was_in_combat) {
                $tracking->status = 'destroyed';
                $tracking->detection_source = 'grace_period';
                $tracking->resolved_at = $now;
                $classified['destroyed']++;

                // Publish structure.alert.destroyed via MC EventBus (no-op if MC absent)
                $this->publishDestroyedEvent($tracking, 'grace_period');
            } else {
                $tracking->status = 'likely_transferred';
                $tracking->detection_source = 'grace_period';
                $tracking->resolved_at = $now;
                $classified['likely_transferred']++;
            }

            $tracking->save();
        }

        Log::info('TrackStructurePresence: complete — ' . json_encode([
            'present'             => count($presentIds),
            'reappeared'          => $upsertedReappearances,
            'hull_reinforced'     => $hullReinforcedFired,
            'destroyed_fired'     => $classified['destroyed'],
            'likely_transferred'  => $classified['likely_transferred'],
            'bulk_vanished'       => $classified['bulk_vanished'],
            'still_watching'      => $classified['still_watching'],
        ]));
    }

    /**
     * Publish structure.alert.hull_reinforced when a structure transitions INTO
     * the 'hull_reinforce' state. This is the polling-driven path — CCP doesn't
     * reliably send a separate notification for hull reinforce, so we watch
     * corporation_structures.state directly.
     *
     * timer_ends_at comes from corporation_structures.state_timer_end which is
     * authoritative for hull-timer expiration.
     *
     * No-op when Manager Core is not installed.
     *
     * @param object $row corporation_structures row joined with universe_structures + mapDenormalize
     */
    private function publishHullReinforcedEvent($row): void
    {
        if (!class_exists('\\ManagerCore\\Services\\EventBus')) {
            return;
        }

        // Pull state_timer_end from corporation_structures specifically — the
        // join in handle() doesn't include it (we only need it on transitions).
        $timerEnd = DB::table('corporation_structures')
            ->where('structure_id', $row->structure_id)
            ->value('state_timer_end');

        $payload = [
            'structure_id'      => (int) $row->structure_id,
            'corporation_id'    => (int) $row->corporation_id,
            'type_id'           => $row->type_id ? (int) $row->type_id : null,
            'structure_name'    => $row->structure_name,
            'system_id'         => $row->system_id ? (int) $row->system_id : null,
            'system_name'       => $row->system_name,
            'system_security'   => $row->system_security !== null ? (float) $row->system_security : null,
            'timer_ends_at'     => $timerEnd ? Carbon::parse($timerEnd)->toIso8601String() : null,
            'attacker_summary'  => null, // not available from state polling — use shield/armor_reinforced events for attacker info
            'attacker_corp'     => null,
            'attacker_alliance' => null,
            'severity'          => 'critical',
            'notification_id'   => null,
            'notification_type' => null,
            'detection_source'  => 'state_poll',
        ];

        try {
            app(\ManagerCore\Services\EventBus::class)->publish(
                'structure.alert.hull_reinforced',
                'structure-manager',
                $payload
            );
            Log::info("TrackStructurePresence: published structure.alert.hull_reinforced for structure {$row->structure_id} (state transition to hull_reinforce)");
        } catch (\Throwable $e) {
            Log::warning("TrackStructurePresence: failed to publish structure.alert.hull_reinforced for structure {$row->structure_id}: " . $e->getMessage());
        }
    }

    /**
     * Publish structure.alert.destroyed for a structure classified as destroyed
     * via the grace-period path. Payload matches the contract documented in
     * project_structure_manager_destruction_detection.md (lines 148-167).
     *
     * No-op when Manager Core is not installed.
     */
    private function publishDestroyedEvent(StructureDisappearanceTracking $tracking, string $detectionSource): void
    {
        if (!class_exists('\\ManagerCore\\Services\\EventBus')) {
            return;
        }

        $finalTimerResult = $this->inferFinalTimerResult($tracking);

        $payload = [
            'structure_id'       => (int) $tracking->structure_id,
            'corporation_id'     => (int) $tracking->last_known_corporation_id,
            'type_id'            => $tracking->last_known_type_id ? (int) $tracking->last_known_type_id : null,
            'structure_name'     => $tracking->last_known_structure_name,
            'system_id'          => $tracking->last_known_system_id ? (int) $tracking->last_known_system_id : null,
            'system_name'        => $tracking->last_known_system_name,
            'system_security'    => $tracking->last_known_system_security !== null
                ? (float) $tracking->last_known_system_security
                : null,
            // Best-effort timestamp: midpoint between last sighting and now
            'destroyed_at'       => $tracking->last_seen_at
                ? $tracking->last_seen_at->copy()->addMinutes(15)->toIso8601String()
                : Carbon::now()->toIso8601String(),
            'detection_source'   => $detectionSource,
            'killmail_url'       => null,
            'final_timer_result' => $finalTimerResult,
            'severity'           => 'critical',
        ];

        try {
            app(\ManagerCore\Services\EventBus::class)->publish(
                'structure.alert.destroyed',
                'structure-manager',
                $payload
            );
            Log::info("TrackStructurePresence: published structure.alert.destroyed for structure {$tracking->structure_id} (detection={$detectionSource}, last_state={$tracking->last_known_state})");
        } catch (\Throwable $e) {
            Log::warning("TrackStructurePresence: failed to publish structure.alert.destroyed for structure {$tracking->structure_id}: " . $e->getMessage());
        }
    }

    /**
     * Best-effort guess at what happened in the final timer based on the
     * last known state. Subscribers (MM extraction_lost) display this in
     * the embed footer — purely informational.
     */
    private function inferFinalTimerResult(StructureDisappearanceTracking $tracking): string
    {
        return match ($tracking->last_known_state) {
            'shield_vulnerable', 'shield_reinforce' => 'lost_in_shield_or_armor_timer',
            'armor_vulnerable',  'armor_reinforce'  => 'lost_in_armor_or_hull_timer',
            'hull_vulnerable',   'hull_reinforce'   => 'lost_in_hull_timer',
            default                                  => 'unknown',
        };
    }
}
