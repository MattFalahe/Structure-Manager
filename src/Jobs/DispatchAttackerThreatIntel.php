<?php

namespace StructureManager\Jobs;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use StructureManager\Models\StructureManagerSettings;
use StructureManager\Models\WebhookConfiguration;
use StructureManager\Services\IdResolver;
use StructureManager\Services\WebhookDispatcher;
use StructureManager\Services\ZKillThreatService;

/**
 * Opt-in async attacker threat intel.
 *
 * Dispatched as a fire-and-forget side effect of the under-attack alert,
 * AFTER the primary alert has already gone out. Queries zKillboard for the
 * attacker's profile, builds a separate Discord embed ("who is shooting
 * you"), and dispatches it via WebhookDispatcher under the dedicated
 * `events.attacker_threat_intel` category.
 *
 * Why a separate job + separate category:
 *
 *   1. The primary alert is time-critical and must NOT wait on zKB. The
 *      under-attack ping has to land in fleet channels within seconds of
 *      ESI detection so FCs can scramble. zKB lookups add 200-2000ms each;
 *      doing them inline would bottleneck dispatch during a major op.
 *
 *   2. zKB enrichment is operator-OPT-IN. Some operators don't want their
 *      SeAT instance making external calls every time a citadel gets hit
 *      (rate-limit concern; opsec preference; can't reach zKB from their
 *      network). Splitting into a separate category with its own master
 *      toggle + bindings respects that choice.
 *
 *   3. Different audience routing. The under-attack alert pings fleet
 *      channels for "form fleet NOW". The threat intel is useful 30s
 *      later in an intel channel: "btw the guy shooting your citadel is
 *      a known griefer". Different audiences naturally want different
 *      Discord channels.
 *
 * Lifecycle:
 *   - Master toggle `attacker_threat_intel_enabled` gates the dispatch.
 *     If false: job exits immediately (cheap no-op).
 *   - Bindings resolved via WebhookDispatcher('events', 'attacker_threat_intel').
 *     If empty: job exits. Same shape as every other notification path.
 *   - Profile fetched via ZKillThreatService::getProfile. Returns null on
 *     ANY failure (timeout, 429, missing data). Null = skip dispatch
 *     silently; we never send a half-empty embed.
 *   - Embed built, dispatched via Http facade. Same defensive try/catch
 *     pattern as StructureEventHandler::dispatch.
 *
 * Idempotency: this job runs once per primary alert dispatch. Re-running
 * (e.g. retried by Laravel after a transient failure) just hits zKB cache
 * and re-sends — which is the worst case "fleet sees the same intel
 * twice". Acceptable for a best-effort enrichment.
 *
 * Standalone: this job does NOT require Manager Core. It runs purely
 * within SM's category system. zKB is the only external dependency.
 */
class DispatchAttackerThreatIntel implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Bound below SeAT's queue retry_after default (960s). */
    public $timeout = 30;

    /**
     * Single attempt. zKB lookups are best-effort; if it fails, the next
     * attack will retry naturally. Retrying within the same dispatch just
     * wastes worker cycles and might hit zKB rate limits.
     */
    public $tries = 1;

    public int $attackerCharacterId;
    public ?int $corporationId;
    public ?string $structureName;
    public ?string $structureType;
    public ?int $structureTypeId;
    public ?string $systemName;
    public ?string $attackerCorpName;
    public ?string $attackerAllianceName;
    public ?int $attackerCorpId;
    public ?int $attackerAllianceId;
    public string $primaryEventFlavor;
    public string $primaryEventId;

    /**
     * @param int    $attackerCharacterId The attacker's character_id (zKB lookup key)
     * @param int|null $corporationId     OUR corp_id (the defender corp) for binding resolution
     * @param string $primaryEventFlavor  e.g. 'shield_reinforced' / 'armor_reinforced'
     * @param string $primaryEventId      Correlation key — the event_id of the under-attack alert this enriches
     * @param array  $context             Structure/system/attacker metadata for the embed (see properties above)
     */
    public function __construct(
        int $attackerCharacterId,
        ?int $corporationId,
        string $primaryEventFlavor,
        string $primaryEventId,
        array $context = []
    ) {
        $this->attackerCharacterId  = $attackerCharacterId;
        $this->corporationId        = $corporationId;
        $this->primaryEventFlavor   = $primaryEventFlavor;
        $this->primaryEventId       = $primaryEventId;

        // Flatten context onto serializable properties so SerializesModels
        // doesn't have to wrestle with an opaque array. Each field is
        // independent — missing fields render as "Unknown" in the embed
        // rather than blocking dispatch.
        $this->structureName         = $context['structure_name']         ?? null;
        $this->structureType         = $context['structure_type']         ?? null;
        $this->structureTypeId       = $context['structure_type_id']      ?? null;
        $this->systemName            = $context['system_name']            ?? null;
        $this->attackerCorpName      = $context['attacker_corp_name']     ?? null;
        $this->attackerAllianceName  = $context['attacker_alliance_name'] ?? null;
        $this->attackerCorpId        = $context['attacker_corp_id']       ?? null;
        $this->attackerAllianceId    = $context['attacker_alliance_id']   ?? null;
    }

    public function handle(ZKillThreatService $zkbThreat): void
    {
        // 1. Master toggle. Operator opt-in; default OFF — dispatching SM
        //    without this set behaves identically to before this feature
        //    existed. Cheap early bail.
        $enabled = StructureManagerSettings::get('attacker_threat_intel_enabled', false);
        if (!filter_var($enabled, FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        // 2. Webhook bindings. If no admin has bound this category to a
        //    webhook, there's nowhere to send the intel — bail without
        //    hitting zKB. This also gives operators a granular off-switch
        //    (disable bindings instead of the master toggle to "pause" the
        //    feature without losing config).
        $bindings = WebhookDispatcher::resolveBindings(
            'events',
            'attacker_threat_intel',
            $this->corporationId
        );
        if (empty($bindings)) {
            Log::debug(sprintf(
                'DispatchAttackerThreatIntel: no bindings for events.attacker_threat_intel / corp %s — skipping',
                $this->corporationId ?? 'global'
            ));
            return;
        }

        // 3. Fetch profile. Null = zKB unavailable / no data; we skip
        //    silently rather than send a barren embed. Cached 7 days
        //    in the service so repeat attackers in the same hour resolve
        //    instantly without re-querying.
        $profile = $zkbThreat->getProfile($this->attackerCharacterId);
        if ($profile === null) {
            Log::info(sprintf(
                'DispatchAttackerThreatIntel: no zKB profile available for char %d; skipping enrichment for event %s',
                $this->attackerCharacterId,
                $this->primaryEventId
            ));
            return;
        }

        // 4. Attacker name fallback — if the caller didn't pass it through,
        //    resolve via IdResolver (cached). One DB hit at worst.
        $attackerCharacterName = IdResolver::characterName($this->attackerCharacterId)
            ?? "Character #{$this->attackerCharacterId}";

        // 5. Build embed + dispatch
        $payload = $this->buildEmbed($profile, $attackerCharacterName);

        foreach ($bindings as $binding) {
            if (!WebhookConfiguration::isValidWebhookUrl($binding['webhook_url'])) {
                continue;
            }

            $finalPayload = $this->injectMention($payload, $binding['role_mention'] ?? '');

            // v2.0.0 — route through WebhookDeliveryService for telemetry.
            // Failures don't escalate — primary alert already fired.
            \StructureManager\Services\WebhookDeliveryService::sendByUrl(
                $binding['webhook_url'],
                $finalPayload,
                'events.attacker_threat_intel',
                "Attacker threat intel: char #{$this->attackerCharacterId}"
            );
        }

        Log::info(sprintf(
            'DispatchAttackerThreatIntel: dispatched threat intel for char %d (%s, tier=%s, kills_30d=%d) to %d webhook(s) — primary event %s',
            $this->attackerCharacterId,
            $attackerCharacterName,
            $profile['tier'] ?? 'unknown',
            $profile['kills_30d'] ?? 0,
            count($bindings),
            $this->primaryEventId
        ));
    }

    /**
     * Build the Discord embed payload from the zKB profile.
     */
    private function buildEmbed(array $profile, string $attackerName): array
    {
        // Color matches the tier — gives an at-a-glance threat read.
        $color = match ($profile['tier'] ?? 'cold') {
            'professional' => 0xdc2626, // red
            'active'       => 0xea580c, // orange
            'casual'       => 0xeab308, // yellow
            'dormant'      => 0x6b7280, // grey
            default        => 0x3b82f6, // blue (cold / no data)
        };

        $fields = [];

        // Tier headline — this is the FC's first read on threat level.
        if (!empty($profile['tier_label'])) {
            $fields[] = [
                'name'   => 'Threat Tier',
                'value'  => $profile['tier_label'],
                'inline' => false,
            ];
        }

        // Kill stats. Numbers normalized to be readable (1,234 not 1234).
        $fields[] = [
            'name'   => 'Recent Activity (~30d)',
            'value'  => number_format((int) ($profile['kills_30d'] ?? 0)) . ' kill(s)',
            'inline' => true,
        ];

        if (!empty($profile['kills_lifetime'])) {
            $fields[] = [
                'name'   => 'Lifetime Kills',
                'value'  => number_format((int) $profile['kills_lifetime']),
                'inline' => true,
            ];
        }

        // Danger ratio — zKB's 0-100 metric. Add a readable verdict so
        // operators don't have to interpret the raw number.
        if (isset($profile['danger_ratio'])) {
            $dr = (int) $profile['danger_ratio'];
            $verdict = $dr >= 75 ? ' (high)' : ($dr >= 40 ? ' (moderate)' : ' (low)');
            $fields[] = [
                'name'   => 'Danger Ratio',
                'value'  => $dr . '/100' . $verdict,
                'inline' => true,
            ];
        }

        // Gang ratio — solo vs. blob preference. 0=solo, 100=always blob.
        if (isset($profile['gang_ratio'])) {
            $gr = (int) $profile['gang_ratio'];
            $style = $gr >= 70 ? 'blob' : ($gr >= 30 ? 'mixed' : 'solo');
            $fields[] = [
                'name'   => 'Engagement Style',
                'value'  => $gr . '% gang (' . $style . ')',
                'inline' => true,
            ];
        }

        // Top ship — what to expect on field.
        if (!empty($profile['top_ship_name'])) {
            $fields[] = [
                'name'   => 'Most-Flown Ship',
                'value'  => $profile['top_ship_name'],
                'inline' => true,
            ];
        }

        // Last activity — recent attacker vs. dormant returner.
        if (isset($profile['days_since_last_kill'])) {
            $dsk = (int) $profile['days_since_last_kill'];
            $whenLabel = $dsk === 0 ? 'today' : ($dsk === 1 ? 'yesterday' : "{$dsk} days ago");
            $fields[] = [
                'name'   => 'Last Killed',
                'value'  => $whenLabel,
                'inline' => true,
            ];
        }

        // Context block — restate WHERE the attack is so the intel embed
        // stands alone (operators might see it in a separate channel).
        $contextParts = [];
        if ($this->structureName) {
            $contextParts[] = '**Target:** ' . $this->structureName
                . ($this->structureType ? " ({$this->structureType})" : '');
        }
        if ($this->systemName) {
            $contextParts[] = '**System:** ' . $this->systemName;
        }
        if ($this->attackerCorpName) {
            $contextParts[] = '**Corp:** ' . $this->attackerCorpName;
        }
        if ($this->attackerAllianceName) {
            $contextParts[] = '**Alliance:** ' . $this->attackerAllianceName;
        }
        if (!empty($contextParts)) {
            $fields[] = [
                'name'   => 'Context',
                'value'  => implode("\n", $contextParts),
                'inline' => false,
            ];
        }

        // Helpful action — direct link to the attacker's zKB profile.
        $fields[] = [
            'name'   => 'zKillboard Profile',
            'value'  => "[Open on zKillboard]({$profile['zkb_url']})",
            'inline' => false,
        ];

        $titleEvent = match ($this->primaryEventFlavor) {
            'shield_reinforced'      => 'shield reinforce',
            'armor_reinforced'       => 'armor reinforce',
            'hull_reinforced'        => 'hull reinforce',
            'destroyed'              => 'destruction',
            'sov_reinforced'         => 'sov reinforce',
            'entosis_in_progress'    => 'entosis',
            default                  => 'attack',
        };

        $embed = [
            'title' => sprintf("\u{1F50D} Attacker Intel — %s", $attackerName),
            'description' => sprintf(
                "Threat assessment for the pilot involved in the %s alert above.",
                $titleEvent
            ),
            'color'  => $color,
            'fields' => $fields,
            'footer' => [
                'text' => sprintf(
                    'Structure Manager attacker threat intel | data: zKillboard | correlates to event %s',
                    substr($this->primaryEventId, 0, 16) . '…'
                ),
            ],
            'timestamp' => Carbon::now()->toIso8601String(),
            'url'       => $profile['zkb_url'] ?? null,
        ];

        if (!empty($profile['top_ship_type_id'])) {
            $embed['thumbnail'] = [
                'url' => "https://images.evetech.net/types/{$profile['top_ship_type_id']}/render?size=64",
            ];
        }

        return [
            'content' => '',
            'embeds'  => [$embed],
            'allowed_mentions' => [
                'parse' => [],
                'users' => [],
                'roles' => [],
            ],
        ];
    }

    /**
     * Inject the role mention from the binding into the webhook content
     * (with allowed_mentions scoped so the role only mentions itself).
     * Delegates parsing to WebhookDispatcher for format-validation consistency.
     */
    private function injectMention(array $payload, ?string $mention): array
    {
        [$prefix, $allowedMentions] = WebhookDispatcher::formatMention($mention);
        if ($prefix !== '') {
            $payload['content'] = trim($prefix . ($payload['content'] ?? ''));
        }
        $payload['allowed_mentions'] = $allowedMentions;
        return $payload;
    }
}
