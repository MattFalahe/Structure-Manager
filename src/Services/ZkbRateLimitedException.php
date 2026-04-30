<?php

namespace StructureManager\Services;

/**
 * Thrown by ZkbClient when zKillboard returns HTTP 429. Carries the
 * server-supplied Retry-After value (seconds) so callers can honor it
 * rather than hammering the API on their default retry schedule.
 *
 * EnrichKillmailJob catches this and calls `$this->release($retryAfter)` to
 * reschedule itself outside zKB's window.
 */
class ZkbRateLimitedException extends \RuntimeException
{
    public int $retryAfterSeconds;

    public function __construct(int $retryAfterSeconds, ?string $message = null)
    {
        $this->retryAfterSeconds = max(1, $retryAfterSeconds);
        parent::__construct($message ?? "zKB rate-limited; retry after {$this->retryAfterSeconds}s");
    }
}
