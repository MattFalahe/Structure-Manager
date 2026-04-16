<?php

namespace StructureManager\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use StructureManager\Models\EsiNotification;
use StructureManager\Models\StructureManagerSettings;
use Carbon\Carbon;
use Symfony\Component\Yaml\Yaml;

/**
 * Fallback sweep of SeAT's character_notifications table.
 *
 * Runs every 10 minutes. Picks up any structure-related notifications
 * that the fast-poll missed (e.g., a director's token was expired, ESI
 * was down during our poll window, or the notification arrived between
 * polling cycles). Deduplicates by notification_id against our own table
 * so already-seen notifications are never processed twice.
 *
 * This is the "belt-and-suspenders" layer — the fast-poll handles 90%+
 * of notifications within 2 minutes; this sweep catches the remainder
 * within ~10-20 minutes (still faster than SeAT's 20-30 min bucket delay
 * because we process immediately on discovery).
 */
class SweepSeatNotifications implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $timeout = 120;
    public $tries = 2;
    public $backoff = [30, 60];

    /**
     * All structure notification types we handle (same list as PollStructureNotifications).
     */
    const HANDLED_TYPES = [
        // Attack
        'StructureUnderAttack', 'StructureLostShields', 'StructureLostArmor',
        'StructureDestroyed', 'SkyhookUnderAttack', 'SkyhookLostShields', 'SkyhookDestroyed',
        // Lifecycle
        'StructureAnchoring', 'AllAnchoringMsg', 'StructureUnanchoring',
        'OwnershipTransferred', 'SkyhookDeployed',
        // Fuel events
        'StructureWentLowPower', 'StructureWentHighPower', 'StructureServicesOffline',
        'StructureFuelAlert', 'StructureLowReagentsAlert', 'StructureNoReagentsAlert',
        'SkyhookOnline',
    ];

    /**
     * Execute the job.
     */
    public function handle()
    {
        if (!Schema::hasTable('character_notifications')) {
            Log::debug('SweepSeatNotifications: character_notifications table not found (SeAT not fully installed?)');
            return;
        }

        // Look back 2 hours — anything older is probably already processed or stale
        $cutoff = Carbon::now()->subHours(2);

        $seatNotifications = DB::table('character_notifications')
            ->whereIn('type', self::HANDLED_TYPES)
            ->where('timestamp', '>=', $cutoff)
            ->orderBy('timestamp', 'desc')
            ->limit(200) // safety cap
            ->get();

        if ($seatNotifications->isEmpty()) {
            Log::debug('SweepSeatNotifications: No structure notifications in SeAT table within 2h window');
            return;
        }

        Log::info("SweepSeatNotifications: Found {$seatNotifications->count()} candidate notification(s) in SeAT table");

        $newCount = 0;

        foreach ($seatNotifications as $seatNotif) {
            $notificationId = $seatNotif->notification_id;

            // Deduplicate — skip if we already have this notification
            if (EsiNotification::where('notification_id', $notificationId)->exists()) {
                continue;
            }

            // Resolve corporation_id from character_affiliations
            $corporationId = DB::table('character_affiliations')
                ->where('character_id', $seatNotif->character_id)
                ->value('corporation_id') ?? 0;

            // Parse the YAML text field
            $rawText = $seatNotif->text ?? '';
            $parsedData = null;
            try {
                $parsedData = is_string($rawText) ? Yaml::parse($rawText) : $rawText;
            } catch (\Throwable $e) {
                $parsedData = ['raw' => $rawText];
            }

            // Insert as fallback source
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
                'source' => 'seat_fallback',
                'processed' => false,
            ]);

            $newCount++;
            Log::info("SweepSeatNotifications: Picked up {$seatNotif->type} #{$notificationId} from SeAT table (fallback)");
        }

        Log::info("SweepSeatNotifications: Done. New fallback notifications: {$newCount}");

        // Process any unprocessed notifications (reuse PollStructureNotifications logic)
        // We dispatch the poll job to handle processing — it processes all unprocessed rows
        if ($newCount > 0) {
            dispatch(new PollStructureNotifications());
        }
    }
}
