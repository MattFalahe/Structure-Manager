# Structure Manager for SeAT

[![Latest Version](https://img.shields.io/packagist/v/mattfalahe/structure-manager.svg?style=flat-square)](https://packagist.org/packages/mattfalahe/structure-manager)
[![License](https://img.shields.io/badge/license-GPL--2.0-blue.svg?style=flat-square)](LICENSE)
[![SeAT](https://img.shields.io/badge/SeAT-5.x-blue.svg?style=flat-square)](https://github.com/eveseat/seat)

**v2.0.0 — The Ecosystem Era.** A comprehensive fuel management and structure-events plugin for EVE Online corporations in SeAT. Tracks fuel consumption across Upwell structures and legacy POS towers, fires Discord/Slack notifications for ESI events (attacks, reinforce timers, anchoring, sov, fuel alerts), and integrates with the broader Manager Core plugin family for cross-plugin coordination.

> Structure Manager works fully standalone. Installing Manager Core alongside it unlocks faster detection (~2 min vs 15-20 min), cross-plugin event broadcasting, shared director key pool, and the Fuel Economics page. Every ecosystem feature is purely additive — leaving Manager Core out keeps all v1 functionality intact at SeAT's native cadence.

📋 **[v2.0.2 release notes →](CHANGELOG.MD)**

## Features

### 🔥 Real-Time Fuel Tracking
- **Upwell Structures**: Hourly automated tracking with consumption analysis every 30 minutes
- **POS Towers**: 10-minute tracking intervals for real-time monitoring
- **Dual tracking method**: Primary fuel bay monitoring with days-remaining fallback
- **Automatic refuel detection** when fuel is added to structures
- **Historical consumption analysis** with anomaly detection (6 months for Upwell, 90 days for POS)

### 📊 Advanced Analytics
- **Accurate consumption calculations** based on actual online services
- **Service-specific fuel rates** with proper structure bonuses
- **Consumption trend analysis** to detect service changes
- **Predictive fuel requirements** for logistics planning
- **Interactive consumption charts** on detail pages

### 🏭 Structure Support

#### Upwell Structures
- **Citadels** (Astrahus, Fortizar, Keepstar)
- **Engineering Complexes** (Raitaru, Azbel, Sotiyo)
- **Refineries** (Athanor, Tatara)
- **Metenox Moon Drill** dual-fuel support
- **Navigation Structures** (Ansiblex, Pharolux, Tenebrex)

#### Legacy Player Owned Starbases (POS)
- **Multi-resource tracking**: Fuel blocks, strontium clathrates, and starbase charters
- **Tower size support**: Small, Medium, and Large towers with accurate consumption rates
- **Smart security detection**: Automatic charter tracking in high-security space
- **Limiting factor detection**: Identifies which resource will run out first
- **Faction & Officer towers**: Full support for fuel efficiency bonuses

### 🌕 Metenox Moon Drill Support
- **Dual-fuel system tracking**: Monitors both fuel blocks (120/day) and magmatic gas (4,800/day)
- **Limiting factor detection**: Automatically identifies which resource depletes first
- **Purple visual indicators**: Distinct badges for gas-related data throughout interface
- **Separate projections**: Individual consumption analysis for each fuel type
- **Magmatic gas reserves**: Tracks staged gas in corporation hangars

### 📦 Reserve Management (Upwell only)
- **CorpSAG hangar tracking** for staged fuel blocks and magmatic gas
- **Selective hangar tracking**: Exclude specific hangars from reserves (market trading, logistics, etc.)
- **Nested Office container support** for reserve detection
- **Refuel event logging** when fuel moves from reserves to bay
- **Custom division name display** for organized fuel storage
- **POS towers have no CorpSAG hangars** — their fuel/stront/charter inventories are tracked separately on the POS detail pages, not on the Reserves page

### 🔔 Discord & Slack Notifications
- **Three-namespace category system**: Upwell Structures, Structure Events (ESI), POS (legacy) — toggle each category independently, bind each to any number of webhooks
- **Webhook integration**: Rich embeds with color-coded alerts (Red: Critical, Yellow: Warning, Green: Recovery)
- **Per-binding role mentions**: Same notification can ping different Discord roles when it fires to different webhooks (corp Discord vs alliance Discord)
- **Discord role picker**: Multi-source union from `seat-discord-pings` (`discord_roles`) and `warlof/seat-connector`, deduplicated and searchable; set role IDs are translated to readable role names in the Notifications panel
- **Notification Routing Map**: Read-only Settings tab showing where every category delivers and which role it pings, tagged with the precedence tier (L1 binding / L2 category / L3 webhook) that resolved each one
- **Smart alerting**: Status-based transitions prevent spam (Good → Warning → Critical → Recovery)
- **Customizable intervals**: Set reminder frequencies during critical stage or disable completely
- **Final alerts**: Urgent notification 1 hour before POS goes offline
- **Attacker name resolution**: ESI-driven combat events show real character/corp/alliance names via the IdResolver service (local cache → ESI fallback with 7-day result cache)
- **Threshold customization**: Configure when alerts trigger for each resource

### 🚨 Critical Alerts
- **Priority-based warnings** (Critical < 7 days, Warning < 14 days)
- **Fuel bay status monitoring** separate from total reserves
- **System-grouped alerts** for efficient logistics
- **Real-time dashboard widget** for quick overview
- **Limiting factor badges** for Metenox and POS resources

### 🛰️ ESI Notification Events (v2.0.0)
- **Attack alerts** — StructureUnderAttack, LostShields, LostArmor, Destroyed (plus Skyhook variants)
- **Lifecycle alerts** — Anchoring (with 24h timer), Unanchoring, Ownership Transferred, Skyhook Deployed
- **Sov alerts** — SovStructureReinforced (with decloak time), SovCommandNodeEventStarted, EntosisCaptureStarted
- **Services-offline alerts** — StructureServicesOffline routed to a separate category for industry-ops channels
- **Three detection paths**, identifiable from the embed footer:
  - `Fast Poll (Manager Core)` — ~2-minute detection via the shared key pool
  - `SeAT Sweep (Manager Core)` — belt-and-braces fallback via SeAT's native bucket
  - `SeAT Native` — standalone fallback when Manager Core isn't installed
- **Mode selector** — operators can opt out of Manager Core fast-poll via Settings > Structure Events > ESI Detection Mode

### 🌐 Manager Core Integration (v2.0.0)

Structure Manager v2.0.0 is built to work with [Manager Core](https://github.com/MattFalahe/Manager-Core), an optional companion plugin that hosts the shared infrastructure of the broader plugin family:

**With Manager Core installed:**
- ⚡ **~2-minute ESI detection** via the shared director key pool (vs SeAT's native 15-20 min cadence)
- 💰 **Fuel Economics page** — projected ISK costs over weekly / monthly / quarterly / yearly windows using MC's pricing service
- 📡 **Cross-plugin events** — Structure Manager publishes `structure.alert.*` and `structure_manager.timer.*` events on MC's EventBus
- 🤝 **Ecosystem benefits** — Mining Manager flags moon extractions as at-risk when their parent structure enters reinforce; SeAT Broadcast (`seat-discord-pings`) will (when its calendar build lands) consume the tactical-planning events for fleet calendaring

**Without Manager Core:**
- All v1 functionality intact: POS + Upwell fuel tracking (POS fuel/stront/charter, Upwell fuel bay + CorpSAG reserves), Discord/Slack notifications via categories + webhooks, Metenox dual-fuel, logistics report, structure detail pages
- ESI notification detection falls back to SeAT's native 15-20 min bucket cadence
- Fuel Economics page is hidden from the sidebar (it depends on MC pricing)

Manager Core can be installed at any time; Structure Manager auto-detects it on next boot and registers automatically.
- **System-based fuel requirements** with 30/60/90-day projections
- **Hauling volume calculations** with trip estimates
- **Reserve vs. bay fuel breakdown** for refuel planning
- **Dual-fuel support**: Separate calculations for Metenox structures
- **CSV export functionality** for external logistics tools

### ⚙️ Settings & Configuration
- **Centralized settings page** for all plugin configuration
- **Webhook management**: Configure Discord/Slack notifications with test functionality
- **Threshold customization**: Set critical/warning levels for all resource types
- **Hangar exclusion**: Choose which corporate hangars to track for reserves
- **Notification intervals**: Configure reminder frequencies for fuel and strontium

### 📚 Help & Documentation
- **Complete in-app help system** with 9 major sections
- **18 FAQ entries** covering common questions (including v2.0.0 forensics + external reserves + webhook delivery)
- **6 troubleshooting guides** with step-by-step solutions
- **Searchable documentation** for quick answers
- **Artisan command reference** with examples

### 🎨 Enhanced User Interface
- **Dark theme optimized** with improved contrast and readability
- **Responsive design** for mobile and desktop
- **Interactive charts** for consumption visualization
- **Clean, modern layout** matching SeAT 5.x design language
- **Separate pages**: Upwell Structures, Control Towers (POS), Settings, Help & Documentation

### 🛠 Diagnostic Page (admin-only)
A `/structure-manager/diagnostic` admin-only page (deliberately not in the sidebar) with 10 tabs covering every operational concern:
- **Health Checks** — at-a-glance dashboard with 13 checks (environment, tables, schedules, webhooks, ESI coverage, notification state, pricing integration, **webhook delivery health**, etc.)
- **Type IDs (SDE)** — verifies every hardcoded EVE type ID resolves in your SDE
- **Master Test** — runs every check grouped by category with a pass-rate score
- **System Validation** — audits hardcoded constants + plugin dependencies
- **Settings Health** — every setting key with current value / default / respected status
- **Data Integrity** — DB-level orphan + stale-row checks
- **Fuel Trace** — pick one structure or POS and walk the full fuel pipeline step by step, with v2.0.0 forensics surfaces (event classification breakdown + suspect candidates for the latest withdrawal)
- **Notification Testing** — manually trigger the real cron jobs against real data
- **Notification Lab** (DEV) — inject synthetic notifications through the full dispatch pipeline
- **Test Data** (DEV) — generate synthetic test corps / structures / POSes

Every tab opens with a "What this tab does / When to use" intro since the diagnostic page is intentionally not in Help & Documentation. Heavy tabs lazy-load for fast cold starts.

### 📊 Webhook Delivery Telemetry (v2.0.0)
Every Discord/Slack webhook dispatch is recorded with HTTP status code, latency, success flag, error message, and the notification category that triggered it. **"Webhook Delivery Health (Last 24h)"** section on the diagnostic Health Checks tab shows per-webhook attempt counts, success rate (color-coded), average response time, and the most recent failure with HTTP code + error preview. Catches "the webhook URL silently 404'd two weeks ago and no one noticed" failure modes. 30-day retention.

## Installation

### SeAT Docker (recommended)

SeAT Docker installs plugins from a list in the `.env` file at the root of your `seat-docker` directory; the container entrypoint runs composer install on boot. **Do not run `composer require` inside the running container** — that change vanishes on the next container rebuild.

```bash
# 1. Edit your seat-docker .env file. Add mattfalahe/structure-manager
#    to the SEAT_PLUGINS environment variable (comma-separated).
#    The exact var name depends on which version of eveseat/seat-docker
#    you started from. Check your .env for an existing plugin list and
#    append to the same line.

# 2. Restart the stack so the entrypoint picks up the new plugin list.
#    Pass all three compose files so the DB + reverse-proxy come back correctly.
docker compose -f docker-compose.yml -f docker-compose.mariadb.yml -f docker-compose.traefik.yml down
docker compose -f docker-compose.yml -f docker-compose.mariadb.yml -f docker-compose.traefik.yml up -d

# 3. Optional but reassuring: watch the front container boot
docker compose -f docker-compose.yml -f docker-compose.mariadb.yml -f docker-compose.traefik.yml logs -f front
```

### Bare-metal SeAT (non-Docker)

```bash
cd /path/to/seat
composer require mattfalahe/structure-manager
php artisan migrate
```

### After install

The plugin will automatically:
- Start tracking fuel levels on the next scheduled run
- Create default settings
- Register scheduled tasks
- Seed notification categories (18 total across upwell / events / pos namespaces) with sensible enabled/disabled defaults
- Set up navigation menu items

**Companion plugins** (all optional):
```
mattfalahe/manager-core       # the optional hub — fast-poll + pricing + EventBus
mattfalahe/mining-manager     # subscribes to structure.alert.* for extraction-at-risk alerts
mattfalahe/seat-discord-pings # SeAT Broadcast — Discord broadcast routing + planned calendar
mattfalahe/corp-wallet-manager  # corp wallet tracking
mattfalahe/hr-manager         # HR / member-lifecycle
```

## Usage

### Dashboard Overview
Access Structure Manager from the main SeAT sidebar. The main dashboard shows:
- All corporation Upwell structures with fuel status
- Color-coded alerts (Red: Critical, Yellow: Warning, Green: Normal, Blue: Excellent)
- Real-time consumption rates
- Days remaining until refuel needed
- Metenox dual-fuel status with limiting factor indicators

### Control Towers (POS)
Dedicated page for legacy Player Owned Starbases:
- View all POSes with multi-resource status (fuel, strontium, charters)
- 10-minute tracking intervals for real-time data
- Security space detection (charters tracked in high-sec only)
- Limiting factor badges show priority resources
- Individual POS detail pages with 90-day history

### Fuel Reserves
Track staged fuel blocks and magmatic gas across your structures:
- View reserves by system and structure
- See which CorpSAG divisions contain fuel and gas (with in-game custom hangar names resolved from `corporation_divisions`)
- Monitor reserve movements and refuel events
- Exclude specific hangars from tracking (market, logistics, personal storage)
- **External reserves** (v2.0.0) — CorpSAG fuel staged outside your own structures shows up as "External" badged cards under each system. Covers NPC station Office rentals (resolves real names like *"Jita IV - Moon 4 - Caldari Navy Assembly Plant"*) AND foreign Upwell structures where your corp has CorpSAG access (resolves the player citadel name + system from `universe_structures`).
- Fuel Withdrawals table is paginated (25 / 50 / 100 / 200 per page) and shows in-game hangar names underneath each `CORPSAG{N}` badge.

### Fuel Forensics (v2.0.0)
Detect suspicious fuel withdrawals through inference, not deterministic attribution:

- **Event classification** — every fuel-tracking poll is classified into one of eight types: `consumption_normal`, `consumption_anomaly`, `refuel_internal`, `refuel_external`, `withdrawal_bay`, `withdrawal_reserves`, `unexplained_gain`, `unclassified`. Recent Fuel Records shows color-coded badges per row.
- **Suspect narrowing** — for each `withdrawal_*` event, an async job builds a candidate list of corp members who collaterally match four signals (online during ±1h window, personal asset gain match, has corp title, sold matching fuel ≤48h after). Scores bucket into HIGH / MEDIUM / LOW confidence.
- **Honest limitation** — ESI does not expose actor identity for asset moves. The candidate list is probabilistic inference from collateral SeAT data, not "who did it". False positives are inevitable (logistics alts look like thieves). Operator approval workflow planned in a future tier.

### Critical Alerts
Stay on top of urgent fuel needs:
- Structures and POSes with less than 14 days of fuel
- Sorted by urgency (most critical first)
- Quick-view fuel requirements for each resource type
- Limiting factor indicators for Metenox and POS
- One-click navigation to structure details

### Logistics Report
Plan your fuel hauling operations:
- Fuel requirements grouped by solar system
- 30/60/90-day projections for each structure
- Separate calculations for fuel blocks and magmatic gas
- Total volume and hauler trip calculations
- CSV export for external planning tools

### Settings
Configure the plugin to match your needs:
- **Notification Settings**: Webhook URLs, intervals, role mentions, thresholds
- **Reserves Tracking**: Select which hangars to include in reserves calculations
- **Test Webhook**: Verify Discord/Slack connectivity
- **Restore Defaults**: Reset all settings to default values

### Structure Detail Pages
Deep dive into individual structure or POS fuel data:
- Complete fuel history with consumption graphs
- Service module breakdown with consumption rates
- Fuel projections and refuel recommendations
- Historical analysis with refuel event timeline
- Reserve status and movement tracking
- For Metenox: Separate charts for fuel blocks and magmatic gas
- For POS: Separate displays for fuel, strontium, and charters

### Help & Documentation
Comprehensive in-app documentation:
- Complete feature explanations
- Getting started guide
- Fuel mechanics reference
- Troubleshooting guides
- FAQ section with 15 entries
- Artisan command reference

## Permissions

Structure Manager uses SeAT's permission system:

- `structure-manager.view`: View structure fuel data
- `structure-manager.admin`: Administrative functions
- `structure-manager.export`: Export logistics reports

Assign permissions via SeAT's Settings → Access Management.

## Automated Tasks

The plugin runs several scheduled jobs automatically:

### Upwell Structures
1. **Fuel Tracking** (`structure-manager:track-fuel`)
   - Runs hourly at :15 past the hour
   - Tracks fuel bay levels and consumption

2. **Consumption Analysis** (`structure-manager:analyze-consumption`)
   - Runs every 30 minutes
   - Analyzes consumption patterns and generates recommendations

### POS Towers
3. **POS Fuel Tracking** (`structure-manager:track-poses-fuel`)
   - Runs every 10 minutes
   - Real-time tracking of fuel, strontium, and charters

4. **POS Consumption Analysis** (`structure-manager:analyze-pos-consumption`)
   - Runs daily at 01:00 AM
   - Analyzes POS consumption patterns

5. **POS Notifications** (`structure-manager:notify-pos-fuel`)
   - Runs every 10 minutes
   - Sends webhook alerts for low fuel levels

### Maintenance
6. **History Cleanup** (`structure-manager:cleanup-history`)
   - Runs daily at 03:00 AM
   - Removes old history records (6 months for Upwell, 90 days for POS)
   - Keeps reserve data for 3 months

### Manual Commands

```bash
# Upwell structures (fuel tracking)
php artisan structure-manager:track-fuel
php artisan structure-manager:analyze-consumption
php artisan structure-manager:notify-upwell-fuel

# POS towers
php artisan structure-manager:track-poses-fuel
php artisan structure-manager:analyze-pos-consumption
php artisan structure-manager:notify-pos-fuel
php artisan structure-manager:simulate-consumption       # rapid fuel consumption testing

# ESI notification detection (v2.0.0)
php artisan structure-manager:process-notifications      # SeAT-native fallback for ESI events
php artisan structure-manager:track-structure-presence   # destruction-detection medium-confidence path

# Cross-plugin EventBus publishing (v2.0.0)
php artisan structure-manager:publish-timer-schedule-events  # timer.upcoming_24h / .upcoming_1h / .elapsed events

# Test infrastructure (v2.0.0)
php artisan structure-manager:create-test-upwell-structures
php artisan structure-manager:create-test-poses
php artisan structure-manager:create-test-metenox
php artisan structure-manager:inject-test-notification --list   # see all 23 supported notification types
php artisan structure-manager:cleanup-test-data --dry-run       # preview what would be deleted

# Maintenance
php artisan structure-manager:cleanup-history
php artisan structure-manager:prune-structure-board-timers
```

Full command reference with examples and cron schedules is available in the in-app **Help & Documentation > Commands** section.

## Requirements

- SeAT 5.x
- PHP 8.1 or higher
- MySQL/MariaDB
- Corporation structures tracked by SeAT
- Corporation asset data (for fuel bay and reserve tracking)
- Valid ESI tokens with required scopes

## Troubleshooting

### No fuel data showing
1. Ensure your corporation's ESI tokens have the required scopes
2. Wait for SeAT to complete initial corporation asset sync
3. Check that structures have fuel_expires timestamps
4. Run `php artisan structure-manager:track-fuel` manually
5. For POS: Run `php artisan structure-manager:track-poses-fuel`

### Inaccurate consumption rates
1. Allow 24-48 hours for initial data collection
2. Ensure services are properly online in game
3. Check for recent service changes or structure states
4. Verify fuel blocks are tracked in corporation assets

### Reserves not detected
1. Fuel must be in structure's CorpSAG hangars (1-7)
2. Or in Office containers inside the structure
3. Must be fuel blocks (Type ID: 4312) or magmatic gas (Type ID: 58903)
4. Corporation asset data must be up to date
5. Check Settings → Reserves Tracking to verify hangars aren't excluded

### POS notifications not working
1. Go to Settings and verify webhook URL is configured
2. Click "Test Webhook" to verify connectivity
3. Ensure notifications are enabled
4. Check notification intervals aren't set to 0 if you want reminders
5. Verify POS has actually changed status (good→warning→critical)
6. Check Laravel logs: `storage/logs/laravel.log`

### Charts not loading
1. Ensure JavaScript is enabled in your browser
2. Check browser console (F12) for errors
3. Clear browser cache and reload
4. Verify sufficient historical data exists (at least a few hours)

For more troubleshooting help, visit the **Help & Documentation** page in Structure Manager (it ships with the plugin and is kept up to date alongside the code).

## Support & Contributing

- **Issues**: [GitHub Issues](https://github.com/MattFalahe/structure-manager/issues)
- **Documentation**: in-app **Help & Documentation** page (ships with the plugin)
- **Changelog**: [CHANGELOG.MD](CHANGELOG.MD)
- **Pull Requests**: Always welcome!

## License

This project is licensed under the GNU General Public License v2.0 - see the [LICENSE](LICENSE) file for details.

This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation; either version 2 of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.

## Credits

**Author**: Matt Falahe  
**SeAT Compatibility**: 5.x

Built for the EVE Online community. Special thanks to the SeAT development team and all contributors.

---

*EVE Online and the EVE logo are the registered trademarks of CCP hf. All rights are reserved worldwide. All other trademarks are the property of their respective owners. EVE Online, the EVE logo, EVE and all associated logos and designs are the intellectual property relating to these trademarks are likewise the intellectual property of CCP hf. All artwork, screenshots, characters, vehicles, storylines, world facts or other recognizable features of the intellectual property relating to these trademarks are likewise the intellectual property of CCP hf.*
