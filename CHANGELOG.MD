# Changelog

Below is a summary of all major updates, improvements, and fixes made to **Structure Manager**.
Each version entry lists key changes for easier reference and upgrade planning.

---

## 🛠️ Version 2.0.1 — *May 2026*

Patch release on top of v2.0.0 "The Ecosystem Era". UI/UX corrections caught while shaking down v2.0.0 in production, a small Help-page Version Status card ported from SeAT Broadcast, and a test-fixture upgrade that pairs with Mining Manager's upcoming v2.0.1 Metenox cargo readout.

### 🐛 Bug Fixes

**Critical Alerts — Metenox dual-fuel box layout**
The "Dual Fuel System Status" panel was rendering its header, fuel-blocks row, magmatic-gas row, and "Structure has no fuel" footer all on one horizontal line. The canonical `structure-manager.css` was applying `display: flex` to `.metenox-dual-fuel` for a compact widget elsewhere; the view-local CSS for Critical Alerts didn't override it, so canonical specificity won. The local rule now matches that specificity and explicitly sets `display: block`, restoring the intended stacked card layout.

**Diagnostic page — tab preservation across form POSTs**
Clicking any action button on a non-lazy diagnostic tab (Test Data, Notification Lab) used to bounce you back to Health Checks after the controller's `back()` redirect. Tab clicks now keep the URL in sync via `history.replaceState`, so form POSTs carry the `diag_tab` parameter in the Referer and land you back on the tab you were working in. Deep links to a specific tab also work cleanly now.

### ✨ Enhancements

**Version Status card on the Help page.**
New card sits right under the Plugin Info block on Help & Documentation. Shows the installed version (resolved via Composer's `installed.json`, falling back to `structure-manager.config.php` if Composer metadata is unavailable), the latest stable release from Packagist (cached 6 hours, fail-safe on Packagist outages), and an at-a-glance status badge: ✓ Up to date / ⚠ Update available / 🚀 Pre-release / 🌱 Development branch / — Unable to check. When an update is available a one-click "View release notes" link plus an upgrade-recipe block appear. Pattern ported verbatim from SeAT Broadcast where it was introduced first; the same shape will roll out to the other plugins.

### 📊 Documentation

**Notification Lab intro corrected.** Previous text claimed that leaving the Test webhook URL empty would route injected notifications to your real Discord channels with a `[TEST INJECTION]` banner. The code in `StructureEventHandler::dispatchTestNotification` does the safer thing: when the URL is empty, the webhook dispatch is skipped entirely (board upsert and EventBus publish still fire, but no Discord embed is sent). Intro now accurately describes both behaviours.

**Diagnostic Test Data tab — Metenox button description.** Updated to note the new `MoonMaterialBay` cargo seeding so operators know exactly what the button writes.

### 🧪 Test Fixtures

**`MoonMaterialBay` cargo seeded by the test-Metenox command.** `structure-manager:create-test-metenox` now writes a realistic mix of moon-ore stacks (Hydrocarbons, Atmospheric Gases, Evaporite Deposits, Silicates, Cobaltite, Euxenite) into the test Metenox's cargo bay so the Mining Manager v2.0.1 Metenox cargo readout renders end-to-end from a single artisan call. SM itself doesn't read `MoonMaterialBay` rows; this lives in SM because SM owns the test Metenox lifecycle. Default fill is ~90% so MM's "cargo full" alert fires on the fixture without further tweaking.

### 🚀 Upgrade

No migrations, no schema changes. Pure view, asset, controller, and service-version updates — restart the front container to pick up the blade + JS changes.

ESI User-Agent bumps to `SeAT-StructureManager/2.0.1` per CCP's third-party developer guidelines.

---

## 🎉 Version 2.0.0 — The Ecosystem Era — *May 2026*

Structure Manager v2.0.0 is the first public canonical release of the ecosystem-era plugin family. Where the original Structure Manager was a single-purpose fuel tracker, v2.0.0 sits at the centre of a connected plugin suite where Manager Core provides shared infrastructure, Mining Manager consumes Structure Manager's combat events for extraction-at-risk alerting, and SeAT Broadcast (`seat-discord-pings`) will consume the tactical-planning events for fleet calendar planning.

> **Mental model:** Structure Manager still works fully standalone. Every ecosystem feature is purely additive. Install Manager Core when you want faster detection and cross-plugin coordination; leave it out and Structure Manager keeps doing everything it used to, just at SeAT's native 15-20 minute notification cadence instead of ~2 minutes.

### 🚀 Headline Features

#### Plugin ecosystem integration
Structure Manager publishes a documented family of `structure.alert.*` events on Manager Core's EventBus:

| Event | Fires for | Tactical use |
|---|---|---|
| `structure.alert.shield_reinforced` | StructureLostShields, SkyhookLostShields | Defend at armor exit |
| `structure.alert.armor_reinforced` | StructureLostArmor | Final defense or evacuate |
| `structure.alert.destroyed` | StructureDestroyed, SkyhookDestroyed | After-action: clear timers, update intel |
| `structure.alert.anchoring_started` | StructureAnchoring, AllAnchoringMsg | Contest anchor before 24h completion |
| `structure.alert.sov_reinforced` | SovStructureReinforced | Fleet coverage for sov defense |
| `structure.alert.entosis_in_progress` | EntosisCaptureStarted, SovCommandNodeEventStarted | Counter-entosis NOW |
| `structure.alert.fuel_critical` | SM fuel poll (low fuel detected) | Schedule refuel run |
| `structure.alert.fuel_recovered` | SM fuel poll (refilled) | Clear calendar, cancel reminders |

Mining Manager already subscribes for extraction-at-risk alerts. SeAT Broadcast will subscribe (when its calendar build lands) for fleet-coverage calendaring with configurable pre-timer reminder pings.

#### Three-path ESI detection
Structure Manager now has three distinct paths for ESI notification detection, identifiable by the embed footer:

| Path | Footer label | Latency | When it runs |
|---|---|---|---|
| Manager Core fast-poll | `Fast Poll (Manager Core)` | ~2 minutes | MC installed + mode=auto |
| Manager Core sweep fallback | `SeAT Sweep (Manager Core)` | ~1 min after SeAT's 15-20 min bucket | MC installed + mode=auto (belt-and-braces) |
| Structure Manager native | `SeAT Native` | ~1 min after SeAT's 15-20 min bucket | MC absent OR mode=seat_native |

Detection mode is operator-configurable per install (Settings > Structure Events > ESI Detection Mode). Auto (default) uses MC fast-poll when available; `seat_native` opts out of MC fast-poll even when MC is installed; `off` disables detection entirely.

#### Attacker name resolution via public ESI
Discord embeds for combat events show real character / corporation / alliance names where possible (even for attackers who have never been a member of any corp on this SeAT install). A new `IdResolver` service uses a three-tier lookup chain:

1. SeAT's local info tables (`character_infos`, `corporation_infos`, `alliance_infos`) — instant
2. SeAT's `universe_names` secondary cache — instant
3. CCP's public ESI endpoint with 2-second timeout, results cached for 7 days

First-time attacker resolution adds ~250ms to alert dispatch; subsequent attacks by the same attacker resolve instantly from cache. Falls back gracefully to ID-only rendering on ESI outages.

#### Fuel Economics page (Manager Core required)
Dedicated page projecting structure fuel costs in ISK over weekly / monthly / quarterly / yearly windows. Per-system and per-structure breakdowns, daily-trend chart, cheapest-fuel-block suggestion with optimization-savings banner. Uses MC's pricing service; configurable market and price method per plugin in `Manager Core > Pricing Preferences`. Hidden from the sidebar when MC is not installed.

#### Operational security policy
Formal documentation of trust zones (SeAT auth + operator-controlled webhooks) and explicit rejection of feature classes that would leak tactical data outside those zones. ICS / iCalendar feed export, third-party data feeds, and similar will not ship: the reasoning is documented in-product (Help > Notifications > Operational Security) so the same conversation doesn't need to get relitigated for each request.

### 🛠 Operational Improvements

- **Corp scope defaults to "My Corporations"** — every list view (Upwell Structures, Control Towers, Fuel Reserves, Logistics Report, Critical Alerts) now defaults an admin to their *own* corporations instead of fire-hosing them with every corporation on the SeAT install. Each view carries a corporation-scope selector: "My Corporations" (default), individual corporations, and "All Corporations" (admin-only). Non-admins are unchanged — they were always, and still are, locked to their own corporations. Implemented as a `?scope=mine|all|<corpId>` request parameter resolved by a new `getScopedCorporations()` helper; the existing `getUserCorporations()` (actual access rights) is untouched, so the security boundary is unchanged — `scope=all` is honored only for admins.
- **Stale-structure hiding (Upwell only)** — when a corporation removes its ESI key, SeAT can no longer refresh that corp's structures: the row freezes, `fuel_expires` drifts months into the past, and the UI used to render a nonsensical "−142 days remaining" critical alert (which also inflated the critical count and the "total fuel needed" figure). Upwell structures whose `updated_at` is older than a configurable threshold are now hidden from the Upwell Structures list, Critical Alerts, and the Logistics Report. The threshold lives in **Settings > Stale Structure Visibility** (default 30 days; set to 0 to disable hiding). Resolved centrally by `FuelThresholds::staleStructureCutoff()`. **POS towers are exempt:** a `corporation_starbases` row only changes when the tower's state or settings change, so a stable or offline POS legitimately freezes its `updated_at` even while ESI polling is healthy; hiding on row age would drop healthy towers. POS activity is read from the tower state (online / offline / reinforced) instead.
- **Mode-change worker restart warning** — the ESI detection mode dropdown now has an explicit warning that flipping modes requires a worker restart (because the in-memory notification registry is per-process). A "Why isn't there a re-register button?" collapsible explains the per-process registry vs. DB-persistent pricing preference distinction. Settings save now flashes an explicit reminder when the mode actually changes.
- **Manager Core Plugin Bridge diagnostic improvements** — the MC admin dashboard shows worker-context registry snapshots (handlers registered, key pool size, plugins contributing) plus per-plugin integration badges (pricing types, EventBus subs, ESI handlers, recent publishes) and last-activity timestamps. Silent failures of cross-plugin integration become visible at a glance.
- **`cleanup-test-data` command** — symmetric teardown for everything the `create-test-*` and `inject-test-notification` commands create. `--dry-run` to preview, `--force` to skip confirmation. Bounded by `TestDataGenerator` safe ID ranges so real CCP data cannot be touched.
- **Better diagnostic page** — MC-aware detection-mode panel, registered handler status, per-source notification counts, pricing integration status.

### 📦 Architecture & Ecosystem

- **`structure.alert.*` event publishing** — the full 8-event family (shield_reinforced, armor_reinforced, destroyed, anchoring_started, sov_reinforced, entosis_in_progress, fuel_critical, fuel_recovered) registered in Manager Core's Topics registry with idempotency templates and required-field declarations.
- **Tactical-planning event payload contract** — every `structure.alert.*` event ships a uniform payload with `source_plugin`, `schema_version`, `event_id`, `event_type`, `category_group`, `severity`, `corporation_id`, `structure_id`, `structure_name`, `system_id`, `timer_ends_at`, attacker context (resolution_status, character/corporation/alliance fields), and a Structure Board deeplink. Subscribers calendar events with non-null `timer_ends_at`; null-timer events are "react NOW" alerts.
- **Unified timer extraction helper** (`computeTimerEndsAt`) handles all three CCP timer encodings (`corporation_structures.state_timer_end`, Microsoft FILETIME `decloakTime` for sov, CCP 100-ns ticks for `timeLeft` on anchoring). Subscribers see one uniform ISO 8601 field regardless of which CCP encoding the underlying notification used.
- **TypeIdRegistry consolidation** — POS tower metadata (42 towers with size/faction/modifier), POS_BASE_FUEL_RATES per size, SERVICE_FUEL_RATES for Upwell modules, FUEL_BLOCK_* constants, MAGMATIC_GAS, STRONTIUM, CHARTER_* (6 charters), UPWELL_TYPE_IDS (13 types). Hardcoded values for fields where SeAT's SDE has known errors (notably Tenebrex Cyno Jammer ID 37534 vs. SDE's 35839, faction-tower fuel modifiers).

### 📊 Documentation

- **In-app help completely rewritten for v2.0.0** — new "What's New in v2.0.0 — The Ecosystem Era" section as the marketing pitch with companion-plugin listing; ESI detection paths documented with testing recipes for each path; opsec policy section explicitly rejecting calendar export and external data feeds; tactical-planning event contract documenting payload schema + subscriber implementation guide; IdResolver subsection explaining the three-tier lookup chain.
- **All previously-undocumented commands now documented** — `process-notifications`, `track-structure-presence`, `publish-timer-schedule-events`, `prune-structure-board-timers`, `create-test-upwell-structures`, `inject-test-notification`, `cleanup-test-data`. Grouped into "Operational Background Jobs" (cron-driven) and "Test-Data Commands" (operator-invoked).
- **Plugin naming standardized to display names** in user-facing docs (`SeAT Broadcast` not "Pings", `Manager Core` not "MC"). Package strings appear in brackets on first prominent reference for install / debug context.

### ⚠️ Upgrade Notes

- **Backwards-compatible.** All migrations are forward-only and idempotent. No data loss, no manual reconfiguration required for existing installs.
- **Manager Core is optional but recommended.** Without it, Structure Manager works exactly as it did before (15-20 min detection cadence via SeAT's native notification bucket). With it, you get ~2-minute detection plus the cross-plugin integrations.
- **Changing the ESI detection mode requires a worker restart** to apply uniformly. The settings page now flashes an explicit reminder when the mode changes. The in-memory notification registry is per-process; only a fresh boot can re-evaluate which registrations apply.
- **Worker container must have the same plugin code as front.** Run `composer update` (or your container's equivalent) on every container that boots Laravel: `front`, `worker`, `scheduler`. Otherwise the worker may have stale code that doesn't match the front-end UI.

### 🐳 Upgrading via SeAT Docker

```bash
# 1. Edit your seat-docker .env file. Add mattfalahe/manager-core to the
#    SEAT_PLUGINS list (or whatever your template names the plugin list
#    env var) IF you want the ecosystem features. Skip this step if you
#    want to stay standalone.
nano .env

# 2. Stop the stack (pass all three compose files)
docker compose -f docker-compose.yml -f docker-compose.mariadb.yml -f docker-compose.traefik.yml down

# 3. Bring it back up. The entrypoint composer-installs the new plugin
#    list, runs all pending migrations, and SeAT comes up ready.
docker compose -f docker-compose.yml -f docker-compose.mariadb.yml -f docker-compose.traefik.yml up -d

# 4. Watch the front container boot (optional but reassuring)
docker compose -f docker-compose.yml -f docker-compose.mariadb.yml -f docker-compose.traefik.yml logs -f front
```

### 🚀 Additional v2.0.0 features

These features landed during the dev-4.0 development cycle and ship together as part of v2.0.0. The "Headline Features" section above covers the core architecture; this section adds the broader feature set built on that foundation.

#### Pre-Timer Reminder Pings (T-24h / T-6h / T-1h)

Scheduled Discord reminders fired 24 hours, 6 hours, and 1 hour before a structure timer expires. Lets fleet leadership plan doctrine + roster on a predictable rhythm — *tomorrow plan / tonight prepare / hour-out ping* — rather than scrambling when the under-attack alert fires.

Six dedicated notification categories (`events.pre_timer_armor`, `_hull`, `_sov`, `_nodes`, `_hostile`, `_defense`) — operator routes each event type independently via the Notifications panel. Combat reminders enabled by default; manual-op reminders opt-in.

**Manager Core required** for the EventBus subscription that drives reminders. Without MC, under-attack alerts still fire via SeAT native — only the scheduled reminders are gated by MC.

#### Attacker Threat Intel (zKillboard enrichment) — opt-in

Optional follow-up Discord embed posted ~1-2 seconds after each under-attack alert with the attacker's zKillboard profile: kill count, top-flown ship, danger ratio, gang ratio, and a synthesized threat tier ("Professional", "Active", "Casual", "Dormant", "Cold"). FCs decide fleet form-up based on threat assessment.

**Async** — never delays the primary alert. **Opt-in by design** — disabled by default because it makes external HTTP calls to zKillboard. Enable via Settings > Structure Events + bind the `events.attacker_threat_intel` category. **Standalone** (no MC required). 7-day cache; only public attacker character IDs leave SeAT (opsec-safe).

#### Under-attack board behavior + escalation cleanup

`StructureUnderAttack` notifications create a board row with an "UNDER ATTACK" badge and 15-minute auto-dismiss window (matching CCP's auto-repair behavior for transient attacks). If the attack escalates to `StructureLostShields`, the under_attack row is proactively dismissed in favor of the proper `reinforce_armor` row with the real `state_timer_end`. No more ghost rows lingering after a transient attack.

Same fast-dismiss pattern applies to `EntosisCaptureStarted` rows — 60-min synthetic deadline (covers ~2 entosis cycles) instead of the 4-hour grace, so stale "ENTOSIS ACTIVE" rows clear from the board promptly.

#### FINAL TIMER awareness

Structures with no separate hull reinforce timer — **medium Upwell** (Astrahus / Raitaru / Athanor), **FLEX navigation** (Ansiblex / Pharolux / Tenebrex), and **Equinox single-cycle** structures (Metenox / Orbital Skyhook) — now show a red `⚠ FINAL TIMER` indicator on the Structure Board, in under-attack Discord embeds, and in pre-timer reminders. Makes the "this is the last fight" stakes visible at a glance.

EventBus `structure.alert.armor_reinforced` payload includes `is_final_timer: bool` so cross-plugin subscribers (Mining Manager extraction_at_risk, future Pings calendar) can prioritize accordingly. Backward-compatible — old subscribers ignore unknown fields.

#### Notification routing policy: explicit opt-in

The notification category schema ships with **18 categories** across three namespaces (`upwell.*` / `events.*` / `pos.*`). Each has its own master toggle, default role mention, and webhook bindings via the Notifications panel.

**Default state on fresh install:** all categories created with sensible enabled/disabled defaults, but **no webhooks are auto-bound**. Operators bind webhooks explicitly per category via the UI. Prevents surprise routing where existing webhooks would otherwise inherit every category's traffic post-upgrade.

#### Discord role picker

Inline slide-down role picker on every role-mention input across SM. Detects installed Discord-role provider packages (`mattfalahe/seat-discord-pings`, `warlof/seat-discord-connector`, etc.) and presents a deduplicated, color-coded role list. Operators no longer need to copy raw Discord snowflake IDs.

#### Notification Routing Map

A read-only **Routing Map** tab in Settings shows, at a glance, where every notification category delivers and which Discord role it pings, computed from the live configuration. Grouped per namespace (Upwell / Structure Events / POS), it lists each category, its bound webhooks, the corporation scope, and the resolved role mention, tagged with the precedence tier that won (`L1` binding override / `L2` category default / `L3` webhook legacy). Categories that are enabled but bound to no webhook are flagged as "firing nowhere"; `pre_timer_*` categories show a "needs Manager Core" reason when MC is absent (their reminders ride MC's EventBus).

Role-mention inputs across the Notifications panel now also **translate raw Discord role IDs into readable names**: a `<@&1234...>` value resolves to `@Fleet Commander` (with the role's colour) when the ID is known to an installed Discord role source, and is flagged when it is not. The precedence ladder is labelled **L1 / L2 / L3** consistently across the legend, the role inputs, and the Routing Map.

#### Fuel Forensics — event reclassification (Tier 1)

The v1.x fuel tracker treated every negative bay delta as "refuel" and every positive bay delta as "consumption". A 5,000-block theft looked identical to a 4,032-block legit refuel. v2.0.0 reclassifies every fuel-tracking poll into one of **eight distinct event types**:

| Event type | Detection rule |
|---|---|
| `consumption_normal` | Bay went DOWN ≤ expected consumption × 1.15 (within ±15% of expected) |
| `consumption_anomaly` | Bay went DOWN 15-50% more than expected (likely service activation) |
| `refuel_internal` | Bay went UP AND reserves went DOWN by ≥80% of bay gain → moved from CorpSAG |
| `refuel_external` | Bay went UP AND reserves did NOT change → fuel added from outside |
| `withdrawal_bay` | Bay went DOWN by ≥1.5× expected consumption |
| `withdrawal_reserves` | CorpSAG dropped ≥500 without matching bay gain → fuel left the corp |
| `unexplained_gain` | Bay went up with no matching reserve drop |
| `unclassified` | First snapshot or no baseline yet |

**Recent Fuel Records** in the structure detail page now renders color-coded badges per row (green refuel, red withdrawal, gray consumption, yellow anomaly). Each badge has a tooltip explaining the classification.

Hard ESI limit (documented in-product): ESI does not expose actor identity for asset moves. Classification is based on quantity deltas, not who-did-it.

#### Fuel Forensics — suspect narrowing (Tier 2)

For each `withdrawal_*` event, an async `WithdrawalForensicsJob` builds a per-event candidate list of corp members who **collaterally match** four signals:

| Signal | Source | Weight |
|---|---|---|
| Online during ±1h window | `corporation_member_trackings` | +30 |
| Personal asset gain match | `character_assets` | +40 |
| Has any corp title | `corporation_member_titles` | +20 |
| Sold matching fuel ≤48h after event | `character_wallet_transactions` | +30 |

Scores bucket into **HIGH / MEDIUM / LOW** confidence (≥60 / 30-59 / 10-29). Rows below 10 are dropped as noise.

Withdrawal rows on the structure detail page gain an expandable forensics panel listing candidates with confidence badges, score bars, signal pills, and zKB profile links. The panel header explicitly states: *"ESI does not expose who moved this fuel — these candidates are inferred from collateral SeAT data."* False positives are inevitable (logistics alts look like thieves). The system catches lazy thieves; careful market-alt thieves escape.

Tiers 3-5 (per-structure Z-score baselines, ISK valuation via MC, stewardship leaderboard) deferred — see internal roadmap.

#### External Reserves Tracking (NPC stations + foreign Upwells)

v1.x only saw CorpSAG fuel inside corp-owned Upwell structures. A corp staging refuel hauls in Jita 4-4 (NPC station Office rental) or in a friendly alliance-mate's Fortizar (foreign Upwell with Office rental) had those reserves completely invisible to the plugin. v2.0.0 sweeps `corporation_assets` corp-wide and resolves CorpSAG fuel inside Office containers regardless of whether the container sits in an NPC station or a foreign Upwell — using `staStations` + `mapDenormalize` for NPC stations and `universe_structures` + `mapDenormalize` for foreign Upwells.

**Fuel Reserves** page now groups by solar system and shows external holdings as "External" badged cards alongside owned Upwells. Each external location resolves to its real EVE name (e.g. *"Jita IV - Moon 4 - Caldari Navy Assembly Plant"* for NPC, or *"3AE-CP - Cosmos Collective Cat Cafe"* for foreign Upwells) and its actual solar system.

**Scope:** NPC stations + foreign Upwells. The only verdict skipped is `unknown_location` (resolution failure — location_id doesn't match any structure or station), since rendering "Unknown Location" cards is UI noise with no actionable info.

**Depletion reconciliation:** every `track-fuel` poll runs a corp-wide reconciliation pass after both sweeps complete. For every `(structure_id, fuel_type, location_flag)` tuple with a positive latest quantity, it verifies the fuel is still physically present in `corporation_assets` (checking both direct placement and nested-Office traversal). Tuples that no longer match insert a depletion row (`reserve_quantity=0, quantity_change=-previous, is_refuel_event=true`) to close out the audit trail. Without this, the latest snapshot of a CorpSAG that had fuel moved out would stay at the old positive quantity forever — and a move to a new location would render as double-counted fuel in two places. Depletion rows appear correctly on the Fuel Withdrawals tab as proper withdrawal events with negative `quantity_change`; UI layers filter `reserve_quantity > 0` so the system view doesn't show "0 blocks" cards.

**Phantom withdrawal safety net** — the original Fuel Withdrawals page would show endless hourly `−57,000` rows from a CorpSAG that hadn't actually moved any fuel. The smoking-gun diagnostic from an affected install showed eleven separate physical stacks of Hydrogen Fuel Block (`58,000 + 10 × 1,000`) sitting in a single CorpSAG3 inside an Office container. The root cause turned out to be a dedup mismatch in `trackStructureReserves` + `trackExternalReserves`:

> EVE does NOT auto-merge identical items moved into CorpSAG hangars by different characters or at different times. A corp can easily end up with N separate physical stacks of e.g. Hydrogen in the same CorpSAG. Pre-v2.0.0 the tracker iterated each physical stack separately and compared each one to a per-tuple `lastReserve`. Result: every poll the dedup memory bounced between the 58k stack and the 1k stacks, writing a mirror pair of rows (`+57,000` / `−57,000`) into `structure_fuel_reserves` every cycle even though no fuel had actually moved.

Fixed in four layers:

- **Write-side aggregation** — both `trackStructureReserves` and `trackExternalReserves` now group the raw `corporation_assets` rows by `(fuel_type_id, location_flag)` (or `(actual_location_id, fuel_type_id, location_flag)` for external) and SUM the stack quantities into a single logical reserve before the `lastReserve` comparison. One row per tuple per poll, regardless of how many physical stacks the corp has lying around. Stack item_ids are preserved in `metadata.stack_item_ids` for audit.
- **Depletion-reconciliation debounce** — `reconcileDepletedReserves` requires absence across **two consecutive polls** before committing a depletion row. Streak counter lives in Redis (`sm:depletion_absence:<corp>:<structure>:<fuel>:<flag>`, TTL 6h), cleared on detection. Defends against SeAT's `corporation-assets` refresh being non-atomic (DELETE-then-INSERT, no transaction) — a poll that lands inside the refresh window now can't write a phantom by itself.
- **Display-side filter** — `FuelReserveController::detectPhantomPairs` hides two phantom signatures from the UI: (A) any `is_refuel_event` row that has a mirror `+N` companion in the same tuple within 5 minutes (the dual-stack signature); (B) any `depletion_reconciliation` row that is followed within 4 hours by a return-to-positive row (the reconciliation-race signature). Historical phantom rows from before the write-side fix shipped will simply stop appearing in the UI; they remain in the DB for audit until the operator runs the cleanup command.
- **Cleanup command** — `php artisan structure-manager:cleanup-phantom-withdrawals` does two SQL self-joins to detect two distinct phantom signatures: **(A) symmetric mirror pairs** (`+N` and `−N` rows in the same tuple within `--window-seconds=300`, both halves deleted), and **(B) first-poll phantoms** (a `−N` row whose mirror is the very first observation of the tuple, with `quantity_change=NULL` — paired by the mathematical fingerprint `A.previous_quantity = B.reserve_quantity`, only the phantom side deleted, the first-observation audit row kept). Default is dry-run; pass `--force` to actually delete. Optional `--corp=ID` scopes to a single corporation for multi-corp installs. Explicitly excludes `depletion_reconciliation` rows from both pair-detection self-joins (uses LIKE on the raw metadata string to survive the legacy double-encoded JSON) so legitimate move-out audit rows are never wiped just because someone happened to refuel within the window.

- **Automatic cleanup on upgrade** — migration `2026_05_01_000006_cleanup_phantom_dual_stack_pairs` runs the same SQL logic unconditionally during the v1.x → v2.0.0 upgrade. SeAT Docker auto-applies migrations on container restart, so operators don't need to manually invoke the artisan command — the Fuel Withdrawals UI is clean on first load after upgrade. Scoped to the last 365 days; capped at 100,000 rows per signature for migration speed (operators with pathological backlogs get a log warning telling them to re-run the manual command). Idempotent — re-running on a clean install is a no-op. Forward-only.

**Metadata double-JSON-encoding fix** — the `StructureFuelReserves` model has `protected $casts = ['metadata' => 'array']`, which means Laravel handles JSON-encoding on save and decoding on read. Three call sites in `TrackFuelConsumption` (`trackStructureReserves`, `trackExternalReserves`, `reconcileDepletedReserves`) were passing `json_encode([...])` to the `metadata` column, which Laravel then encoded AGAIN. The column ended up holding a JSON string that wrapped a JSON object (`"{\"tracking_method\":\"nested_office\",...}"`), which made `JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.tracking_method'))` return NULL in MariaDB and silently broke downstream queries — cleanup, diagnostic forensic candidate lookup, and audit reports. Now passes the array directly so the cast can do its job.

#### Fuel Withdrawals — pagination + division names

The Fuel Withdrawals table on the Reserves page was unpaginated, returning the full 30-day event list as a single JSON blob. For active alliance installs with hourly refuel cadence this could be thousands of rows. Now:

- **Server-side pagination** with operator-selectable page size (25 / 50 / 100 / 200 per page).
- **First / Prev / Next / Last** controls + row-count info ("Showing 1-50 of 1,247 withdrawals").
- **"From Location" column renamed to "From Hangar"** and shows both the `CORPSAG3` flag AND the in-game custom hangar name (resolved from `corporation_divisions`).
- **"Quantity Withdrawn" renamed to "Quantity Change"** and now shows the signed delta (green `+N` for refuels, red `−N` for withdrawals) plus a `before → after` sub-line. A row that reads `−57,000` with `58,000 → 1,000` underneath makes it instantly obvious whether a large number is a real movement or a phantom snapshot. A `Reconciled` badge marks synthetic rows written by the corp-wide depletion reconciliation pass (fuel moved out, last positive snapshot closed to zero) so operators can distinguish them from primary ESI-detected withdrawals.
- Auto-refresh preserves the operator's current page.

#### Settings UI — hangar names in Reserves Tracking

The Reserves Tracking section of Settings listed hangars as plain `Division 1..7`. Operators had to remember which division corresponded to which in-game hangar tab. Each checkbox now shows three stacked lines:

```
☑  Division 1
   FUEL HANGAR              ← in-game name (resolved from corporation_divisions)
   CorpSAG1                  ← ESI location_flag (monospace)
```

When the operator straddles multiple corps with different names for the same division, all distinct names render joined with " / ".

#### Webhook Delivery Telemetry

The #1 ops question — *"did my Discord webhook actually fire?"* — is now visible at a glance on the Health Checks diagnostic tab without grepping `laravel.log`. v1.x dispatched via raw `Http::post()` and threw the result on the floor; v2.0.0 routes every dispatch through `WebhookDeliveryService` which writes a telemetry row to `structure_manager_webhook_deliveries` capturing:

- HTTP status code (0 = connection failure / timeout)
- Success flag
- Round-trip duration in ms
- Notification category that triggered the dispatch
- Payload summary (e.g. *"structure_under_attack: 3AE-CP Fortizar"*)
- Error message on failure (truncated to 500 chars)

**Diagnostic surface** — new *"Webhook Delivery Health (Last 24h)"* section on the Health Checks tab. Per-webhook table showing status badge, attempts count (with ok/fail breakdown), 24h success rate (color-coded ≥95% green / ≥50% yellow / <50% red), average response time, last attempt time, last failure with HTTP code + error preview.

**Retention** — 30 days, pruned by the existing daily `structure-manager:cleanup-history` 3 AM tick. Active alliance installs producing thousands of dispatches per day stay bounded at <100k rows.

**Refactored 5 dispatchers** to use the centralized service: `NotifyPosLowFuel`, `NotifyUpwellLowFuel`, `StructureEventHandler`, `PreTimerReminderHandler`, `DispatchAttackerThreatIntel`.

#### Diagnostic Page Overhaul

Comprehensive improvements to the `/diagnostic` admin page (10 tabs, admin-only, intentionally not in sidebar):

- **Default landing tab is always Health Checks.** Pre-fix the JS restored the last-clicked tab from localStorage — operators who clicked into Test Data once kept landing there. Now every fresh visit opens on Health Checks; URL deep-links (`?diag_tab=X`) still win.
- **Lazy-load for heavy tabs** — System Validation, Settings Health, and Data Integrity now follow the same pattern Fuel Trace has used since launch. Tab data only computes when its tab is active. Cold-load is much faster (Health Checks only); heavy tabs pay on first click then cache for repeat visits. Cache TTLs: 1800s / 300s / 30s respectively.
- **Per-tab intro boxes** — every tab opens with a uniform "What this tab does / When to use / Heads up" intro. Diagnostic page is intentionally NOT in Help & Documentation, so tab intros are the only place operators learn each surface's purpose.
- **Hidden deprecated-at-default settings** — Settings Health audits deprecated keys (e.g. `pos_webhook_url`, `pos_webhook_enabled`) but suppresses them from the rendered list when at default. They'd only show if an operator accidentally set a value, with a loud WARN. A small footer lists what's been hidden.
- **N+1 fix + 60s caching** on the heavy `check*` methods (ESI coverage, notification state, polling state, pricing integration, webhook delivery). Eliminated synchronous SDE lookups in the test-lab loop.
- **Notification Lab gains the danger-zone warning banner** that Test Data has — Lab is arguably MORE dangerous because forgetting to set the Test webhook URL sends fake injections to real Discord channels.
- **Fuel Trace v2 surfaces** — two new steps for Upwell structures: event classification breakdown (last 30 polls) and forensic candidates list (for the latest withdrawal event).
- **Sample Alert form moved** from Notification Testing → Notification Lab. Testing now contains only real-job triggers; Lab contains all synthetic-data dispatch paths.

### 📦 Migration

All v2 schema changes ship as **six forward-only migrations** with the `2026_05_01_*` date prefix:

- `2026_05_01_000001_create_structure_manager_v2_core_schema` — all v2 table CREATE statements, drops the dormant `structure_manager_esi_key_holders` table (Manager Core owns the key pool now)
- `2026_05_01_000002_seed_structure_manager_v2_notification_categories` — seeds all 18 categories with sensible defaults
- `2026_05_01_000003_run_structure_manager_v2_forward_cleanups` — forward-only data cleanups (legacy POS strontium board rows, stale mislabeled under_attack rows from pre-v2 mapping)
- `2026_05_01_000004_extend_structure_fuel_schema_for_v2` — Part A: adds `event_type`, `expected_consumption`, `unexplained_delta`, `reserves_delta` columns to `structure_fuel_history` + creates `structure_fuel_event_candidates` table for Tier 2 forensics. Part B: adds `location_type`, `location_name`, `location_system_id`, `location_system_name` columns to `structure_fuel_reserves` (backfills existing rows as `owned_structure`)
- `2026_05_01_000005_create_webhook_delivery_telemetry_table` — creates `structure_manager_webhook_deliveries` for per-dispatch telemetry (status code, duration, success, category, error message)
- `2026_05_01_000006_cleanup_phantom_dual_stack_pairs` — forward-only cleanup of phantom mirror-pair + first-poll-phantom rows in `structure_fuel_reserves` from the pre-v2.0.0 dual-stack-not-aggregated bug. Runs automatically during the upgrade. Capped at 100k rows per signature for migration speed

Forward-only, additive, defensively guarded with `Schema::hasTable` / `Schema::hasColumn` checks. Upgrades from v1.x preserve all settings, webhooks, fuel history, POS data, and structure presence tracking. The legacy `esi_attack_role_mention` setting is auto-migrated to the new `events.structure_attack` category role_mention on first run.

### 🤝 Companion Plugins

- **Manager Core** (`mattfalahe/manager-core`) — the optional hub. Adds 10-15x faster ESI detection via the shared key holder pool, MC pricing service for the Fuel Economics page, EventBus for cross-plugin events, SDE service, REST API, comprehensive diagnostic dashboard.
- **Mining Manager** (`mattfalahe/mining-manager`) — already integrated. Subscribes to `structure.alert.*` events for extraction-at-risk alerts on reinforced moon-mining refineries. Uses MC's pricing service and the shared director key pool.
- **SeAT Broadcast** (`mattfalahe/seat-discord-pings`) — already integrated with Manager Core's EventBus. A planned calendar build will consume Structure Manager's tactical-planning events to render a fleet-coverage calendar with configurable pre-timer reminder pings (24h / 2h / 30min before reinforce / anchoring timers).
- **Corp Wallet Manager** (`mattfalahe/corp-wallet-manager`) — integrated with Manager Core for cross-plugin wallet events.
- **HR Manager** (`mattfalahe/hr-manager`) — integrated with Manager Core for cross-plugin member-lifecycle events.

### Limitations & honest disclosure

- **Calendar / ICS export will not ship.** Timer data is tactical intelligence in EVE; exporting it to third-party calendar services (Google, Apple, Outlook) is an opsec failure mode. The reasoning is documented in-product. The in-app Structure Board + the existing Discord webhook channels are the operator-controlled trust zones for timer info.
- **Fast-poll detection requires director characters** in Manager Core's shared ESI key pool. Without enrolled directors, MC's poll exits cleanly each minute with nothing to do; SM's native sweep fills in.
- **First-time attacker resolution adds ~250ms** to alert dispatch when the attacker isn't in any local cache. Acceptable for a critical security ping. Subsequent attacks by the same pilot resolve instantly. Worst case (CCP's public ESI is down): the embed renders the ID-only "Pilot ID #N (name not cached)" form exactly as before.

---

## Version 1.0.11 — *November 2025*

### 🐛 Bug Fixes

**Fixed notifications for unanchored/removed POSes**

**Issue:** Users received critical fuel notifications for POSes that had already been unanchored or removed from the game. The POS was not visible in the plugin UI or SeAT, but notifications continued with stale data showing "Last updated 1h ago", "11h ago", etc.

**Root Cause:** The notification job queried historical records (`starbase_fuel_history`) without verifying if the POS still existed in the current corporation holdings (`corporation_starbases`). When a POS was unanchored, it was removed from ESI data but old history records with state=4 (online) remained, triggering repeated notifications.

**What's Fixed:**

- **Added POS existence verification** - Notification job now validates POSes exist in `corporation_starbases` before sending alerts
- **Primary query filter** - Added `whereExists` clause to exclude POSes no longer in corporation holdings
- **Secondary safety check** - Per-POS existence verification catches race conditions
- **Orphaned POS cleanup** - Tracking job now detects and marks removed POSes as unanchored (state=0)
- **Notification state reset** - Orphaned POSes have all notification tracking fields cleared

**Technical Changes:**
- `NotifyPosLowFuel.php`: Added DB facade import and existence checks
- `TrackPosesFuel.php`: Added `cleanupOrphanedPoses()` method that runs after each tracking cycle

---

## Version 1.0.10 — *November 2025*

### Added
- Multiple webhook support with per-webhook configuration (corporation filters, location filters, role mentions)
- Zero strontium behavior handling for POSes (proper critical alerts and status detection)
- Status-based POS notifications (good → warning → critical transitions with optional reminders)
- Final 1-hour alert for POSes before going offline
- `simulate-consumption` command for rapid fuel consumption testing
- Webhook test button in settings for connectivity verification
- Enhanced reserve tracking with nested Office container support

### Changed
- Renamed "Recent Refuel Events" section to "Fuel Withdrawals" for clarity
- Fuel Withdrawals now only shows Upwell structures (excludes POS and Metenox)
- Enhanced refuel event detection logic
- Updated help documentation with corrected command schedules
- `track-fuel` command now documented as handling both fuel bays and reserves
- `analyze-consumption` command now includes `--structure` and `--corporation` options

### Fixed
- POS and Metenox entries incorrectly appearing in Fuel Withdrawals section
- Incorrect command schedules in help documentation (analyze-consumption)
- Duplicate "Track Fuel Reserves" section in help documentation
- Zero strontium alert triggers not working correctly

### Documented
- Added missing `cleanup-history` command documentation
- Added missing `simulate-consumption` command documentation
- Removed confusing duplicate command sections
- Added command options and usage examples throughout
- Corrected all command schedules (hourly at :15/:30, every 10 minutes, etc.)

---

## Version 1.0.9 — *November 2025*

### 🐛 Bug Fixes

**Fixed POS State Recognition and Critical Alerts Filtering**

**Issue:** POSes were not appearing correctly in critical alerts and notifications regardless of their state.

**What's Fixed:**

- **Fixed POS state recognition** - Plugin now correctly identifies POS states (unanchored, offline, onlining, reinforced, online, unanchoring)

- **Critical alerts page filtering** - Critical alerts page will now only show POSes that are **online** or **reinforced** and actively consuming fuel. POSes in other states (offline, unanchored, etc.) are excluded from alerts as they do not consume fuel.

- **Notification filtering** - Discord/Slack notifications will now only be sent for POSes in **online** or **reinforced** states. Any other state will be ignored.

- **Added POS state badge** - Control tower list now displays a badge showing the current state of each POS for better visibility.

- **Added state validation checks** - Enhanced validation to ensure state recognition is accurate throughout the plugin.

**State Behavior:**
- **Online (state 4)** - POS is operational and consuming fuel → Alerts enabled
- **Reinforced (state 3)** - POS is in reinforced mode and consuming fuel → Alerts enabled
- **Offline (state 1)** - POS is anchored but offline → Alerts disabled (no fuel consumption)
- **Unanchored (state 0)** - POS is not deployed → Alerts disabled
- **Onlining (state 2)** - POS is coming online → Alerts disabled
- **Unanchoring (state 5)** - POS is being unanchored → Alerts disabled

---

## Version 1.0.8 — *November 2025*

### 🎉 Major Features

#### Legacy Player Owned Starbases (POS) Support
Complete fuel management system for legacy Player Owned Starbases (Control Towers), bringing the same comprehensive tracking capabilities to POS towers that Upwell structures already enjoy.

**Multi-Resource Tracking**
- **Fuel Blocks**: Full consumption tracking based on tower size (Small: 10/hr, Medium: 20/hr, Large: 40/hr)
- **Strontium Clathrates**: Defensive resource monitoring with reinforcement timer calculations
- **Starbase Charters**: Automatic detection and tracking for high-security space (1/hour consumption)
- **Faction & Officer Towers**: Full support for fuel efficiency bonuses

**Smart Security Space Detection**
- Automatically detects system security level (High-Sec, Low-Sec, Null-Sec, Wormhole)
- Enables charter tracking only in high-security space
- Handles both sovereign and NPC null-sec correctly
- No manual configuration required

**Limiting Factor Detection**
- Identifies which resource (fuel, strontium, or charters) will run out first
- Visual `[LIMITING FACTOR]` badges highlight priority resources
- Helps optimize hauling operations and prioritize refueling efforts
- Separate status tracking for each resource type

**Real-Time Monitoring**
- POS fuel tracking every 10 minutes (vs 1 hour for Upwell structures)
- Daily consumption analysis at 01:00 AM
- 90-day historical data retention
- Automatic refuel event detection

**Comprehensive Dashboard**
- Dedicated "Control Towers" page with filterable table
- Color-coded status indicators (Critical/Warning/Normal/Good)
- Multiple resource display (fuel, strontium, charters) in single view
- Days/hours remaining calculations for each resource
- Corporation filtering for multi-corp management
- System security status display

**Detailed POS Pages**
- Individual detail pages for each POS with full analytics
- Separate resource cards for fuel blocks, strontium, and charters
- Historical consumption graphs (90 days)
- Refuel event timeline
- Reserve status from corporation hangars
- Fuel projections (7, 14, 30, 60, 90 days)
- Critical alerts with actionable information

#### Discord & Slack Webhook Notifications
Advanced notification system for POS fuel alerts with intelligent status-based alerting and customizable intervals.

**Rich Webhook Integration**
- Discord and Slack webhook support
- Color-coded embeds (Red: Critical, Yellow: Warning)
- Detailed resource information in structured format
- System location, security status, and corporation details
- Limiting factor emphasis in notifications
- Customizable webhook avatar (uses your webhook configuration)

**Smart Notification System**
- **Status-Based Alerts**: Notifications sent on status transitions (Good → Warning → Critical)
- **Critical Stage Reminders**: Optional interval reminders during critical stage
  - Fuel/Charters: Default 6 hours (configurable 1-24 hours, or disable with 0)
  - Strontium: Default 2 hours (configurable 1-12 hours, or disable with 0)
- **Final Alert**: Urgent notification sent exactly 1 hour before POS goes offline
- **Independent Tracking**: Fuel and strontium alerts tracked separately per POS
- **Spam Prevention**: Status flags reset automatically when POS returns to good status

**Configurable Thresholds**
- **Fuel Blocks**: Critical < 7 days, Warning < 14 days (customizable)
- **Strontium Clathrates**: Critical < 6 hours, Warning < 12 hours, Good < 24 hours (customizable)
- **Starbase Charters**: Critical < 7 days, Warning < 14 days (customizable)
- Validation ensures critical thresholds are always less than warning thresholds

**Discord Role Mentions**
- Optional role mentions for critical alerts (@role support)
- Easy role ID configuration with helper instructions
- Developer mode integration guide in documentation

**Notification Testing**
- Built-in webhook test button in Settings
- Immediate feedback on webhook connectivity
- Test message with sample embed format

#### Settings Page
Brand new Settings page providing centralized configuration for all plugin features.

**Notification Settings Tab**
- Enable/disable POS webhook notifications
- Webhook URL configuration (Discord/Slack)
- Discord role mention field
- Critical stage reminder intervals (fuel and strontium)
- Test webhook button for connectivity verification

**Threshold Settings Tab**
- Customizable alert thresholds for all resource types
- Fuel blocks: Critical and Warning day thresholds
- Strontium: Critical, Warning, and Good hour thresholds
- Starbase charters: Critical day threshold
- Real-time validation of threshold relationships

**Reserves Tracking Settings Tab**
- Corporate hangar exclusion selector (Hangars 1-7)
- Visual checkbox interface (Checked = Tracked, Unchecked = Excluded)
- Applies to both Upwell structures AND POSes
- Useful for excluding hangars used for:
  - Market trading operations
  - Logistics staging areas
  - Personal storage
  - Contract fulfillment

**Settings Management**
- Default values restoration button
- Form validation with helpful error messages
- Cache clearing on settings updates
- Persistent configuration across sessions

#### Help & Documentation Page
Comprehensive, searchable documentation system replacing the old About page.

**Complete Coverage**
- 9 major documentation sections with navigation sidebar
- Plugin overview and key features
- Getting started guide with installation steps
- Detailed feature explanations:
  - Fuel mechanics for Upwell structures
  - Metenox Moon Drill dual-fuel system
  - POS management and resource types
  - Notification system setup
  - Settings configuration
  - Pages guide (all plugin pages explained)
- Artisan commands reference with examples
- 15 FAQ entries covering common questions
- 6 troubleshooting issues with solutions

**Interactive Features**
- Live search functionality across all documentation
- Smooth navigation with URL hash support
- Collapsible FAQ accordion
- Direct links to plugin pages
- GitHub repository integration
- Plugin information sidebar with version, license, changelog links

**User-Friendly Design**
- Clean, modern interface with icons
- Color-coded sections for easy scanning
- Code examples with syntax highlighting
- Step-by-step instructions
- Visual indicators and badges explained
- Pro tips and best practices

**Comprehensive Troubleshooting**
- Issue 1: Structures Not Appearing
- Issue 2: Incorrect Fuel Consumption Numbers
- Issue 3: Missing Reserve Data
- Issue 4: Metenox Dual-Fuel Not Showing
- Issue 5: Charts Not Loading on Detail Page
- Issue 6: POS Notifications Not Working (NEW)

### ✨ Improvements

#### Navigation & Organization
- **Renamed Page**: "Fuel Status" → "Upwell Structures" for clarity
- **New Navigation Tab**: "Control Towers" for POS management
- **New Navigation Tab**: "Settings" for centralized configuration
- **Improved Menu Structure**: Logical grouping of structure types
- **Page Descriptions**: Clear labels distinguish Upwell vs POS structures

#### Reserve Tracking Enhancements
- **Hangar Exclusion**: Selective tracking of corporation hangars
- **Flexible Configuration**: Include/exclude hangars per operational needs
- **Cross-Platform**: Same exclusion rules apply to Upwell and POS reserves
- **User Control**: Easy checkbox interface in Settings
- **Documentation**: Clear explanation of exclusion use cases

#### User Experience
- **Consistent Interface**: POS pages match Upwell structure design language
- **Better Filtering**: Enhanced corporation and status filters
- **Improved Tables**: Better column organization and responsive design
- **Status Indicators**: Unified color scheme across all pages
- **Help Integration**: Context-sensitive help links throughout interface

### 🔧 Technical Changes

#### Database Schema
- **New Tables**:
  - `starbase_fuel_history`: POS fuel tracking (90-day retention)
  - `starbase_fuel_consumption`: POS consumption analytics
  - `starbase_fuel_reserves`: POS hangar reserve tracking
  - `structure_manager_settings`: Plugin configuration storage
- **New Columns**:
  - Added POS notification tracking fields to `corporation_starbases`
  - Added reserve tracking settings fields
  - Added notification status timestamps

#### New Artisan Commands
- `structure-manager:track-poses-fuel`: POS fuel tracking (runs every 10 minutes)
- `structure-manager:analyze-pos-consumption`: POS consumption analysis (runs daily)
- `structure-manager:notify-pos-fuel`: POS notification dispatcher (runs every 10 minutes)

#### Job Scheduling
- POS tracking: Every 10 minutes for real-time data
- POS analysis: Daily at 01:00 AM
- POS notifications: Every 10 minutes with intelligent cooldowns
- Upwell structures: Maintained at 1 hour tracking, 30-minute analysis

#### New Controllers
- `PosManagerController`: Handles POS dashboard and detail pages
- `SettingsController`: Manages plugin configuration with validation
- `FuelAlertController`: Enhanced for POS notification support

#### New Models
- `StarbaseFuelHistory`: POS fuel history tracking
- `StarbaseFuelConsumption`: POS consumption analytics
- `StarbaseFuelReserves`: POS hangar reserves
- `StructureManagerSettings`: Configuration management with caching

#### Helpers & Services
- `PosFuelCalculator`: Tower size, security space, and consumption calculations
- Enhanced `FuelConsumptionTracker`: Support for POS resource tracking
- Settings validation and sanitization service

### 📊 Performance & Data

#### Tracking Intervals
- **POS Tracking**: 10-minute intervals (6x per hour)
- **POS Analysis**: Daily comprehensive analysis
- **POS Notifications**: 10-minute check intervals
- **Upwell Tracking**: Maintained at 1-hour intervals
- **Upwell Analysis**: Maintained at 30-minute intervals
- **Reserve Tracking**: Hourly for all structure types

#### Data Retention
- **POS History**: 90 days (updated more frequently than Upwell)
- **Upwell History**: 6 months (unchanged)
- **Reserve History**: 3 months for all types (unchanged)
- **Consumption Data**: Matches respective history retention

#### Cache Management
- Settings cached for performance
- Automatic cache invalidation on updates
- Reserve exclusion query optimization

### 🐛 Bug Fixes

#### Notification System
- Fixed POS notification interval calculations
- Resolved status tracking field preservation in TrackPosesFuel job
- Corrected notification cooldown logic for status-based alerts
- Fixed critical stage reminder interval application
- Resolved final alert (1-hour) timing issues

#### UI/UX
- Fixed CSS compatibility issues across SeAT themes (default/jet)
- Resolved blade template structural issues causing help page errors
- Fixed responsive design issues on mobile devices
- Corrected color scheme inconsistencies

### 📝 Documentation Updates

#### New Documentation
- Complete POS management guide (installation, configuration, usage)
- Discord/Slack webhook setup instructions
- Settings page comprehensive documentation
- Notification system detailed explanation
- Hangar exclusion feature documentation
- POS troubleshooting guide (Issue #6)

#### Enhanced Documentation
- Updated FAQ with 5 new POS-related questions (Q12-Q15)
- Expanded command reference with POS commands
- Added notification system best practices
- Enhanced troubleshooting section
- Updated screenshots for new interface

### 🔄 Migration Notes

#### Automatic Migrations
All database changes are handled automatically via migrations. No manual intervention required.

#### Settings Migration
- Default settings are created automatically on first access
- Existing installations: Settings page will use defaults until customized
- No breaking changes to existing Upwell structure tracking

#### Data Compatibility
- Existing Upwell structure data remains unchanged
- POS data is tracked independently
- Reserve tracking respects new exclusion settings
- No data loss or corruption

### ⚠️ Breaking Changes

**None.** This release is fully backward compatible with previous versions.

### 🔮 Known Issues & Future Improvements

#### Known Limitations
- Webhook notifications currently support POSes only
- Upwell structure webhook notifications planned for v1.1.0+
- Hangar exclusion applies globally (cannot exclude per-structure)

---

# **Previous Updates / Related Enhancements**

## Version 1.0.7 — *October 2025*

**🧩 Maintenance & Fixes — *Refinements and Stability Improvements*** 

This update focuses on **cleaning up internal logic**, **removing redundant setup steps**, and **improving compatibility** with development environments.  
It doesn’t introduce new user-facing features, but it makes the plugin leaner, simpler, and easier to maintain.  

**🔧 Key Improvements**  
- **Simplified Schedule Seeder** — Now uses SeAT’s built-in service class instead of a custom implementation  
- **Removed Unnecessary Setup Command** — `structure-manager:setup` is no longer needed; registration is handled automatically  
- **Improved HTTPS Handling** — Fixed an issue where the plugin would force HTTPS and break local (HTTP) development environments  

**🤝 Contribution**

Special thanks to **@recursivetree** for identifying issues, refining setup flow, and improving plugin compatibility.  

---

## Version 1.0.6 — *October 2025*

**🌕 New Features — Metenox Moon Drill Support**

This update introduces full dual-fuel support for the new Metenox Moon Drills, expanding the plugin’s capabilities to track both Fuel Blocks and Magmatic Gas simultaneously.

**🔧 Key Additions**

* Dual Fuel System Tracking — Complete support for Metenox Moon Drills requiring both Fuel Blocks (120/day) and Magmatic Gas (4,800/day)  
* Limiting Factor Detection — Automatically determines which resource will deplete first  
* Magmatic Gas Reserve Tracking — Tracks magmatic gas stored in corporation hangars across all structures  
* Enhanced Critical Alerts — New purple "LIMITING" badges highlight which resource needs immediate attention  
* Dual Fuel Projections — Separate projections for fuel blocks and magmatic gas on detail pages  
* Improved Logistics Planning — Gas requirements are now included in hauling plans and logistics reports  
* Visual Indicators — Purple badges and icons throughout the interface for gas-related information

**🧩 Enhancements**

* Updated structure detail views with dual-fuel display and limiting factor visibility  
* Improved dark theme compatibility for new components  
* Expanded analytics to include magmatic gas consumption data

---

## Version 1.0.5 — *October 2025*

**Enhanced Structure Detail Page / Comprehensive Fuel Dashboard**

* Detailed consumption breakdown cards showing hourly, daily, weekly, monthly, and quarterly rates  
* Improved fuel projections with estimated blocks remaining, volume in m³, and precise time calculations  
* Historical analysis integration displaying fuel bay snapshot data and refuel event detection  
* Consumption anomaly alerts to identify potential service changes

**Visual Improvements**

* Enhanced dark theme support with better contrast and improved color palette  
* Service status indicators with clear online/offline visual display  
* Modern chart visualization with dark-themed gradients and improved tooltips  
* Better event tracking in fuel history table with change indicators and event badges

**User Experience**

* Clearer explanation of service-based calculations  
* Real-time consumption updates when services change  
* Better mobile responsiveness  
* Improved data presentation and readability

---

## Version 1.0.4 — *October 2025*

**Critical Bug Fix**

* **Fixed fuel consumption calculation for multi-service modules**
  * Previously calculated fuel consumption per service instead of per module
  * Structures with modules providing multiple services showed incorrect consumption rates

**Example Issue (Now Fixed)**

* **Standup Research Lab I** provides 3 services:
  * Blueprint Copying
  * Material Efficiency Research
  * Time Efficiency Research
* **OLD BUG**: Calculated as 3 separate modules = 27 blocks/hour ❌
* **NOW FIXED**: Correctly calculated as 1 module = 9 blocks/hour ✅

**Impact Examples**

* **Raitaru** with Research Lab + Invention Lab:
  * Before: ~36 blocks/hour (incorrect) ❌
  * After: **18 blocks/hour** (correct) ✅
* **Azbel** with Manufacturing Plant + Capital Shipyard:
  * Before: ~18 blocks/hour (incorrect) ❌
  * After: **27 blocks/hour** (correct) ✅

**Technical Changes**

* Added `SERVICE_TO_MODULE_MAP` constant mapping services to their source modules  
* Implemented proper module grouping to count each unique module only once  
* Updated `calculateFromActiveServices()` to group services by module before calculating fuel  
* Service names now use exact case-sensitive matching from EVE API

This fix ensures **accurate fuel consumption calculations** for all structures, especially those using Research Labs.

---

## Version 1.0.3 — *October 2025*

**Fixes**

* Fixed migration issue.

---

## Version 1.0.2 — *October 2025*

**Highlights**

* Added **Reserve Tracking** for corporation hangars and containers  
* Implemented **Automated Consumption Analysis** with hourly updates  
* Improved dashboard visuals and responsiveness  
* Added permissions: `structure-manager.view`, `structure-manager.admin`, `structure-manager.export`  
* Refined structure grouping and improved query efficiency  
* Minor bug fixes and ESI error handling improvements

---

## Version 1.0.1 — *October 2025*

**New Features**

* Introduced **Fuel Usage Analytics** with time-remaining prediction  
* Added manual Artisan commands for testing and diagnostics  
* Included full SeAT permission integration for granular role control  
* Added **automatic scheduling** of hourly and daily jobs (no cron setup needed)

**Fixes**

* Fixed inaccurate `fuel_expires` timestamps for newly anchored structures  
* Resolved caching issue causing delayed dashboard updates

---

## 🚀 Version 1.0.0 — *October 2025*

**Initial Release**

* Core structure tracking with automatic hourly fuel updates  
* Integration with SeAT’s job system and corporation asset sync  
* Dynamic dashboard showing fuel levels, time to depletion, and last refuel events  
* Configurable through SeAT’s permission system  
* Full support for multi-corporation SeAT installations

---

## 🔧 Development Notes

* Back-end processes are optimized for minimal performance impact  
* ESI polling is staggered to avoid hitting rate limits  
* History cleanup runs daily to maintain performance and data freshness

---

## 📦 Upcoming Features (Planned)

* Low-fuel alerts via Discord or SeAT notifications  
* Low stock reserves notifications  
* Alliance-level structure overview  
* Configurable data retention period via UI settings  
* POS support

---

For the latest updates, issues, or feature requests:  
👉 [github.com/MattFalahe/structure-manager](https://github.com/MattFalahe/structure-manager)
