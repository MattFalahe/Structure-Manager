<?php

namespace StructureManager\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use StructureManager\Models\WebhookConfiguration;

/**
 * Centralized webhook dispatcher with delivery telemetry.
 *
 * Every webhook HTTP POST in Structure Manager should route through
 * this service. Previously each dispatcher (NotifyPosLowFuel,
 * NotifyUpwellLowFuel, StructureEventHandler, PreTimerReminderHandler,
 * DispatchAttackerThreatIntel) called `Http::post()` directly and
 * threw the result on the floor.
 *
 * What this service adds on top of a raw Http::post():
 *
 *   1. Telemetry — every attempt writes a row to
 *      structure_manager_webhook_deliveries with the HTTP status code,
 *      duration, success flag, error message, and a payload summary.
 *      The diagnostic page reads these rows for the per-webhook
 *      "delivery health" panel.
 *
 *   2. Uniform error handling — all five dispatchers used to have
 *      slightly different timeout values, retry semantics, and error
 *      logging formats. This service is the one place those decisions
 *      get made.
 *
 *   3. Future enrichment — circuit breakers, rate-limit backoff,
 *      retry-with-backoff, etc. can land here without touching the
 *      five dispatch sites.
 *
 * Hard rules:
 *   - Telemetry-table insert is wrapped in its own try/catch and
 *     NEVER blocks the dispatch outcome. A telemetry write failure
 *     is logged and swallowed; the dispatch is still considered
 *     authoritative based on the HTTP result.
 *   - error_message + payload_summary are length-capped (500 / 255
 *     chars) so a misbehaving Discord/Slack endpoint can't bloat
 *     the telemetry table with kilobyte-long bodies.
 */
class WebhookDeliveryService
{
    /**
     * Connection timeout for the HTTP request, in seconds.
     */
    public const CONNECT_TIMEOUT = 5;

    /**
     * Request timeout (response must arrive within this window), in seconds.
     */
    public const REQUEST_TIMEOUT = 10;

    /**
     * Maximum chars stored in error_message (truncation prevents
     * misbehaving endpoints from filling the table with huge bodies).
     */
    public const MAX_ERROR_LENGTH = 500;

    /**
     * Maximum chars stored in payload_summary (one-line label only).
     */
    public const MAX_SUMMARY_LENGTH = 255;

    /**
     * Send a webhook payload and record the outcome to telemetry.
     *
     * @param WebhookConfiguration $webhook       The webhook config row (must have a non-empty webhook_url)
     * @param array                $payload       The Discord/Slack-shaped payload (content, embeds, etc.)
     * @param string|null          $categoryKey   Notification category (upwell.fuel / events.structure_attack / ...) for telemetry
     * @param string|null          $payloadSummary One-line label for telemetry display (e.g. "structure_under_attack: 3AE-CP Fortizar")
     *
     * @return bool True when Discord/Slack returned HTTP 2xx, false otherwise
     */
    public static function send(
        WebhookConfiguration $webhook,
        array $payload,
        ?string $categoryKey = null,
        ?string $payloadSummary = null
    ): bool {
        if (empty($webhook->webhook_url)) {
            Log::warning("[Structure Manager] WebhookDeliveryService: webhook #{$webhook->id} has empty URL; skipping");
            return false;
        }

        $start = microtime(true);
        $statusCode = 0;
        $success = false;
        $errorMessage = null;

        try {
            $response = Http::connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::REQUEST_TIMEOUT)
                ->post($webhook->webhook_url, $payload);

            $statusCode = $response->status();
            $success = $response->successful();

            if (!$success) {
                $body = (string) $response->body();
                $errorMessage = 'HTTP ' . $statusCode . ': ' . $body;
            }
        } catch (\Throwable $e) {
            // Network failure / DNS / timeout / SSL — Http::post throws
            // ConnectionException or RequestException. Treat all as
            // status_code=0 + record exception detail.
            $statusCode = 0;
            $success = false;
            $errorMessage = get_class($e) . ': ' . $e->getMessage();
        }

        $durationMs = (int) round((microtime(true) - $start) * 1000);

        // Truncate before storage so the telemetry table can't be
        // bloated by a misbehaving endpoint dumping kilobyte bodies.
        $truncatedError = $errorMessage !== null
            ? mb_substr($errorMessage, 0, self::MAX_ERROR_LENGTH)
            : null;
        $truncatedSummary = $payloadSummary !== null
            ? mb_substr($payloadSummary, 0, self::MAX_SUMMARY_LENGTH)
            : null;

        try {
            DB::table('structure_manager_webhook_deliveries')->insert([
                'webhook_id' => $webhook->id,
                'attempted_at' => now(),
                'status_code' => $statusCode,
                'success' => $success,
                'duration_ms' => $durationMs,
                'category_key' => $categoryKey ? mb_substr($categoryKey, 0, 64) : null,
                'payload_summary' => $truncatedSummary,
                'error_message' => $truncatedError,
            ]);
        } catch (\Throwable $e) {
            // Telemetry failure must NEVER fail the dispatch. The HTTP
            // outcome is authoritative; we just lose the audit row.
            Log::error('[Structure Manager] WebhookDeliveryService telemetry insert failed: ' . $e->getMessage());
        }

        if ($success) {
            Log::info(sprintf(
                '[Structure Manager] Webhook #%d delivered (HTTP %d, %dms)%s',
                $webhook->id,
                $statusCode,
                $durationMs,
                $categoryKey ? " [{$categoryKey}]" : ''
            ));
        } else {
            Log::warning(sprintf(
                '[Structure Manager] Webhook #%d delivery FAILED (HTTP %d, %dms)%s: %s',
                $webhook->id,
                $statusCode,
                $durationMs,
                $categoryKey ? " [{$categoryKey}]" : '',
                mb_substr($errorMessage ?? '(unknown)', 0, 200)
            ));
        }

        return $success;
    }

    /**
     * Convenience wrapper for dispatchers that hold a webhook_url string
     * rather than the WebhookConfiguration model. Looks up the matching
     * model row (URLs are effectively unique per install) and forwards.
     *
     * When no matching configuration row is found (edge case: webhook
     * row deleted but a cached URL still in flight), the HTTP POST is
     * still sent so the operator's notification isn't lost — telemetry
     * is skipped with a warning log.
     *
     * @return bool True when Discord/Slack returned HTTP 2xx, false otherwise
     */
    public static function sendByUrl(
        string $webhookUrl,
        array $payload,
        ?string $categoryKey = null,
        ?string $payloadSummary = null
    ): bool {
        $webhook = WebhookConfiguration::where('webhook_url', $webhookUrl)->first();
        if ($webhook) {
            return self::send($webhook, $payload, $categoryKey, $payloadSummary);
        }

        // Fallback: untracked URL. POST but skip telemetry.
        Log::warning("[Structure Manager] WebhookDeliveryService::sendByUrl: URL {$webhookUrl} not found in structure_manager_webhooks; dispatching without telemetry");
        try {
            $response = Http::connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::REQUEST_TIMEOUT)
                ->post($webhookUrl, $payload);
            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning('[Structure Manager] Webhook delivery (untracked URL) failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Prune delivery telemetry older than 30 days. Called from the
     * structure-manager:cleanup-history command on the daily 3 AM tick.
     *
     * @return int Number of rows deleted
     */
    public static function pruneOldDeliveries(int $retentionDays = 30): int
    {
        try {
            return DB::table('structure_manager_webhook_deliveries')
                ->where('attempted_at', '<', \Carbon\Carbon::now()->subDays($retentionDays))
                ->delete();
        } catch (\Throwable $e) {
            Log::error('[Structure Manager] WebhookDeliveryService::pruneOldDeliveries failed: ' . $e->getMessage());
            return 0;
        }
    }
}
