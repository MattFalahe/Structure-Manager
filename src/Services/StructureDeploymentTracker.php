<?php

namespace StructureManager\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use StructureManager\Models\StructureManagerSettings;
use StructureManager\Models\Timer;
use StructureManager\Models\WebhookConfiguration;

/**
 * Follows a newly deployed Upwell structure until it is online, keeping one
 * Structure Board row live for the stage it is in:
 *
 *   anchor_start     deploying, counting down to its first vulnerable window
 *   anchoring        counting down to the Quantum Core stage
 *   core_awaiting    anchored and waiting for its core, vulnerable meanwhile
 *   onlining         core installed, vulnerable until it comes online
 *   anchor_complete  finished; recorded already dismissed, never shown
 *
 * Two sources feed it. Notifications arrive within minutes but mark only some
 * transitions: StructureAnchoring as deployment starts, StructureOnline as the
 * core wait begins, StructureWentHighPower once a service is running.
 * Nothing marks the core going in. corporation_structures carries every stage
 * with ESI's own timer, but SeAT refreshes it roughly once an hour. Each stage
 * row is keyed per structure, so whichever source sees a stage first creates
 * it and the other refreshes it, and a structure only ever moves forward.
 *
 * The stage rows double as the state machine. anchor_complete exists so that
 * an hour-old structure row still reading "anchoring" cannot put a finished
 * deployment back on the board, and the reminder reads the rows rather than
 * the board, so dismissing a core wait hides it until the next pass but
 * never silences the reminder.
 */
class StructureDeploymentTracker
{
    public const STAGE_DEPLOYMENT = 'anchor_start';
    public const STAGE_ANCHORING  = 'anchoring';
    public const STAGE_CORE       = 'core_awaiting';
    public const STAGE_ONLINING   = 'onlining';
    public const STAGE_COMPLETE   = 'anchor_complete';

    /** In the order a structure passes through them. */
    public const STAGES = [
        self::STAGE_DEPLOYMENT,
        self::STAGE_ANCHORING,
        self::STAGE_CORE,
        self::STAGE_ONLINING,
        self::STAGE_COMPLETE,
    ];

    private const SEVERITY = [
        self::STAGE_DEPLOYMENT => 'warning',
        self::STAGE_ANCHORING  => 'info',
        self::STAGE_CORE       => 'critical',
        self::STAGE_ONLINING   => 'warning',
        self::STAGE_COMPLETE   => 'info',
    ];

    /**
     * States a structure only reaches once it is fully online. Seeing one of
     * these ends the deployment whatever stage was last recorded.
     */
    private const ONLINE_STATES = [
        'shield_vulnerable',
        'armor_reinforce',
        'armor_vulnerable',
        'hull_reinforce',
        'hull_vulnerable',
    ];

    /** States that belong to the deployment itself. */
    private const DEPLOYMENT_STATES = [
        'anchor_vulnerable',
        'anchoring',
        'onlining_vulnerable',
    ];

    /**
     * States a structure can show while it waits for its core. SeAT keeps
     * reporting "anchoring" after the timer runs out until it next refreshes,
     * and EVE's own wording for the wait is that the core is needed "to
     * complete its onlining process".
     */
    private const CORE_WAIT_STATES = [
        'anchoring',
        'onlining_vulnerable',
    ];

    /**
     * A structure left without its core this long is abandoned rather than
     * forgotten, so reminders stop. The board row stays until it resolves.
     */
    private const REMINDER_HORIZON_DAYS = 7;

    /**
     * Stage rows older than this belong to an earlier deployment of the same
     * structure ID. A deployment runs about a day plus however long the core
     * takes, so this leaves plenty of room either side.
     */
    private const CYCLE_DAYS = 14;

    /**
     * How far back a deployment still counts as the reason a structure has
     * just reached full power for the first time.
     */
    private const RECENT_DEPLOYMENT_DAYS = 3;

    /**
     * The first reminder goes out this long after the wait begins, and again
     * at every multiple. The 15 minute onlining window after the core goes in
     * plus a few minutes for the notification to arrive means a structure
     * whose core went straight in has usually reported full power by then.
     */
    public const DEFAULT_REMINDER_INTERVAL_MINUTES = 20;

    /** events.* category the reminder is routed through. */
    private const REMINDER_CATEGORY = 'quantum_core';

    public static function sourceReference(int $structureId, string $stage): string
    {
        return "deployment:{$structureId}:{$stage}";
    }

    /**
     * Record that a structure has reached a stage.
     *
     * Returns false when a later stage is already on record. Hourly structure
     * data can trail a notification by most of an hour, and a poll that still
     * sees the earlier stage must not undo what the notification established.
     *
     * @param Carbon|null $eveTime When the stage ends, or for the core wait
     *                             when it began. Null when the source cannot
     *                             tell; an existing row keeps its time.
     * @param array $context Any of structure_name, structure_type,
     *                       structure_type_id, system_id, system_name,
     *                       system_security, corporation_id,
     *                       owner_corporation_name.
     */
    public static function reachStage(int $structureId, string $stage, ?Carbon $eveTime, array $context = [], ?string $notes = null): bool
    {
        $position = array_search($stage, self::STAGES, true);
        if ($position === false || $stage === self::STAGE_COMPLETE) {
            return false;
        }

        $later = array_slice(self::STAGES, $position + 1);
        if (self::hasStageRow($structureId, $later)) {
            return false;
        }

        $ref = self::sourceReference($structureId, $stage);
        $existing = Timer::where('source_reference', $ref)->first();

        // A row left over from an earlier deployment of the same structure
        // ID is a fresh start, not the stage being seen again.
        if ($existing !== null && ! self::isCurrentCycle($existing)) {
            $existing = null;
            $fresh = true;
        } else {
            $fresh = $existing === null;
        }

        // The core wait counts up from when it began, so the first sighting
        // fixes that moment. Later passes that could only guess at it must
        // not move it, or the wait would keep restarting.
        if ($stage === self::STAGE_CORE && $existing !== null) {
            $eveTime = $existing->eve_time;
        }
        $eveTime = $eveTime ?? $existing?->eve_time ?? Carbon::now();

        $attrs = array_merge(self::contextFields($context), [
            'source'           => 'auto_anchor',
            'event_type'       => $stage,
            'severity'         => self::SEVERITY[$stage],
            'structure_id'     => $structureId,
            'eve_time'         => $eveTime,
            'source_reference' => $ref,
        ]);
        if ($notes !== null) {
            $attrs['notes'] = $notes;
        }

        // A stage an operator dismissed stays dismissed while the structure
        // is still in it; the poll repeats every few minutes and would
        // otherwise undo the click. The core wait is the exception: it is a
        // live vulnerability, so it comes back until the core is in.
        if ($fresh || $stage === self::STAGE_CORE) {
            $attrs['dismissed_at'] = null;
        }

        if ($fresh) {
            $attrs['emitted_upcoming_24h_at'] = null;
            $attrs['emitted_upcoming_6h_at']  = null;
            $attrs['emitted_upcoming_1h_at']  = null;
            $attrs['emitted_elapsed_at']      = null;
        }

        try {
            Timer::upsertAuto($attrs);
        } catch (\Throwable $e) {
            Log::warning("StructureDeploymentTracker: could not record {$stage} for structure {$structureId}: " . $e->getMessage());

            return false;
        }

        self::dismissStages($structureId, array_slice(self::STAGES, 0, $position));

        return true;
    }

    /**
     * The deployment is over: the structure is online, or it was destroyed.
     *
     * A completed deployment leaves an anchor_complete row behind, recorded
     * already dismissed so it never reaches the board. It is what stops an
     * hour-old structure row that still reads "anchoring" from reopening
     * the core wait. A destroyed structure needs no such guard, because it
     * disappears from corporation_structures altogether.
     */
    public static function finish(int $structureId, bool $completed = true): void
    {
        if ($completed
            && self::hasStageRow($structureId, self::STAGES)
            && ! self::hasStageRow($structureId, [self::STAGE_COMPLETE])
        ) {
            $latest = Timer::whereIn('source_reference', self::sourceReferences($structureId, self::STAGES))
                ->orderByDesc('eve_time')
                ->first();

            $now = Carbon::now();

            try {
                Timer::upsertAuto(array_merge(
                    $latest ? self::contextFields($latest->only([
                        'structure_name', 'structure_type', 'structure_type_id', 'system_id',
                        'system_name', 'system_security', 'corporation_id', 'owner_corporation_name',
                    ])) : [],
                    [
                        'source'           => 'auto_anchor',
                        'event_type'       => self::STAGE_COMPLETE,
                        'severity'         => self::SEVERITY[self::STAGE_COMPLETE],
                        'structure_id'     => $structureId,
                        'eve_time'         => $now,
                        'source_reference' => self::sourceReference($structureId, self::STAGE_COMPLETE),
                        'dismissed_at'     => $now,
                    ]
                ));
            } catch (\Throwable $e) {
                Log::warning("StructureDeploymentTracker: could not mark structure {$structureId} complete: " . $e->getMessage());
            }
        }

        self::dismissStages($structureId, self::STAGES);
    }

    /**
     * Has this structure been through a deployment in the last few days?
     * Full power for the first time after anchoring is not power restored.
     */
    public static function isRecentDeployment(int $structureId): bool
    {
        return Timer::whereIn('source_reference', self::sourceReferences($structureId, self::STAGES))
            ->where('eve_time', '>=', Carbon::now()->subDays(self::RECENT_DEPLOYMENT_DAYS))
            ->exists();
    }

    /**
     * Bring every structure whose state belongs to a deployment up to date
     * from corporation_structures, and close out those that have finished.
     */
    public static function syncFromState(): array
    {
        $stats = ['deploying' => 0, 'finished' => 0];

        $tracked = Timer::query()
            ->whereIn('event_type', self::STAGES)
            ->where('source', 'auto_anchor')
            ->whereNull('dismissed_at')
            ->whereNotNull('structure_id')
            ->pluck('structure_id')
            ->unique()
            ->values()
            ->all();

        $rows = DB::table('corporation_structures as cs')
            ->leftJoin('universe_structures as us', 'us.structure_id', '=', 'cs.structure_id')
            ->leftJoin('invTypes as it', 'it.typeID', '=', 'cs.type_id')
            ->leftJoin('mapDenormalize as md', 'md.itemID', '=', 'cs.system_id')
            ->leftJoin('corporation_infos as ci', 'ci.corporation_id', '=', 'cs.corporation_id')
            ->where(function ($q) use ($tracked) {
                $q->whereIn('cs.state', self::DEPLOYMENT_STATES);
                if (! empty($tracked)) {
                    $q->orWhereIn('cs.structure_id', $tracked);
                }
            })
            ->select(
                'cs.structure_id',
                'cs.corporation_id',
                'cs.type_id',
                'cs.system_id',
                'cs.state',
                'cs.state_timer_start',
                'cs.state_timer_end',
                'us.name as structure_name',
                'it.typeName as structure_type',
                'md.itemName as system_name',
                'md.security as system_security',
                'ci.name as owner_corporation_name'
            )
            ->get();

        foreach ($rows as $row) {
            $structureId = (int) $row->structure_id;

            if (in_array($row->state, self::ONLINE_STATES, true)) {
                self::finish($structureId);
                $stats['finished']++;

                continue;
            }

            $stage = self::stageForState($row);
            if ($stage === null) {
                continue;
            }

            [$stageName, $eveTime] = $stage;
            if (self::reachStage($structureId, $stageName, $eveTime, self::contextFromStateRow($row))) {
                $stats['deploying']++;
            }
        }

        return $stats;
    }

    /**
     * Map a corporation_structures row to the stage it describes.
     *
     * @return array{0:string,1:?Carbon}|null
     */
    private static function stageForState(object $row): ?array
    {
        $start = $row->state_timer_start ? Carbon::parse($row->state_timer_start) : null;
        $end   = $row->state_timer_end ? Carbon::parse($row->state_timer_end) : null;

        return match ($row->state) {
            // The anchoring timer runs to the start of the core stage, so
            // once it has run out the wait began when it ended.
            'anchoring' => ($end !== null && $end->isFuture())
                ? [self::STAGE_ANCHORING, $end]
                : [self::STAGE_CORE, $end ?? $start],
            // Onlining with time left means the core is in and the last
            // vulnerable window is running. Without any, the structure is
            // stuck in onlining because it has no core to finish it.
            'onlining_vulnerable' => ($end !== null && $end->isFuture())
                ? [self::STAGE_ONLINING, $end]
                : [self::STAGE_CORE, $start],
            default => null,
        };
    }

    /**
     * Put back any core wait that is still unresolved but was dismissed on
     * the board, then send whatever reminders have come due.
     */
    public static function sendDueReminders(): int
    {
        // Settings accepts 5 to 240. Anything else in the table falls back to
        // the default rather than meaning "never": routing a reminder nowhere
        // is what leaving the category unbound is for.
        $interval = (int) StructureManagerSettings::get(
            'core_reminder_interval_minutes',
            self::DEFAULT_REMINDER_INTERVAL_MINUTES
        );
        if ($interval < 5 || $interval > 240) {
            $interval = self::DEFAULT_REMINDER_INTERVAL_MINUTES;
        }

        $now = Carbon::now();
        $sent = 0;

        foreach (self::waitingForCore() as $timer) {
            if ($timer->dismissed_at !== null) {
                $timer->dismissed_at = null;
                $timer->save();
            }

            $waitedMinutes = (int) floor($timer->eve_time->diffInSeconds($now, false) / 60);
            if ($waitedMinutes < $interval) {
                continue;
            }

            // Reminder n falls due at n intervals. Only the latest one due is
            // sent, so a wait noticed late gets one reminder, not a burst of
            // the ones it missed.
            $number = intdiv($waitedMinutes, $interval);

            // Cache::add only writes when the key is absent, which makes it
            // the claim: of two overlapping runs only one sends.
            $claimKey = "structure-manager:core-reminder:{$timer->id}:{$number}";
            if (! Cache::add($claimKey, $now->toIso8601String(), $now->copy()->addDays(self::REMINDER_HORIZON_DAYS + 1))) {
                continue;
            }

            if (self::dispatchReminder($timer, $waitedMinutes, $number)) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Core waits with nothing after them, whether or not the board row is
     * currently dismissed.
     */
    private static function waitingForCore()
    {
        $horizon = Carbon::now()->subDays(self::REMINDER_HORIZON_DAYS);

        return Timer::query()
            ->where('event_type', self::STAGE_CORE)
            ->where('source', 'auto_anchor')
            ->whereNotNull('structure_id')
            ->where('eve_time', '>=', $horizon)
            ->get()
            ->filter(fn (Timer $timer) => self::stillWaitingForCore($timer));
    }

    private static function stillWaitingForCore(Timer $timer): bool
    {
        $structureId = (int) $timer->structure_id;

        if (self::hasStageRow($structureId, [self::STAGE_ONLINING, self::STAGE_COMPLETE])) {
            return false;
        }

        // A structure that has gone from corporation_structures was
        // destroyed or unanchored, or SeAT can no longer see it.
        $state = DB::table('corporation_structures')
            ->where('structure_id', $structureId)
            ->value('state');

        return in_array($state, self::CORE_WAIT_STATES, true);
    }

    private static function dispatchReminder(Timer $timer, int $waitedMinutes, int $number): bool
    {
        $bindings = WebhookDispatcher::resolveBindings(
            'events',
            self::REMINDER_CATEGORY,
            $timer->corporation_id !== null ? (int) $timer->corporation_id : null
        );

        if (empty($bindings)) {
            Log::info(sprintf(
                'StructureDeploymentTracker: structure %d has waited %dm for its core; no webhook bound to events.%s',
                $timer->structure_id,
                $waitedMinutes,
                self::REMINDER_CATEGORY
            ));

            return false;
        }

        $payload = self::buildReminderPayload($timer, $waitedMinutes, $number);

        foreach ($bindings as $binding) {
            if (! WebhookConfiguration::isValidWebhookUrl($binding['webhook_url'])) {
                continue;
            }

            [$prefix, $allowedMentions] = WebhookDispatcher::formatMention($binding['role_mention'] ?? null);
            $final = $payload;
            $final['content'] = trim($prefix . $final['content']);
            $final['allowed_mentions'] = $allowedMentions;

            WebhookDeliveryService::sendByUrl(
                $binding['webhook_url'],
                $final,
                'events.' . self::REMINDER_CATEGORY,
                "Quantum Core reminder #{$number} for structure {$timer->structure_id}"
            );
        }

        Log::info(sprintf(
            'StructureDeploymentTracker: sent core reminder #%d for structure %d (%s), waiting %dm, to %d webhook(s)',
            $number,
            $timer->structure_id,
            $timer->structure_name ?? 'unnamed',
            $waitedMinutes,
            count($bindings)
        ));

        return true;
    }

    private static function buildReminderPayload(Timer $timer, int $waitedMinutes, int $number): array
    {
        $name = $timer->structure_name ?: ($timer->structure_type ?: 'Structure');

        $location = $timer->system_name ?? 'Unknown';
        if ($timer->system_security !== null) {
            $location .= ' (' . number_format((float) $timer->system_security, 2) . ')';
        }

        $fields = [
            ['name' => "\u{1F4CD} Location", 'value' => $location, 'inline' => true],
            ['name' => 'Structure Type', 'value' => $timer->structure_type ?? 'Unknown', 'inline' => true],
            [
                'name'   => "\u{23F3} Waiting Since",
                'value'  => $timer->eve_time->format('Y-m-d H:i') . " UTC\n*(" . self::humanMinutes($waitedMinutes) . ')*',
                'inline' => true,
            ],
        ];

        if (! empty($timer->owner_corporation_name)) {
            $fields[] = ['name' => 'Owning Corporation', 'value' => $timer->owner_corporation_name, 'inline' => true];
        }

        return [
            'content'  => '**Quantum Core Reminder**',
            'username' => 'SeAT Structure Manager',
            'embeds'   => [[
                'title'       => "{$name} is still waiting for its Quantum Core",
                'description' => 'Anchoring finished ' . self::humanMinutes($waitedMinutes) . ' ago. '
                    . 'The structure stays vulnerable and cannot come online until its Quantum Core is installed.',
                'color'       => 0xdc2626,
                'fields'      => $fields,
                'footer'      => ['text' => 'SeAT Structure Manager | Reminder ' . $number],
                'timestamp'   => Carbon::now()->toIso8601String(),
            ]],
        ];
    }

    private static function humanMinutes(int $minutes): string
    {
        if ($minutes < 60) {
            return "{$minutes}m";
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $rest > 0 ? "{$hours}h {$rest}m" : "{$hours}h";
    }

    private static function sourceReferences(int $structureId, array $stages): array
    {
        return array_map(fn ($stage) => self::sourceReference($structureId, $stage), $stages);
    }

    private static function hasStageRow(int $structureId, array $stages): bool
    {
        if (empty($stages)) {
            return false;
        }

        return Timer::whereIn('source_reference', self::sourceReferences($structureId, $stages))
            ->where('eve_time', '>=', Carbon::now()->subDays(self::CYCLE_DAYS))
            ->exists();
    }

    private static function isCurrentCycle(Timer $timer): bool
    {
        return $timer->eve_time !== null
            && $timer->eve_time->gte(Carbon::now()->subDays(self::CYCLE_DAYS));
    }

    /**
     * Dismiss through Eloquent so TimerObserver tells subscribers each stage
     * row has gone.
     */
    private static function dismissStages(int $structureId, array $stages): void
    {
        if (empty($stages)) {
            return;
        }

        Timer::whereIn('source_reference', self::sourceReferences($structureId, $stages))
            ->whereNull('dismissed_at')
            ->get()
            ->each(function (Timer $timer) {
                $timer->dismissed_at = Carbon::now();
                $timer->save();
            });
    }

    private static function contextFields(array $context): array
    {
        $allowed = [
            'structure_name',
            'structure_type',
            'structure_type_id',
            'system_id',
            'system_name',
            'system_security',
            'corporation_id',
            'owner_corporation_name',
        ];

        // Missing context must not blank out what an earlier source filled in.
        return array_filter(
            array_intersect_key($context, array_flip($allowed)),
            fn ($value) => $value !== null && $value !== ''
        );
    }

    private static function contextFromStateRow(object $row): array
    {
        return [
            'structure_name'         => $row->structure_name,
            'structure_type'         => $row->structure_type,
            'structure_type_id'      => $row->type_id !== null ? (int) $row->type_id : null,
            'system_id'              => $row->system_id !== null ? (int) $row->system_id : null,
            'system_name'            => $row->system_name,
            'system_security'        => $row->system_security,
            'corporation_id'         => $row->corporation_id !== null ? (int) $row->corporation_id : null,
            'owner_corporation_name' => $row->owner_corporation_name,
        ];
    }
}
