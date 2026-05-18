<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structure Manager v2.0.0 — webhook delivery telemetry.
 *
 * Background:
 *   Every webhook dispatch in v1.x logged via Log::info/error and threw
 *   the result away. When operators asked "did my Discord webhook
 *   actually fire?" the only answer was to grep laravel.log. There was
 *   no UI-visible record of HTTP status codes, latencies, or recent
 *   failures per webhook.
 *
 * What this table stores:
 *   One row per webhook dispatch attempt (success OR failure).
 *   WebhookDeliveryService::send() writes the row right after the
 *   HTTP::post returns. The diagnostic UI surfaces these rows as
 *   per-webhook delivery-health stats (last 24h success rate,
 *   recent failures with HTTP codes, latency p95).
 *
 * Retention:
 *   30-day window pruned by the existing structure-manager:cleanup-history
 *   command. Active alliance installs can produce thousands of dispatches
 *   per day; 30 days at typical volume keeps the table at <100k rows.
 *
 * Indexes:
 *   - (webhook_id, attempted_at DESC): per-webhook latest-N lookups
 *     (powering the "Last delivery" + "Recent failures" UI rows)
 *   - (attempted_at DESC): the daily-cleanup query's WHERE clause
 *   - (success, attempted_at DESC): "list recent failures across all
 *     webhooks" without a full-table scan
 */
class CreateWebhookDeliveryTelemetryTable extends Migration {

    public function up(): void
    {
        if (Schema::hasTable('structure_manager_webhook_deliveries')) {
            return;
        }

        Schema::create('structure_manager_webhook_deliveries', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('webhook_id')
                ->comment('FK to structure_manager_webhooks.id. Not FK-constrained so deletion of the parent webhook does not break the audit trail — the operator can still see "this webhook used to send N messages before I deleted it"');

            $table->timestamp('attempted_at')->useCurrent()
                ->comment('When the dispatch attempt was made');

            $table->smallInteger('status_code')->default(0)
                ->comment('HTTP status code from Discord/Slack. 0 = connection/timeout/network failure (no response received)');

            $table->boolean('success')
                ->comment('True when HTTP 2xx received. False on all other outcomes including timeouts, 4xx, 5xx, and connection failures');

            $table->integer('duration_ms')->nullable()
                ->comment('Round-trip time in milliseconds (request sent until response received or timeout)');

            $table->string('category_key', 64)->nullable()
                ->comment('Which notification category triggered this dispatch (upwell.fuel, events.structure_attack, pos.lifecycle, etc.). NULL for raw test sends');

            $table->string('payload_summary', 255)->nullable()
                ->comment('Short human-readable summary, e.g. "structure_under_attack: 3AE-CP Fortizar". For diagnostic display only — payload itself is not stored');

            $table->text('error_message')->nullable()
                ->comment('Error details when success=false. Truncated to first 500 chars. Contains HTTP status + response body OR exception class + message');

            $table->index(['webhook_id', 'attempted_at'], 'smwd_webhook_time_idx');
            $table->index('attempted_at', 'smwd_time_idx');
            $table->index(['success', 'attempted_at'], 'smwd_success_idx');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('structure_manager_webhook_deliveries')) {
            Schema::drop('structure_manager_webhook_deliveries');
        }
    }
}
