<?php

namespace StructureManager\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use StructureManager\Handlers\StructureEventHandler;
use StructureManager\Integrations\ManagerCoreIntegration;
use StructureManager\Models\EsiNotification;
use Carbon\Carbon;
use Symfony\Component\Yaml\Yaml;

/**
 * SeAT-native notification sweep — runs when MC fast-poll isn't being used.
 *
 * Decides whether to run by consulting `ManagerCoreIntegration::isNativeSweepEnabled()`.
 * That method honors the operator's chosen detection mode:
 *
 *   - mode=auto + MC absent     → run (this job is the fallback)
 *   - mode=auto + MC present    → no-op (MC fast-poll is doing the work)
 *   - mode=seat_native          → run (operator opted out of MC fast-poll
 *                                  even though MC is installed)
 *   - mode=off                  → no-op (operator disabled detection)
 *
 * Without MC fast-poll the detection path is:
 *   SeAT's updateCharacterNotifications (15-20 min bucket) writes rows to
 *   character_notifications → this job reads them → dedup against SM's local
 *   structure_manager_esi_notifications table → call StructureEventHandler.
 *
 * The local dedup table prevents re-dispatch if SeAT re-reads a notification
 * (or this job runs twice on the same window).
 */
class ProcessStructureNotifications implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $timeout = 120;
    public $tries = 2;
    public $backoff = [30, 60];

    public function handle(): void
    {
        if (!ManagerCoreIntegration::isNativeSweepEnabled()) {
            $mode = ManagerCoreIntegration::detectionMode();
            $mcOn = ManagerCoreIntegration::isAvailable();
            Log::debug("ProcessStructureNotifications: native sweep disabled (mode={$mode}, mc_available=" . ($mcOn ? 'yes' : 'no') . "); job is a no-op.");
            return;
        }

        if (!Schema::hasTable('character_notifications')) {
            Log::debug('ProcessStructureNotifications: character_notifications table not found.');
            return;
        }

        $handledTypes = StructureEventHandler::registeredTypes();

        $cutoff = Carbon::now()->subHours(2);

        $seatNotifications = DB::table('character_notifications')
            ->whereIn('type', $handledTypes)
            ->where('timestamp', '>=', $cutoff)
            ->orderBy('timestamp', 'asc')
            ->limit(200)
            ->get();

        if ($seatNotifications->isEmpty()) {
            Log::debug('ProcessStructureNotifications: No recent relevant notifications in SeAT table.');
            return;
        }

        $newCount = 0;
        $dispatched = 0;

        // Two-phase: first record + dedup, then dispatch under a lock to prevent
        // double-fire if the job accidentally runs concurrently.
        foreach ($seatNotifications as $seatNotif) {
            $notificationId = $seatNotif->notification_id;

            if (EsiNotification::where('notification_id', $notificationId)->exists()) {
                continue;
            }

            $corporationId = DB::table('character_affiliations')
                ->where('character_id', $seatNotif->character_id)
                ->value('corporation_id') ?? 0;

            $rawText = $seatNotif->text ?? '';
            $parsedData = null;
            try {
                $parsedData = is_string($rawText) ? Yaml::parse($rawText) : $rawText;
            } catch (\Throwable $e) {
                // CCP changed the YAML format, or the row is corrupt. Log so
                // we can spot format drift early; still record the raw text
                // so the dispatch path has something to work with (handlers
                // tolerate missing fields).
                Log::warning(sprintf(
                    'ProcessStructureNotifications: YAML parse failed for notification #%s (type=%s): %s',
                    $notificationId,
                    $seatNotif->type ?? '?',
                    $e->getMessage()
                ));
                $parsedData = ['raw' => $rawText];
            }

            try {
                EsiNotification::create([
                    'notification_id' => $notificationId,
                    'character_id' => $seatNotif->character_id,
                    'corporation_id' => $corporationId,
                    'type' => $seatNotif->type,
                    'sender_id' => $seatNotif->sender_id ?? null,
                    'sender_type' => $seatNotif->sender_type ?? null,
                    'timestamp' => $seatNotif->timestamp,
                    'text' => is_string($rawText) ? $rawText : json_encode($rawText),
                    'parsed_data' => $parsedData,
                    'source' => 'seat_native',
                    'processed' => false,
                ]);
                $newCount++;
            } catch (\Illuminate\Database\QueryException $e) {
                if (str_contains($e->getMessage(), 'Duplicate entry') || str_contains($e->getMessage(), 'UNIQUE constraint')) {
                    continue;
                }
                throw $e;
            }
        }

        // Dispatch unprocessed rows (including any from previous failed runs)
        DB::transaction(function () use (&$dispatched) {
            $unprocessed = EsiNotification::where('processed', false)
                ->orderBy('timestamp', 'asc')
                ->limit(50)
                ->lockForUpdate()
                ->get();

            foreach ($unprocessed as $notification) {
                try {
                    StructureEventHandler::handle($notification);
                    $notification->markProcessed();
                    $dispatched++;
                } catch (\Throwable $e) {
                    Log::error("ProcessStructureNotifications: Failed to dispatch notification #{$notification->notification_id}: " . $e->getMessage());
                }
            }
        });

        Log::info("ProcessStructureNotifications: Recorded {$newCount}, dispatched {$dispatched}");
    }
}
