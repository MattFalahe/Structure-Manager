<?php

namespace StructureManager\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use StructureManager\Models\StructureFuelEventCandidate;
use StructureManager\Services\WithdrawalForensicsService;

/**
 * Tier 2 — async candidate-list computation for withdrawal_* events.
 *
 * Dispatched from TrackFuelConsumption immediately after the classifier
 * tags a row as withdrawal_bay or withdrawal_reserves. Runs in the
 * background so the hourly poll doesn't block on forensic queries
 * (which can be slow on large corps with many members).
 *
 * Failure handling: silent. Forensics is enrichment, not correctness —
 * if it fails the withdrawal row still exists with the right event_type,
 * just without a candidate list. The poll's correctness must not depend
 * on this job succeeding.
 */
class WithdrawalForensicsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $timeout = 120;
    public $tries = 2;
    public $backoff = [60];

    /** @var int */
    public $fuelHistoryId;

    public function __construct(int $fuelHistoryId)
    {
        $this->fuelHistoryId = $fuelHistoryId;
    }

    public function handle(): void
    {
        try {
            $candidates = WithdrawalForensicsService::computeCandidates($this->fuelHistoryId);

            if (empty($candidates)) {
                Log::info("WithdrawalForensicsJob: no candidates for fuel_history #{$this->fuelHistoryId}");
                return;
            }

            // Remove any existing candidates for this event (idempotent on retry)
            StructureFuelEventCandidate::where('fuel_history_id', $this->fuelHistoryId)->delete();

            $now = now();
            foreach ($candidates as &$row) {
                // signals is an array column - JSON-cast by the model when
                // using Eloquent, but insert() bypasses casts, so encode manually.
                $row['signals'] = json_encode($row['signals']);
                $row['created_at'] = $now;
                $row['updated_at'] = $now;
            }
            unset($row);

            StructureFuelEventCandidate::insert($candidates);

            Log::info(sprintf(
                'WithdrawalForensicsJob: stored %d candidate(s) for fuel_history #%d',
                count($candidates),
                $this->fuelHistoryId
            ));
        } catch (\Throwable $e) {
            Log::error('WithdrawalForensicsJob: failed for fuel_history #' . $this->fuelHistoryId . ': ' . $e->getMessage());
            // Surface the error to the queue retry mechanism
            throw $e;
        }
    }
}
