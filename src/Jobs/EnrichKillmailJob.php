<?php

namespace StructureManager\Jobs;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use StructureManager\Helpers\AlertEventEnvelope;
use StructureManager\Models\StructureKillmailEnrichment;
use StructureManager\Services\ZkbClient;
use StructureManager\Services\ZkbRateLimitedException;

/**
 * Tier C Stage 2 — async killmail enrichment for `structure.alert.destroyed`.
 *
 * Dispatched after a destroyed event publishes (from either the CCP-notification
 * path in StructureEventHandler, or the grace-period path in TrackStructurePresence).
 * Queries zKB for the structure's killmail, resolves attacker / ISK details from
 * SeAT's local caches, then publishes `structure.alert.destroyed_confirmed`
 * correlated to the original destroyed event via `original_event_id`.
 *
 * Lifecycle:
 *   - dispatched with initial 30s delay (give zKB time to ingest)
 *   - handle() searches zKB; on hit, finalize as 'enriched' + publish stage 2
 *   - on miss, throw to trigger Laravel's $backoff schedule
 *   - on rate limit (HTTP 429), release() with zKB's Retry-After
 *   - after $tries exhausted, failed() finalizes as 'not_found' + publishes stage 2
 *
 * Idempotency is enforced by the local enrichment row's `published_at` column —
 * once stage 2 fires, subsequent attempts (e.g. duplicate dispatch from both
 * destroyed paths) skip the publish.
 *
 * @see StructureKillmailEnrichment
 * @see ZkbClient
 */
class EnrichKillmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Bound below SeAT's queue retry_after default (960s) per platform pitfall. */
    public $timeout = 60;

    /** Total attempts: 1 initial + 4 retries before failed() fires. */
    public $tries = 5;

    /**
     * Backoff between retries (seconds). Long enough to give zKB time to
     * ingest the killmail (typical 30s-5min, can be longer for low-traffic
     * regions). Total wait worst-case: 30+120+600+1800 = 2550s (~42 min)
     * before failed() trips and we publish 'not_found_in_zkb'.
     */
    public $backoff = [30, 120, 600, 1800];

    public int $structureId;
    public int $corporationId;
    public int $structureTypeId;
    public ?int $systemId;
    public string $destroyedAtIso;
    public string $originalEventId;

    public function __construct(
        int $structureId,
        int $corporationId,
        int $structureTypeId,
        ?int $systemId,
        string $destroyedAtIso,
        string $originalEventId
    ) {
        $this->structureId      = $structureId;
        $this->corporationId    = $corporationId;
        $this->structureTypeId  = $structureTypeId;
        $this->systemId         = $systemId;
        $this->destroyedAtIso   = $destroyedAtIso;
        $this->originalEventId  = $originalEventId;
    }

    public function handle(ZkbClient $zkb): void
    {
        // Skip dispatch with no system_id — zKB query needs it for filtering.
        // (StructureEventHandler / TrackStructurePresence already guard this,
        // but defensive check here too.)
        if ($this->systemId === null || $this->structureTypeId <= 0) {
            Log::info("EnrichKillmailJob: skipping {$this->structureId} — missing system_id or type_id");
            return;
        }

        // Get-or-create the local enrichment row. Unique constraint on
        // structure_id makes this atomic — handles duplicate dispatch races.
        $enrichment = StructureKillmailEnrichment::firstOrCreate(
            ['structure_id' => $this->structureId],
            [
                'corporation_id'    => $this->corporationId,
                'structure_type_id' => $this->structureTypeId,
                'system_id'         => $this->systemId,
                'destroyed_at'      => $this->destroyedAtIso,
                'original_event_id' => $this->originalEventId,
                'status'            => StructureKillmailEnrichment::STATUS_PENDING,
            ]
        );

        // Idempotency: if already terminal AND published, nothing to do.
        $isTerminal = in_array($enrichment->status, [
            StructureKillmailEnrichment::STATUS_ENRICHED,
            StructureKillmailEnrichment::STATUS_NOT_FOUND,
        ], true);
        if ($isTerminal && $enrichment->hasPublishedConfirmedEvent()) {
            Log::debug("EnrichKillmailJob: skipping {$this->structureId} — already finalized + published");
            return;
        }

        // Record this attempt
        $enrichment->attempts          = $enrichment->attempts + 1;
        $enrichment->last_attempted_at = Carbon::now();
        $enrichment->save();

        try {
            $killmail = $zkb->findStructureKillmail(
                $this->corporationId,
                $this->structureTypeId,
                $this->systemId,
                Carbon::parse($this->destroyedAtIso)
            );
        } catch (ZkbRateLimitedException $e) {
            // Honor zKB's Retry-After. release() reschedules without throwing,
            // but it does count as an attempt against $tries — that's
            // acceptable here, retries from rate limits are rare.
            Log::info("EnrichKillmailJob: zKB rate-limited (retry after {$e->retryAfterSeconds}s) for structure {$this->structureId}");
            $this->release($e->retryAfterSeconds);
            return;
        }

        if ($killmail === null) {
            // No match yet. Throw to trigger the next $backoff retry; after
            // $tries exhausted, failed() will publish stage 2 as not_found.
            throw new \RuntimeException(
                "zKB has no matching killmail yet for structure {$this->structureId} " .
                "(attempt {$enrichment->attempts}/{$this->tries})"
            );
        }

        $this->finalizeEnriched($enrichment, $killmail);
    }

    /**
     * Called by Laravel after $tries attempts have all failed. Finalize as
     * 'not_found' and publish stage 2 with enrichment_outcome=not_found_in_zkb
     * so subscribers know enrichment ran and gave up (vs. still pending).
     */
    public function failed(\Throwable $exception): void
    {
        $enrichment = StructureKillmailEnrichment::where('structure_id', $this->structureId)->first();
        if (!$enrichment) {
            Log::warning("EnrichKillmailJob::failed: no enrichment row for structure {$this->structureId}");
            return;
        }
        if ($enrichment->status === StructureKillmailEnrichment::STATUS_ENRICHED) {
            // Race: a successful run somehow happened after retry exhaustion.
            // Don't double-publish.
            return;
        }
        $this->finalizeNotFound($enrichment);
    }

    private function finalizeEnriched(StructureKillmailEnrichment $enrichment, array $killmail): void
    {
        $attackers = $killmail['attackers'] ?? [];

        $finalBlow = collect($attackers)->firstWhere('final_blow', true)
            ?? collect($attackers)->first();
        $topDamage = collect($attackers)->sortByDesc('damage_done')->first();

        $enrichment->status        = StructureKillmailEnrichment::STATUS_ENRICHED;
        $enrichment->enriched_at   = Carbon::now();
        $enrichment->killmail_id   = $killmail['killmail_id'] ?? null;
        $enrichment->killmail_hash = $killmail['zkb']['hash'] ?? null;
        $enrichment->killmail_url  = isset($killmail['killmail_id'])
            ? "https://zkillboard.com/kill/{$killmail['killmail_id']}/"
            : null;
        $enrichment->killmail_time = isset($killmail['killmail_time'])
            ? Carbon::parse($killmail['killmail_time'])
            : null;

        if ($finalBlow) {
            $enrichment->final_blow_character_id   = $finalBlow['character_id']   ?? null;
            $enrichment->final_blow_corporation_id = $finalBlow['corporation_id'] ?? null;
            $enrichment->final_blow_alliance_id    = $finalBlow['alliance_id']    ?? null;
            $enrichment->final_blow_ship_type_id   = $finalBlow['ship_type_id']   ?? null;

            $enrichment->final_blow_character_name   = $this->resolveCharName($finalBlow['character_id'] ?? null);
            $enrichment->final_blow_corporation_name = $this->resolveCorpName($finalBlow['corporation_id'] ?? null);
            $enrichment->final_blow_alliance_name    = $this->resolveAllianceName($finalBlow['alliance_id'] ?? null);
            $enrichment->final_blow_ship_type        = $this->resolveShipName($finalBlow['ship_type_id'] ?? null);
        }

        // Capture top damage dealer separately when distinct from final blow —
        // on structure kills the top-damage attacker is often more meaningful
        // (final blow could be a Catalyst landing the last shot, top damage
        // could be a fleet of Machariels that did most of the work).
        if ($topDamage && $topDamage !== $finalBlow) {
            $enrichment->top_damage_character_id   = $topDamage['character_id'] ?? null;
            $enrichment->top_damage_character_name = $this->resolveCharName($topDamage['character_id'] ?? null);
            $enrichment->top_damage_ship_type_id   = $topDamage['ship_type_id'] ?? null;
            $enrichment->top_damage_ship_type      = $this->resolveShipName($topDamage['ship_type_id'] ?? null);
        }

        $enrichment->attacker_count = count($attackers);
        $enrichment->isk_value      = $killmail['zkb']['totalValue'] ?? null;
        $enrichment->zkb_points     = $killmail['zkb']['points']     ?? null;

        $enrichment->save();

        $this->publishConfirmedEvent($enrichment, 'enriched');
    }

    private function finalizeNotFound(StructureKillmailEnrichment $enrichment): void
    {
        $enrichment->status     = StructureKillmailEnrichment::STATUS_NOT_FOUND;
        $enrichment->gave_up_at = Carbon::now();
        $enrichment->save();

        $this->publishConfirmedEvent($enrichment, 'not_found_in_zkb');
    }

    /**
     * Build and publish `structure.alert.destroyed_confirmed`. Idempotent via
     * `published_at` on the enrichment row — won't double-fire on the rare
     * case where finalize* gets called twice (race between handle() and
     * failed()).
     */
    private function publishConfirmedEvent(StructureKillmailEnrichment $enrichment, string $outcome): void
    {
        if ($enrichment->hasPublishedConfirmedEvent()) {
            Log::debug("EnrichKillmailJob: stage 2 already published for {$enrichment->structure_id}, skipping");
            return;
        }
        if (!class_exists('\\ManagerCore\\Services\\EventBus')) {
            // MC was uninstalled between dispatch and run. Record happened
            // (status + enriched_at/gave_up_at already set) but skip publish.
            Log::info("EnrichKillmailJob: MC absent at publish time, skipping stage 2 for {$enrichment->structure_id}");
            return;
        }

        $context = [
            'structure_id'       => $enrichment->structure_id,
            'corporation_id'     => $enrichment->corporation_id,
            'type_id'            => $enrichment->structure_type_id,           // legacy
            'structure_type_id'  => $enrichment->structure_type_id,           // contract
            'system_id'          => $enrichment->system_id,
            'severity'           => 'critical',
            'eve_time'           => $enrichment->destroyed_at,
            'source_reference'   => 'killmail-enrichment:' . $enrichment->structure_id,

            // Stage 2 specific — links back to stage 1 + indicates outcome
            'original_event_id'  => $enrichment->original_event_id,
            'enrichment_outcome' => $outcome,                                  // 'enriched' | 'not_found_in_zkb'
        ];

        if ($outcome === 'enriched') {
            $resolutionStatus = $enrichment->final_blow_character_name
                ? 'resolved'
                : ($enrichment->final_blow_character_id ? 'partial' : 'unresolved');

            $context = array_merge($context, [
                // Mirror the killmail's final blow into the standard attacker_*
                // contract fields so subscribers reading those names render
                // uniformly with shield/armor/hull events.
                'attacker_resolution_status' => $resolutionStatus,
                'attacker_character_id'      => $enrichment->final_blow_character_id,
                'attacker_character_name'    => $enrichment->final_blow_character_name,
                'attacker_corporation_id'    => $enrichment->final_blow_corporation_id,
                'attacker_corporation_name'  => $enrichment->final_blow_corporation_name,
                'attacker_alliance_id'       => $enrichment->final_blow_alliance_id,
                'attacker_alliance_name'     => $enrichment->final_blow_alliance_name,
                'attacker_ship_type_id'      => $enrichment->final_blow_ship_type_id,

                // Killmail core
                'killmail_id'    => $enrichment->killmail_id,
                'killmail_hash'  => $enrichment->killmail_hash,
                'killmail_url'   => $enrichment->killmail_url,
                'killmail_time'  => $enrichment->killmail_time?->toIso8601String(),

                // Final blow ship name (extra detail beyond the standard fields)
                'final_blow_ship_type' => $enrichment->final_blow_ship_type,

                // Top damage (often more meaningful on structure kills)
                'top_damage_character_id'   => $enrichment->top_damage_character_id,
                'top_damage_character_name' => $enrichment->top_damage_character_name,
                'top_damage_ship_type_id'   => $enrichment->top_damage_ship_type_id,
                'top_damage_ship_type'      => $enrichment->top_damage_ship_type,

                // Aggregate
                'attacker_count' => $enrichment->attacker_count,
                'isk_value'      => $enrichment->isk_value !== null ? (float) $enrichment->isk_value : null,
                'zkb_points'     => $enrichment->zkb_points,
            ]);
        } else {
            // not_found_in_zkb — no killmail data to mirror
            $context['attacker_resolution_status'] = 'unresolved';
        }

        $payload = AlertEventEnvelope::build('destroyed_confirmed', $context);

        try {
            app(\ManagerCore\Services\EventBus::class)->publish(
                'structure.alert.destroyed_confirmed',
                'structure-manager',
                $payload
            );
            $enrichment->markPublished();
            Log::info(
                "EnrichKillmailJob: published structure.alert.destroyed_confirmed for {$enrichment->structure_id} " .
                "(outcome={$outcome}, event_id={$payload['event_id']}, original={$enrichment->original_event_id})"
            );
        } catch (\Throwable $e) {
            Log::warning("EnrichKillmailJob: failed to publish destroyed_confirmed for {$enrichment->structure_id}: " . $e->getMessage());
        }
    }

    // ============================================================
    // Local-cache name resolvers — non-blocking. If the SeAT cache
    // doesn't have the name, leave it null and let subscribers render
    // the ID-only form. We don't fire ESI calls here.
    // ============================================================

    private function resolveCharName(?int $id): ?string
    {
        if (!$id) return null;
        try {
            return DB::table('character_infos')->where('character_id', $id)->value('name');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function resolveCorpName(?int $id): ?string
    {
        if (!$id) return null;
        try {
            return DB::table('corporation_infos')->where('corporation_id', $id)->value('name');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function resolveAllianceName(?int $id): ?string
    {
        if (!$id) return null;
        try {
            return DB::table('alliance_infos')->where('alliance_id', $id)->value('name');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function resolveShipName(?int $id): ?string
    {
        if (!$id) return null;
        try {
            return DB::table('invTypes')->where('typeID', $id)->value('typeName');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
