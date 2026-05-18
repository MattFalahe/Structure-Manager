<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Structure Manager v2.0.0 — notification categories seed consolidation.
 *
 * Replaces the granular incremental seed migrations that landed during
 * dev-4.0 development. The result is
 * the same end-state set of 18 categories plus the auto-binding behavior
 * the original migrations established, in a single seed migration that's
 * easier to read and easier to deploy on fresh installs.
 *
 * Categories seeded (by namespace):
 *
 *   upwell.*       (3)  fuel, magmatic_gas, cyno_reagents
 *   events.*       (12) structure_attack, structure_lifecycle,
 *                       structure_fuel_events, services_offline, sovereignty,
 *                       pre_timer_armor, pre_timer_hull, pre_timer_sov,
 *                       pre_timer_nodes, pre_timer_hostile, pre_timer_defense,
 *                       attacker_threat_intel
 *   pos.*          (3)  fuel, strontium, lifecycle
 *
 *   Total: 18 categories. 15 enabled-by-default + 3 opt-in (pre_timer_hostile,
 *   pre_timer_defense, attacker_threat_intel).
 *
 * Binding behavior: NONE. Webhooks must be bound explicitly by operator via
 * the Notifications panel UI. The migration does NOT create category↔webhook
 * bindings automatically — older iterations of this file did, but that
 * created unwanted Discord routing on upgrade installs (every existing webhook
 * suddenly received traffic from every enabled category). v2.0.0 default
 * routing posture: opt-in per binding. See the "NO AUTO-BIND" comment block
 * inside up() for the full rationale.
 *
 * Idempotency:
 *   - Categories use upsertCategory() helper: insert with full seed on first
 *     run; subsequent runs update display_name/description/sort_order but
 *     NEVER touch role_mention or enabled (operator may have configured them)
 *
 * For one-time legacy seed: events.structure_attack gets its role_mention
 * pre-populated from the legacy `esi_attack_role_mention` setting (if it
 * exists in the operator's settings table). Only on FIRST insert — subsequent
 * runs preserve whatever the operator has set.
 *
 * For fresh v2.0.0 installs: no existing webhooks → no bindings created.
 * Operator creates webhooks via Settings > Webhook Configuration, then binds
 * categories on the Notifications panel.
 *
 * Filename and class name match Laravel's filename → StudlyCase derivation
 * (`seed_structure_manager_v2_notification_categories` →
 * `SeedStructureManagerV2NotificationCategories`) so Laravel's Migrator
 * resolves the class by name without workarounds.
 */
class SeedStructureManagerV2NotificationCategories extends Migration {
    /**
     * All 18 categories in one declarative table. Each entry:
     *   [namespace, key, display_name, description, enabled, sort_order]
     *
     * Sort order grouping:
     *   upwell:   10 fuel, 20 magmatic_gas, 30 cyno_reagents
     *   events:   10 structure_attack, 20 structure_lifecycle, 30 fuel_events,
     *             40 services_offline, 50 sovereignty,
     *             60-63 pre_timer_armor/hull/sov/nodes (combat reminders),
     *             70-71 pre_timer_hostile/defense (manual op reminders),
     *             80 attacker_threat_intel
     *   pos:      10 fuel, 20 strontium, 30 lifecycle
     */
    private const CATEGORIES = [
        // ===== upwell =====
        ['upwell', 'fuel',                'Fuel',                    'Upwell structure fuel (warning + critical thresholds, final 1h alert)', true, 10],
        ['upwell', 'magmatic_gas',        'Magmatic Gas',            'Metenox dual-fuel: gas supply for moon drilling',                        true, 20],
        ['upwell', 'cyno_reagents',       'Cyno Reagents',           'Liquid Ozone (Cyno Generator) and Strontium Clathrate (Cyno Jammer) running low in the fuel bay. Polled every 10 minutes; dispatched on threshold crossing (warning / critical).', true, 30],

        // ===== events (ESI-driven structure events) =====
        ['events', 'structure_attack',    'Under Attack',            'Structure/skyhook under attack, shields down, armor down, destroyed',    true, 10],
        ['events', 'structure_lifecycle', 'Lifecycle',               'Anchoring, unanchoring, ownership transferred, skyhook deployed',         true, 20],
        ['events', 'structure_fuel_events', 'Fuel Events',           'Low power, high power restored, services offline, CCP fuel alerts',       true, 30],
        ['events', 'services_offline',    'Services Offline',        'Engineering Complex / Refinery service modules going offline (Manufacturing, Market, Cloning, Research, Moon Drill). Operators usually route to industry-team channels.', true, 40],
        ['events', 'sovereignty',         'Sovereignty',             'TCU / IHUB sov events: entosis capture started, sov reinforced, sov destroyed, command node spawned. Operators route to sov-ops channels.', true, 50],

        // ===== events.pre_timer_* (T-24h / T-6h / T-1h reminder pings) =====
        // Combat reminders (enabled by default; operator binds explicitly)
        ['events', 'pre_timer_armor',     'Pre-Timer Reminder: Armor Reinforced',     'Scheduled Discord reminders fired 24h / 6h / 1h before an armor-reinforce timer (hull cycle) expires. Default audience: FC + fleet leadership. Bind to a planning channel for T-24h heads-up + a fleet-ping channel for T-1h muster. Requires Manager Core.', true, 60],
        ['events', 'pre_timer_hull',      'Pre-Timer Reminder: Hull Reinforced',      'Scheduled Discord reminders fired before a hull-reinforce timer (final-defense window) expires. This is the last chance to save the structure — admins typically route to the most-attended fleet channel. Requires Manager Core.', true, 61],
        ['events', 'pre_timer_sov',       'Pre-Timer Reminder: Sov Reinforced',       'Scheduled Discord reminders fired before a sov-structure decloak (TCU / IHub / Sovereignty Hub). Sov-defense fleet form-up. Often routed to a dedicated sov-ops channel separate from upwell-defense channels. Requires Manager Core.', true, 62],
        ['events', 'pre_timer_nodes',     'Pre-Timer Reminder: Command Nodes Spawning', 'Scheduled Discord reminders fired before the sov capture phase begins (command-node spawn window). Entosis fleet needed. Often shares routing with the sov-reinforced category. Requires Manager Core.', true, 63],
        // Manual op reminders (disabled by default; operator opts in + binds)
        ['events', 'pre_timer_hostile',   'Pre-Timer Reminder: Hostile Op',           'Scheduled reminders for admin-created hostile manual ops (offensive timers operators added directly to the Structure Board). Disabled by default — enable if you schedule offensive ops via SM and want auto-pre-pings. Requires Manager Core.', false, 70],
        ['events', 'pre_timer_defense',   'Pre-Timer Reminder: Defense Op',           'Scheduled reminders for admin-created defense manual ops (defensive timers operators added directly to the Structure Board). Disabled by default — enable if you schedule defensive ops via SM and want auto-pre-pings. Requires Manager Core.', false, 71],

        // ===== events.attacker_threat_intel (opt-in zKB enrichment) =====
        ['events', 'attacker_threat_intel', 'Attacker Threat Intel (zKillboard)',     'Optional follow-up embed posted ~1-2s after each under-attack alert with the attacker\'s zKillboard threat profile (kills, top ship, danger ratio, tier). Async, never delays primary alert. Default off — opt in via Settings > Structure Events.', false, 80],

        // ===== pos (legacy) =====
        ['pos',    'fuel',                'Fuel',                    'Fuel blocks + sovereignty charter alerts for Player Owned Starbases',     true, 10],
        ['pos',    'strontium',           'Strontium',               'Strontium clathrate reinforcement alerts',                                true, 20],
        ['pos',    'lifecycle',           'Lifecycle',               'POS state changes (online, offline, reinforced)',                         true, 30],
    ];

    public function up(): void
    {
        if (!Schema::hasTable('structure_manager_notification_categories')) {
            Log::warning('[Structure Manager] v2 category seed: categories table missing — run 2026_05_01_000001_create_core_schema first.');
            return;
        }

        // ============================================================
        // 1. Resolve legacy esi_attack_role_mention for first-time seeding
        //    of events.structure_attack only. Subsequent runs preserve any
        //    operator-edited role_mention value.
        // ============================================================
        $legacyAttackRoleMention = null;
        if (Schema::hasTable('structure_manager_settings')) {
            $legacyAttackRoleMention = DB::table('structure_manager_settings')
                ->where('key', 'esi_attack_role_mention')
                ->value('value') ?: null;
        }

        // ============================================================
        // 2. Upsert all 18 categories. Helper preserves operator role_mention.
        // ============================================================
        foreach (self::CATEGORIES as [$ns, $key, $displayName, $description, $enabled, $sortOrder]) {
            $isAttackCategory = ($ns === 'events' && $key === 'structure_attack');
            $this->upsertCategory(
                $ns,
                $key,
                $displayName,
                $description,
                $enabled,
                $sortOrder,
                $isAttackCategory ? $legacyAttackRoleMention : null
            );
        }

        // ============================================================
        // 3. NO AUTO-BIND. Webhooks must be bound explicitly by operator.
        // ============================================================
        //
        // Earlier iterations of this migration auto-bound every enabled
        // category to every existing webhook (preserving the pre-v2.0.0
        // "webhook receives everything" routing behavior). That turned out
        // to be a surprising and unwanted side-effect for operators:
        //
        //   - On fresh greenfield installs: harmless (no webhooks exist
        //     yet, so auto-bind doesn't create anything).
        //   - On upgrade installs (operator has webhooks from v1.x or
        //     dev-4.0 testing): every webhook suddenly bound to 16+
        //     categories. Operator's Discord channels start receiving
        //     traffic they never explicitly authorized.
        //
        // v2.0.0 policy: webhooks fire ONLY when an operator explicitly
        // binds them to a category via the Notifications panel UI. Default
        // state = unbound. Operator opts in per binding. No surprise routing.
        //
        // For v1.x → v2.0.0 upgraders this means a brief post-migration
        // setup step (visit Notifications panel, bind webhooks to the
        // categories you want). For fresh installs this is the same
        // friction either way. Both are preferable to silent over-routing.

        Log::info(sprintf(
            '[Structure Manager] v2 category seed: seeded %d categories. NO auto-binding of webhooks — operator binds via the Notifications panel UI.',
            count(self::CATEGORIES)
        ));
    }

    /**
     * Insert a new category on first run, update display fields on subsequent
     * runs. NEVER touches role_mention / role_source / role_id after first
     * insert — operator may have configured these via the Notifications UI.
     */
    private function upsertCategory(
        string $namespace,
        string $key,
        string $displayName,
        string $description,
        bool $enabledDefault,
        int $sortOrder,
        ?string $legacyRoleMention = null
    ): void {
        $now = now();

        $existing = DB::table('structure_manager_notification_categories')
            ->where('namespace', $namespace)
            ->where('category_key', $key)
            ->first();

        if ($existing) {
            // Update non-role fields (operator may have flipped enabled too,
            // so don't touch that either — preserve operator state). The only
            // fields we update on subsequent runs are display_name, description,
            // and sort_order (which an operator wouldn't typically edit).
            DB::table('structure_manager_notification_categories')
                ->where('id', $existing->id)
                ->update([
                    'display_name' => $displayName,
                    'description'  => $description,
                    'sort_order'   => $sortOrder,
                    'updated_at'   => $now,
                ]);
            return;
        }

        // Fresh insert — seed enabled default, sort_order, and (for the legacy
        // attack category only) role_mention from the legacy setting.
        DB::table('structure_manager_notification_categories')->insert([
            'namespace'    => $namespace,
            'category_key' => $key,
            'display_name' => $displayName,
            'description'  => $description,
            'enabled'      => $enabledDefault,
            'role_mention' => $legacyRoleMention,
            'role_source'  => $legacyRoleMention ? 'manual' : null,
            'sort_order'   => $sortOrder,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('structure_manager_notification_categories')) {
            return;
        }

        $keysByNamespace = [
            'upwell' => ['fuel', 'magmatic_gas', 'cyno_reagents'],
            'events' => [
                'structure_attack', 'structure_lifecycle', 'structure_fuel_events',
                'services_offline', 'sovereignty',
                'pre_timer_armor', 'pre_timer_hull', 'pre_timer_sov', 'pre_timer_nodes',
                'pre_timer_hostile', 'pre_timer_defense',
                'attacker_threat_intel',
            ],
            'pos'    => ['fuel', 'strontium', 'lifecycle'],
        ];

        foreach ($keysByNamespace as $ns => $keys) {
            $categoryIds = DB::table('structure_manager_notification_categories')
                ->where('namespace', $ns)
                ->whereIn('category_key', $keys)
                ->pluck('id');

            if ($categoryIds->isNotEmpty() && Schema::hasTable('structure_manager_category_webhook')) {
                DB::table('structure_manager_category_webhook')
                    ->whereIn('category_id', $categoryIds)
                    ->delete();
            }

            DB::table('structure_manager_notification_categories')
                ->where('namespace', $ns)
                ->whereIn('category_key', $keys)
                ->delete();
        }
    }
}
