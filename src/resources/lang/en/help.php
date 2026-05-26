<?php

return [
    // Navigation
    'help_documentation' => 'Help & Documentation',
    'search_placeholder' => 'Search documentation...',
    'overview' => 'Overview',
    'getting_started' => 'Getting Started',
    'features' => 'Features',
    'fuel_mechanics' => 'Fuel Mechanics',
    'metenox_drills' => 'Metenox Drills',
    'pos_management' => 'POS Management',
    'notifications' => 'Notifications',
    'settings' => 'Settings',
    'pages_guide' => 'Pages Guide',
    'commands' => 'Commands',
    'faq' => 'FAQ',
    'troubleshooting' => 'Troubleshooting',
    'admin_diagnostics_nav' => 'Admin Diagnostics',

    // Plugin Information
    'plugin_info_title' => 'Plugin Information',
    'version' => 'Version',
    'license' => 'License',
    'author' => 'Author',
    'github_repo' => 'GitHub Repository',
    'changelog' => 'Full Changelog',
    'report_issues' => 'Report Issues',
    'readme' => 'README',
    'support_project' => 'Support the Project',
    'view_changelog' => 'View Full Changelog',
    'support_list' => '<ul style="margin-top: 10px; margin-bottom: 0;">
        <li>⭐ Star the GitHub repository</li>
        <li>🐛 Report bugs and issues</li>
        <li>💡 Suggest new features</li>
        <li>🔧 Contributing code improvements</li>
        <li>🌟 Share with other SeAT users</li>
    </ul>',

    // v2.0.0 — The Ecosystem Era (2026-05-12)
    // This is the public canonical release that supersedes the internal
    // dev versions (v2/v3.0/v3.1). The framing is "Structure Manager is
    // now part of a connected plugin ecosystem, not a standalone tool" —
    // Manager Core is the optional hub, Mining Manager consumes the
    // tactical events, SeAT Broadcast calendars them, and SM still works fully
    // standalone for installs that don't want the ecosystem layer.
    'v2_badge' => 'NEW in v2.0.0',
    'whats_new_v2_title' => 'What\'s New in v2.0.0 — The Ecosystem Era',
    'whats_new_v2_intro' => 'Structure Manager v2.0.0 is the first public canonical release of the ecosystem-era plugin family. Where the original Structure Manager was a single-purpose fuel tracker, v2.0.0 sits at the centre of a connected plugin suite: Manager Core provides shared infrastructure (fast-poll, pricing, EventBus), Mining Manager consumes Structure Manager\'s combat events, and SeAT Broadcast [<code>seat-discord-pings</code>] (when its calendar feature lands) consumes the tactical-planning events. <strong>Structure Manager still works fully standalone</strong> when none of these are installed — every ecosystem feature is purely additive. Look for the <span class="v2-badge">NEW in v2.0.0</span> badge throughout this documentation to find sections covering ecosystem features in detail.',
    'whats_new_v2_list' => '<p><strong>Headline features:</strong></p>
        <ul>
            <li><strong>Plugin ecosystem integration</strong> — Structure Manager publishes a documented family of <code>structure.alert.*</code> events on Manager Core\'s EventBus. Mining Manager already subscribes (for extraction-at-risk alerts on reinforced structures); SeAT Broadcast will subscribe (calendar view + pre-timer FC reminders). Every ecosystem feature degrades to "harmless no-op" when companion plugins are absent. <a href="#notifications">Tactical events contract →</a></li>
            <li><strong>Three-path ESI detection</strong> — Manager Core fast-poll (~2 min per corp via adaptive per-corp rotation), MC sweep fallback (~10 min via SeAT bucket + MC routing), and SM-native sweep (~15-20 min, standalone fallback). Each path is identifiable from the Discord embed footer ("Fast Poll (Manager Core)" / "SeAT Sweep (Manager Core)" / "SeAT Native"). <a href="#notifications">Detection paths →</a></li>
            <li><strong>Attacker name resolution via public ESI</strong> — when an attacker isn\'t in SeAT\'s local cache, SM looks them up through CCP\'s public ESI endpoint with a 7-day result cache. Embeds show real character names + clickable zKillboard links instead of "Pilot ID #N (name not cached)". Same three-tier chain for corporations and alliances.</li>
            <li><strong>Operational security policy</strong> — formal documentation of trust zones (SeAT auth + operator-controlled webhooks) and explicit rejection of feature classes that would leak tactical data outside those zones. ICS / calendar export, third-party data feeds, etc. will not ship — the reasoning is documented in-product so the same conversation does not get relitigated. <a href="#notifications">Operational Security →</a></li>
            <li><strong>Fuel Economics page</strong> — when Manager Core is installed, a dedicated page shows weekly / monthly / quarterly / yearly fuel ISK projections with per-system and per-structure breakdowns, daily-trend chart, cheapest-fuel-block suggestion, and optimization savings. Uses MC\'s pricing service; market and price method configurable in MC → Pricing Preferences. <a href="#economics">Fuel Economics →</a></li>
            <li><strong>Manager Core Plugin Bridge diagnostic improvements</strong> — the MC admin dashboard now shows worker-context registry snapshots (handlers registered, key pool size, plugins contributing) plus per-plugin integration badges (pricing types, EventBus subs, ESI handlers, recent publishes) and last-activity timestamps. Surfaces cross-plugin health visually so silent failures become obvious.</li>
            <li><strong>Notifications page architecture</strong> — webhooks, notification categories, and Discord role mentions are three separate concerns on a dedicated sidebar page. 18 shipped categories across 3 namespaces (Upwell / Structure Events / POS Legacy). Per-binding role overrides + category defaults + webhook legacy fallback. <a href="#notifications">Notifications →</a></li>
            <li><strong>Discord role picker</strong> — multi-source union from <code>mattfalahe/seat-discord-pings</code> and <code>warlof/seat-connector</code>. Searchable, deduplicated, source-badged dropdown next to every role-mention input.</li>
            <li><strong>Enhanced test infrastructure</strong> — symmetric <code>create-test-*</code> commands paired with the new <code>cleanup-test-data</code> command. Inject-test-notification command for synchronous end-to-end webhook verification. All test data lives in declared safe ID ranges so production data cannot be accidentally affected. <a href="#commands">Commands →</a></li>
            <li><strong>POS namespace isolation</strong> — POS categories are kept separate from Upwell + Structure Events so CCP\'s eventual POS removal will be a clean uninstall path.</li>
        </ul>
        <p style="margin-top:12px;"><strong>Companion plugins (all optional):</strong></p>
        <ul>
            <li><strong>Manager Core</strong> — the optional hub. Adds 10-15x faster ESI detection, shared director key pool, pricing service, cross-plugin EventBus, SDE service, REST API. <a href="#manager-core">Learn more →</a></li>
            <li><strong>Mining Manager</strong> — when installed alongside, automatically flags moon extractions as at-risk when their parent structure goes into reinforce.</li>
            <li><strong>SeAT Broadcast [<code>seat-discord-pings</code>]</strong> — already integrated with Manager Core\'s EventBus; a planned calendar build will consume SM\'s tactical-planning event family (anchoring + sov + reinforce timers) to populate a fleet-coverage calendar with configurable pre-timer reminder pings.</li>
        </ul>',
    'whats_new_v2_upgrade_note' => 'Upgrading from v1.x is seamless. The v2 migrations run additively on top of your existing schema, preserving all settings, webhooks, fuel history, and POS data. The legacy <code>esi_attack_role_mention</code> setting is auto-migrated to the new <code>events.structure_attack</code> category role mention. Notification categories (18 total across upwell/events/pos namespaces) are seeded with sensible enabled/disabled defaults but NOT auto-bound to webhooks — you bind each category explicitly via the Notifications panel to avoid surprise routing. Companion plugins (Manager Core, Mining Manager) can be installed at any time after the upgrade and Structure Manager detects them automatically at boot.',

    // Overview
    'welcome_title' => 'Welcome to Structure Manager',
    'welcome_desc' => 'Your comprehensive fuel management and monitoring system for EVE Online corporation structures.',
    'what_is_title' => 'What is Structure Manager?',
    'what_is_desc' => 'Structure Manager is a comprehensive fuel management plugin for EVE Online corporation structures in SeAT. It provides real-time fuel level monitoring, consumption analytics, reserve tracking, critical alerts, and logistics planning tools to ensure your structures never run out of fuel.',
    'key_benefit' => 'Key Benefit',
    'key_benefit_desc' => 'Never let a structure run out of fuel again. Structure Manager provides proactive alerts and detailed analytics to keep your infrastructure running smoothly.',

    // Key Features
    'key_features' => 'Key Features',
    'feature_alerts_title' => 'Real-Time Fuel Alerts',
    'feature_alerts_desc' => 'Get immediate notifications for structures running low on fuel, with urgent indicators for critical situations.',
    'feature_analytics_title' => 'Consumption Analytics',
    'feature_analytics_desc' => 'Track historical fuel usage patterns, consumption rates, and detect anomalies in fuel consumption.',
    'feature_reserves_title' => 'Reserve Management',
    'feature_reserves_desc' => 'Monitor staged fuel sitting in your Upwell corporation CorpSAG hangars — the fuel waiting to be hauled into a structure\'s fuel bay. Selective tracking lets you exclude hangars used for market trading or logistics so they don\'t inflate your reserve totals. POS towers do not have CorpSAG hangars; their fuel/strontium/charter inventories are tracked separately on the POS detail pages, not on the Reserves page. <strong>v2.0.0</strong> adds tracking for CorpSAG fuel staged outside your own structures — both NPC station Office rentals AND foreign Upwell structures where your corp has CorpSAG access. These appear as "External" badged cards under each system with the resolved location name. Custom in-game hangar names (set per-corp in the EVE client) are resolved and displayed alongside the <code>CorpSAG{N}</code> flag everywhere.',
    'feature_logistics_title' => 'Logistics Planning',
    'feature_logistics_desc' => 'Generate comprehensive fuel requirements reports by system with hauling calculations and export capabilities.',
    'feature_metenox_title' => 'Metenox Moon Drill Support',
    'feature_metenox_desc' => 'Full dual-fuel tracking for Metenox drills requiring both fuel blocks and magmatic gas.',
    'feature_automated_title' => 'Automated Tracking',
    'feature_automated_desc' => 'Upwell structures tracked hourly with 30-minute analysis intervals. POSes tracked every 10 minutes for real-time monitoring. Automatic historical data retention with 90-day POS cleanup.',
    'feature_pos_title' => 'Legacy Player Owned Starbases (POS towers) Support',
    'feature_pos_desc' => 'Comprehensive POS monitoring with fuel blocks, strontium clathrates, and starbase charter tracking. Automatically detects security space, identifies the limiting factor (whichever resource runs out first), and calculates reinforcement timers from the strontium bay. Full support for faction and officer tower fuel efficiency bonuses. POS towers do not have CorpSAG hangars — their fuel/stront/charter inventories live on the POS detail pages, not on the Upwell Reserves page.',

    'feature_forensics_title' => 'Fuel Forensics (v2.0.0)',
    'feature_forensics_desc' => 'Every fuel-tracking poll is classified into one of eight event types (normal consumption, anomaly, internal/external refuel, bay/reserves withdrawal, unexplained gain, unclassified) and rendered as a color-coded badge in Recent Fuel Records. For withdrawal events, an async forensics job builds a per-event candidate list scoring corp members on collateral signals (online window, asset gain match, has corp title, market sales) into HIGH / MEDIUM / LOW confidence buckets. <strong>Honest limitation</strong>: ESI does not expose actor identity for asset moves — these candidates are probabilistic inferences, not "who did it". False positives are inevitable (logistics alts look like thieves). The system catches lazy thieves; careful market-alt thieves escape detection.',

    'feature_webhook_delivery_title' => 'Webhook Delivery Telemetry (v2.0.0)',
    'feature_webhook_delivery_desc' => 'Every Discord/Slack webhook dispatch is recorded with HTTP status code, latency, success flag, error message, and the notification category that triggered it. The Diagnostic page\'s Health Checks tab includes a "Webhook Delivery Health (Last 24h)" section showing per-webhook attempt counts, success rate (color-coded), average response time, and the most recent failure. Catches "the webhook URL silently 404\'d two weeks ago and no one noticed" failure modes. 30-day retention, pruned by the daily cleanup-history command.',

    // Quick Links
    'quick_links_title' => 'Quick Links',
    'view_dashboard' => 'View Dashboard',
    'view_alerts' => 'View Critical Alerts',
    'view_logistics' => 'View Logistics Report',

    // Getting Started
    'getting_started_title' => 'Getting Started',
    'getting_started_desc' => 'Structure Manager is already installed on this SeAT instance — if you can read this page, the plugin and its database migrations are in place. This section covers the minimal configuration needed to get useful fuel tracking and alerts for your corporation. Most of it is sensible-default out of the box; the one thing that needs a deliberate decision is notification routing.',

    'first_time_setup_title' => 'Minimal Setup',
    'setup_step1_title' => 'Verify access and data sync',
    'setup_step1_desc' => 'Confirm your SeAT role grants the <code>structure-manager.view</code> permission, then open Structure Manager from the sidebar. You should see your corporation\'s structures with fuel levels. An empty list usually just means SeAT has not finished its first corporation structures + assets sync — that can take 1-2 hours after a corporation token is first added.',
    'setup_step2_title' => 'Kickstart the first fuel poll (optional)',
    'setup_step2_desc' => 'Fuel data populates automatically on the next scheduled run (Upwell hourly, POS every 10 minutes). If you would rather not wait, trigger an immediate poll by hand from your SeAT directory. The first command covers Upwell structures + CorpSAG reserves; the second covers POS towers (only needed if your corp runs POSes):',
    'setup_step3_title' => 'Review fuel thresholds',
    'setup_step3_desc' => 'Open the Settings page and check the warning / critical day thresholds. Defaults are 14 days (warning) and 7 days (critical) — adjust them to match how long your corp\'s refuel logistics realistically take. Reserves Tracking and ESI Detection Mode also live on Settings, but both ship with working defaults and can be left alone initially.',
    'setup_step4_title' => 'Configure notifications',
    'setup_step4_desc' => 'Discord / Slack alerts are opt-in. Open the Notifications page and (1) add a webhook URL, (2) bind the notification categories you care about to that webhook, (3) optionally set a role mention per binding. Nothing is auto-bound — no alerts fire until you bind a category, so there is no surprise routing on first install. See the Notifications section of this page for the full walkthrough.',

    'success_tip' => 'Good to know',
    'success_desc' => 'Upwell structures are tracked hourly with consumption analysis every 30 minutes. POSes are tracked every 10 minutes for real-time monitoring. Historical data is retained for 6 months for Upwell structures and 90 days for POSes. All of this runs automatically via SeAT\'s scheduler — no cron setup beyond SeAT\'s standard configuration.',

    // Features
    'features_overview' => 'Features Overview',
    'features_intro' => 'Structure Manager provides a comprehensive suite of tools for managing your corporation\'s structure fuel requirements.',

    'real_time_alerts' => 'Real-Time Fuel Alerts',
    'real_time_alerts_desc' => '<ul>
        <li><strong>Critical threshold monitoring:</strong> Automatic alerts for structures with less than 14 days of fuel</li>
        <li><strong>Urgent indicators:</strong> Special highlighting for structures below 7 days</li>
        <li><strong>Dual-fuel tracking:</strong> Metenox Moon Drills show separate status for fuel blocks and magmatic gas</li>
        <li><strong>Limiting factor detection:</strong> Identifies which resource will run out first on Metenox structures</li>
        <li><strong>Fuel requirement calculations:</strong> Shows exactly how much fuel is needed to refuel each structure</li>
    </ul>',

    'consumption_analytics' => 'Consumption Analytics',
    'consumption_analytics_desc' => '<ul>
        <li><strong>Historical fuel tracking:</strong> 6 months of fuel bay history with hourly snapshots</li>
        <li><strong>Consumption rate analysis:</strong> Track fuel usage per hour, day, week, and month</li>
        <li><strong>Service-based calculations:</strong> Accurate fuel consumption based on active service modules</li>
        <li><strong>Anomaly detection:</strong> Identifies unusual consumption patterns or service changes</li>
        <li><strong>Refuel event tracking:</strong> Logs when structures are refueled and by how much</li>
        <li><strong>Visual charts:</strong> Consumption graphs and historical trends on detail pages</li>
    </ul>',

    'reserve_management' => 'Reserve Management',
    'reserve_management_desc' => '<ul>
        <li><strong>Corporation hangar tracking:</strong> Monitors fuel staged in Upwell CorpSAG hangars</li>
        <li><strong>Structure-level reserves:</strong> See which structures have staged fuel ready</li>
        <li><strong>Division tracking:</strong> Identifies which hangar divisions contain fuel</li>
        <li><strong>Custom division names:</strong> Shows your corporation\'s custom hangar division names</li>
        <li><strong>External reserves (v2.0.0):</strong> CorpSAG fuel staged in NPC station Office rentals and foreign Upwell structures appears as "External" badged cards under each system, with the real location name resolved</li>
        <li><strong>Reserve history:</strong> 3 months of reserve movement tracking</li>
        <li><strong>Purple badges:</strong> Special indicators for magmatic gas reserves (Metenox support)</li>
        <li><strong>Selective Tracking:</strong> Configure which hangars to exclude from tracking (see Settings)</li>
        <p><strong>Note:</strong> Reserve tracking is Upwell-only — POS towers have no CorpSAG hangars. Tracking also respects your hangar exclusion settings; excluded hangars will not appear in reserve calculations.</p>
    </ul>',
 
    'logistics_planning' => 'Logistics Planning',
    'logistics_planning_desc' => '<ul>
        <li><strong>System-organized reports:</strong> Fuel requirements grouped by solar system</li>
        <li><strong>Multi-timeframe projections:</strong> 30, 60, and 90-day fuel needs</li>
        <li><strong>Hauler trip calculations:</strong> Automatically calculates number of hauler trips needed</li>
        <li><strong>Dual-fuel logistics:</strong> Metenox structures include both fuel block and gas requirements</li>
        <li><strong>CSV export:</strong> Export logistics data for your hauling team</li>
        <li><strong>Volume calculations:</strong> Total m³ required for efficient planning</li>
    </ul>',

    'historical_tracking' => 'Historical Data Tracking',
    'historical_tracking_desc' => '<ul>
        <li><strong>6 months fuel bay history:</strong> Complete record of fuel levels over time</li>
        <li><strong>3 months reserve history:</strong> Track reserve movements and staging</li>
        <li><strong>Refuel event logging:</strong> Records when and how much fuel was added</li>
        <li><strong>Consumption pattern analysis:</strong> Identifies trends and anomalies</li>
        <li><strong>Service change detection:</strong> Tracks when services are activated or deactivated</li>
    </ul>',

    'accurate_calculations' => 'Accurate Fuel Calculations',
    'accurate_calculations_desc' => '<ul>
        <li><strong>EVE-accurate mechanics:</strong> Follows official EVE Online fuel consumption rules</li>
        <li><strong>Service-based tracking:</strong> Only online services consume fuel</li>
        <li><strong>Multi-service module handling:</strong> Correctly counts modules that provide multiple services</li>
        <li><strong>Correct moon drill rates:</strong> 120 blocks/day for traditional drills, no bonuses</li>
        <li><strong>Metenox dual-fuel:</strong> 120 fuel blocks + 4,800 magmatic gas per day</li>
        <li><strong>Refinery bonuses:</strong> Accurate Athanor (-20%) and Tatara (-25%) fuel reductions</li>
    </ul>',

    // v2.0.0 feature-overview entries
    'mc_required_badge' => 'Manager Core Required',

    'structure_events_feature' => 'Structure Event Notifications (ESI)',
    'structure_events_feature_desc' => '<ul>
        <li><strong>Combat alerts:</strong> Discord / Slack notifications for structures under attack, shield reinforced, armor reinforced, and destroyed</li>
        <li><strong>Lifecycle events:</strong> Anchoring, unanchoring, ownership transfer, low-power and high-power transitions</li>
        <li><strong>Sovereignty events:</strong> Entosis capture started, sov reinforced, command nodes spawning</li>
        <li><strong>Attacker name resolution:</strong> Embeds show real character / corporation / alliance names (resolved via CCP public ESI with a 7-day cache) plus clickable zKillboard links</li>
        <li><strong>Webhook delivery telemetry:</strong> Every dispatch records HTTP status, latency, and success so a silently-broken webhook is visible at a glance on the diagnostic page</li>
    </ul>',

    'fuel_forensics_feature' => 'Fuel Forensics',
    'fuel_forensics_feature_desc' => '<ul>
        <li><strong>Event classification:</strong> Every fuel poll is tagged as normal consumption, anomaly, internal / external refuel, bay / reserves withdrawal, or unexplained gain</li>
        <li><strong>Color-coded badges:</strong> Recent Fuel Records shows the classification per row with an explanatory tooltip</li>
        <li><strong>Withdrawal forensics:</strong> For each withdrawal event, a candidate list scores corp members on collateral signals (online during the window, personal-hangar gain, has corp title, matching market sales) into HIGH / MEDIUM / LOW buckets</li>
        <li><strong>Honest limitation:</strong> ESI does not expose actor identity for asset moves — candidates are probabilistic inference, not proof. The system catches lazy thieves; careful market-alt thieves escape detection.</li>
    </ul>',

    'fuel_economics_feature' => 'Fuel Economics',
    'fuel_economics_feature_desc' => '<p style="margin-bottom:8px;"><em>This feature only works when the optional <strong>Manager Core</strong> companion plugin is installed. Without Manager Core the Fuel Economics page is hidden from the sidebar and the rest of Structure Manager works exactly as before.</em></p>
    <ul>
        <li><strong>ISK cost projections:</strong> Projected fuel spend across weekly / monthly / quarterly / yearly windows</li>
        <li><strong>Per-system and per-structure breakdowns:</strong> See exactly where the fuel budget goes</li>
        <li><strong>Daily-trend chart:</strong> Visualise spend over time</li>
        <li><strong>Cheapest-fuel-block suggestion:</strong> An optimization banner highlights potential savings</li>
        <li><strong>Pricing source:</strong> Uses Manager Core\'s pricing service; the market and price method are configurable in Manager Core &gt; Pricing Preferences</li>
    </ul>',

    // Fuel Mechanics
    'fuel_mechanics_title' => 'Understanding EVE Online Fuel Mechanics',
    'fuel_mechanics_intro' => 'Structure Manager uses accurate EVE Online fuel consumption mechanics. Understanding these rules helps you interpret the plugin\'s data correctly.',

    'base_rules_title' => 'Base Rules',
    'base_rules_list' => '<ul>
        <li><strong>Upwell structures consume ZERO fuel</strong> by themselves</li>
        <li><strong>Only online service modules</strong> consume fuel blocks</li>
        <li><strong>Each module</strong> consumes a fixed amount of fuel regardless of usage</li>
        <li><strong>One module = one fuel cost</strong>, even if it provides multiple services</li>
    </ul>',

    'service_modules_title' => 'Service Module Consumption',
    'service_modules_desc' => 'Different service modules have different fuel consumption rates:',
    'service_modules_list' => '<ul>
        <li><strong>Standard modules</strong> (Manufacturing, Research, Invention, etc.): <span class="fuel-type-indicator fuel-blocks">9 blocks/hour</span></li>
        <li><strong>Capital modules</strong> (Capital Shipyard): <span class="fuel-type-indicator fuel-blocks">18 blocks/hour</span></li>
        <li><strong>Supercapital modules</strong>: <span class="fuel-type-indicator fuel-blocks">27 blocks/hour</span></li>
    </ul>',

    'important_note' => 'Important Note',
    'multi_service_note' => 'A Standup Research Lab I provides three services (Blueprint Copying, Material Efficiency Research, Time Efficiency Research) but counts as ONE module consuming 9 blocks/hour total, NOT three separate modules.',

    'moon_drills_title' => 'Moon Mining Drills',
    'moon_drills_desc' => '<ul>
        <li><strong>Traditional Moon Drills</strong>: <span class="fuel-type-indicator fuel-blocks">120 blocks/day (5 blocks/hour)</span></li>
        <li><strong>Moon drills receive NO fuel reduction bonuses</strong> from Athanor or Tatara</li>
        <li><strong>Metenox Moon Drills</strong> (new): <span class="fuel-type-indicator fuel-blocks">120 blocks/day</span> + <span class="fuel-type-indicator magmatic-gas">4,800 gas/day</span></li>
        <li><strong>Metenox drills also receive NO bonuses</strong> from any refinery type</li>
    </ul>',

    'fuel_bonuses_title' => 'Fuel Reduction Bonuses',
    'fuel_bonuses_intro' => 'Certain refinery structures provide fuel reduction bonuses, but ONLY for specific service types:',
    'fuel_bonuses_list' => '<ul>
        <li><strong>Athanor:</strong> -20% fuel reduction for Reprocessing and Reaction modules only (96 blocks/day instead of 120)</li>
        <li><strong>Tatara:</strong> -25% fuel reduction for Reprocessing and Reaction modules only (90 blocks/day instead of 120)</li>
        <li><strong>These bonuses do NOT apply to:</strong> Moon drills, manufacturing, research, or any other services</li>
    </ul>',

    'common_mistake' => 'Common Mistake',
    'common_mistake_desc' => 'Many pilots incorrectly assume that Athanor and Tatara bonuses apply to moon drills. They do not. Moon drills always consume 120 blocks/day regardless of the refinery type.',

    'calculation_examples_title' => 'Calculation Examples',
    'calculation_examples' => '<div style="background: rgba(0,0,0,0.3); padding: 15px; border-radius: 8px; margin-top: 10px;">
        <h5>Example 1: Raitaru with Research Lab</h5>
        <ul>
            <li>Standup Research Lab I (provides 3 services) = <strong>9 blocks/hour</strong></li>
            <li>Total: <strong>9 blocks/hour</strong> or <strong>216 blocks/day</strong></li>
        </ul>

        <h5>Example 2: Azbel with Manufacturing + Research</h5>
        <ul>
            <li>Standup Manufacturing Plant I = 9 blocks/hour</li>
            <li>Standup Research Lab I = 9 blocks/hour</li>
            <li>Total: <strong>18 blocks/hour</strong> or <strong>432 blocks/day</strong></li>
        </ul>

        <h5>Example 3: Athanor with Moon Drill + Reprocessing</h5>
        <ul>
            <li>Moon Drill = 120 blocks/day (NO BONUS)</li>
            <li>Standup Reprocessing Facility I = 96 blocks/day (20% Athanor bonus applied)</li>
            <li>Total: <strong>216 blocks/day</strong></li>
        </ul>

    </div>',

    // Metenox Drills
    'metenox_title' => 'Metenox Moon Drills - Dual Fuel System',
    'metenox_intro' => 'Metenox Moon Drills introduced a new dual-fuel system to EVE Online. Structure Manager provides full support for tracking both fuel types.',

    'whats_different' => 'What\'s Different',
    'metenox_difference' => 'Unlike traditional moon drills that only require fuel blocks, Metenox Moon Drills require BOTH fuel blocks AND magmatic gas to operate. The plugin tracks both resources simultaneously.',

    'dual_fuel_system_title' => 'Dual Fuel Requirements',
    'dual_fuel_system_desc' => '<ul>
        <li><strong>Fuel Blocks:</strong> <span class="fuel-type-indicator fuel-blocks">120 blocks per day (5 per hour)</span></li>
        <li><strong>Magmatic Gas:</strong> <span class="fuel-type-indicator magmatic-gas">4,800 units per day (200 per hour)</span></li>
        <li><strong>Both resources are required</strong> - if either runs out, the drill stops</li>
    </ul>',

    'limiting_factor_title' => 'Limiting Factor Detection',
    'limiting_factor_intro' => 'Structure Manager automatically identifies which resource will run out first:',
    'limiting_factor_desc' => '<ul>
        <li><strong>Purple "LIMITING" badge:</strong> Shows which resource (fuel blocks or gas) will deplete first</li>
        <li><strong>Dual-status display:</strong> Both fuel types shown with remaining time for each</li>
        <li><strong>Priority alerts:</strong> Critical alerts highlight the limiting factor to help prioritize hauling</li>
        <li><strong>Smart calculations:</strong> All projections based on whichever resource runs out first</li>
    </ul>',

    'gas_tracking_title' => 'Magmatic Gas Reserve Tracking',
    'gas_tracking_intro' => 'The plugin tracks magmatic gas reserves in the same way it tracks fuel block reserves:',
    'gas_tracking_features' => '<ul>
        <li><strong>Corporation hangar monitoring:</strong> Scans all structures for staged magmatic gas</li>
        <li><strong>Purple visual indicators:</strong> Gas reserves marked with purple badges to distinguish from fuel blocks</li>
        <li><strong>Division tracking:</strong> Shows which hangar divisions contain gas reserves</li>
        <li><strong>Historical tracking:</strong> 3 months of gas reserve movement history</li>
        <li><strong>Reserve movements:</strong> Tracks when gas is added or removed from hangars</li>
    </ul>',

    'visual_indicators_title' => 'Visual Indicators',
    'visual_indicators_desc' => '<ul>
        <li><strong>Cyan badges</strong> <span class="fuel-type-indicator fuel-blocks">Fuel Blocks</span> represent fuel block data</li>
        <li><strong>Purple badges</strong> <span class="fuel-type-indicator magmatic-gas">Magmatic Gas</span> represent magmatic gas data</li>
        <li><strong>Purple "LIMITING" badge:</strong> Highlights which resource is the bottleneck</li>
        <li><strong>Dual-status boxes:</strong> Metenox structures show side-by-side status for both resources</li>
    </ul>',

    'logistics_metenox_title' => 'Logistics Planning for Metenox',
    'logistics_metenox_desc' => 'The logistics report automatically includes both fuel types for Metenox structures. Each system\'s fuel requirements will list fuel blocks and magmatic gas separately, with volume calculations for both.',

    'example_calculation' => 'Example Calculation',
    'metenox_example' => 'A Metenox drill with 100 fuel blocks (20 hours) and 48,000 gas (10 days) will show fuel blocks as LIMITING since gas will last longer. The plugin alerts you to prioritize fuel blocks hauling.',

    // POS Management
    'pos_management_title' => 'Player Owned Starbase (POS) Management',
    'pos_management_intro' => 'Complete fuel management for legacy Player Owned Starbases (POS towers), including fuel blocks, strontium clathrates, and starbase charters.',
    
    'pos_features' => 'POS Features',
    'pos_features_desc' => '<ul>
        <li><strong>Multi-Resource Tracking:</strong> Monitors fuel blocks, strontium clathrates, and starbase charters (in high-sec)</li>
        <li><strong>Smart Security Detection:</strong> Automatically detects system security level and enables charter tracking for high-sec</li>
        <li><strong>Tower Size Support:</strong> Accurate consumption rates for Small (10/hr), Medium (20/hr), and Large (40/hr) towers</li>
        <li><strong>Limiting Factor Detection:</strong> Identifies which resource (fuel, strontium, or charters) will run out first</li>
        <li><strong>Real-Time Fuel Tracking:</strong> Automated tracking every 10 minutes for fresh data</li>
        <li><strong>Daily Consumption Analysis:</strong> In-depth analysis runs daily at 01:00 AM</li>
        <li><strong>Historical Data:</strong> 90 days of fuel history retained for analysis</li>
        <li><strong>Reserve Tracking:</strong> Monitors staged fuel, strontium, and charters in corporation hangars</li>
        <li><strong>Discord/Slack Notifications:</strong> Real-time webhook alerts for critical fuel levels with customizable avatar</li>
    </ul>',
    
    'pos_resources' => 'POS Resource Types',
    'pos_fuel_blocks_title' => 'Fuel Blocks',
    'pos_fuel_blocks_desc' => 'Primary power source consumed based on tower size. Critical threshold: 7 days remaining. Warning threshold: 14 days remaining.',
    
    'pos_strontium_title' => 'Strontium Clathrates',
    'pos_strontium_desc' => 'Defensive resource used during reinforcement. Only consumed when tower is reinforced. Critical threshold: 6 hours. Warning threshold: 12 hours.',
    
    'pos_charters_title' => 'Starbase Charters',
    'pos_charters_desc' => 'Required in high-security space only (1 charter/hour). Not needed in low-sec, null-sec (both sovereign and NPC), or wormhole space. Critical threshold: 7 days. Warning threshold: 14 days.',
    
    'pos_consumption_rates' => 'POS Fuel Consumption Rates',
    'pos_consumption_table' => '<ul>
        <li><strong>Small Tower:</strong> 10 fuel blocks/hour (240/day)</li>
        <li><strong>Medium Tower:</strong> 20 fuel blocks/hour (480/day)</li>
        <li><strong>Large Tower:</strong> 40 fuel blocks/hour (960/day)</li>
        <li><strong>Charters (all sizes):</strong> 1 charter/hour (24/day) - high-security space only</li>
        <li><strong>Strontium:</strong> Only consumed during reinforcement timer (not tracked for daily consumption)</li>
    </ul>',
    
    'pos_limiting_factor' => 'Limiting Factor Detection',
    'pos_limiting_desc' => 'The plugin automatically identifies which resource will run out first, marked with a <strong>[LIMITING FACTOR]</strong> badge. This helps prioritize hauling operations - focus on the limiting resource first to maximize tower uptime.',
    
    'pos_security_space' => 'Security Space Detection',
    'pos_security_desc' => 'The plugin automatically detects system security levels:',
    'pos_security_list' => '<ul>
        <li><strong>High-Sec:</strong> Charters required and automatically tracked (1 charter/hour)</li>
        <li><strong>Low-Sec:</strong> No charters required or tracked</li>
        <li><strong>Null-Sec (Sovereign & NPC):</strong> No charters required or tracked</li>
        <li><strong>Wormhole Space:</strong> No charters required or tracked</li>
    </ul>',
    
    'pos_dashboard_features' => 'POS Dashboard Features',
    'pos_dashboard_desc' => '<ul>
        <li><strong>Status Overview:</strong> See all POSes with color-coded fuel status (Critical/Warning/Normal/Good)</li>
        <li><strong>Multiple Resource Display:</strong> View fuel, strontium, and charter levels simultaneously</li>
        <li><strong>Days Remaining:</strong> Precise calculations showing when each resource will deplete</li>
        <li><strong>Limiting Factor Badges:</strong> Visual indicators showing which resource needs priority</li>
        <li><strong>Location Information:</strong> System name, security status, and structure type</li>
        <li><strong>Quick Navigation:</strong> Click any POS to view detailed consumption history and analytics</li>
        <li><strong>Corporation Filtering:</strong> Filter POSes by corporation for multi-corp management</li>
    </ul>',
    
    'pos_detail_page' => 'POS Detail Page',
    'pos_detail_features' => '<ul>
        <li><strong>Resource Cards:</strong> Separate cards for fuel blocks, strontium, and charters (when applicable)</li>
        <li><strong>Consumption Graphs:</strong> Visual charts showing fuel usage over time</li>
        <li><strong>Historical Analysis:</strong> View 90 days of fuel tracking data</li>
        <li><strong>Refuel Events:</strong> Automatic detection and logging of refuel operations</li>
        <li><strong>Reserve Status:</strong> See staged fuel in corporation hangars</li>
        <li><strong>Projections:</strong> Estimates for 7, 14, 30, 60, and 90-day fuel requirements</li>
        <li><strong>Critical Alerts:</strong> In-page warnings for low resources with actionable information</li>
    </ul>',
    
    // Notifications Section
    'notifications_title' => 'Discord & Slack Notifications',
    'notifications_intro' => 'Structure Manager sends real-time alerts to Discord or Slack for POS fuel, Upwell structure fuel, and ESI-driven structure events (attacks, lifecycle, CCP fuel alerts). Notification configuration lives on a dedicated <strong>Notifications</strong> page (sidebar entry between Critical Alerts and Settings) where you manage categories, webhook bindings, and Discord role mentions independently.',

    // Notifications architecture overview — the three-concept design that
    // separates webhook delivery from category routing from role mentions.
    'v31_redesign_title' => 'The Notifications Page Architecture',
    'v31_redesign_intro' => 'Webhook delivery, notification categories, and role mentions are three separate concerns. This separation makes multi-corp and multi-channel setups dramatically easier than a flat per-webhook configuration.',
    'v31_redesign_concepts' => '<ul>
        <li><strong>Webhooks</strong> are pure delivery endpoints: a URL, an optional corporation filter, a description. Managed in Settings.</li>
        <li><strong>Notification categories</strong> are master toggles for each alert type (e.g. "Structure Under Attack", "POS Fuel", "Upwell Magmatic Gas"). Each category has its own default Discord role mention.</li>
        <li><strong>Bindings</strong> connect categories to webhooks. Each binding can override the category\'s default role mention for that specific webhook — so a single "Under Attack" notification can ping <code>@corp-fc</code> in the Corp Discord and <code>@alliance-fc</code> in the Alliance Discord simultaneously.</li>
    </ul>',
    'v31_category_namespaces_title' => 'Category Namespaces',
    'v31_category_namespaces_desc' => 'Categories are grouped into three namespaces for clarity and clean isolation:',
    'v31_category_namespaces_list' => '<ul>
        <li><strong>Upwell Structures</strong> — fuel + magmatic gas alerts for citadels, engineering complexes, refineries, Metenox moon drills. Driven by periodic fuel-bay polling.</li>
        <li><strong>Structure Events (ESI)</strong> — attack alerts, anchoring, ownership transfers, fuel events. Driven by EVE\'s notification stream via Manager Core fast-poll (or SeAT native if MC is absent).</li>
        <li><strong>POS (Legacy)</strong> — Player Owned Starbases. CCP legacy structures, marked with a LEGACY badge in the UI. Kept isolated so they can be cleanly removed if CCP eventually deprecates them.</li>
    </ul>',
    'v31_role_precedence_title' => 'Role Mention Precedence',
    'v31_role_precedence_desc' => 'When a notification fires, Structure Manager picks the role to ping from three tiers, L1 then L2 then L3. The first non-empty tier wins:',
    'v31_role_precedence_list' => '<ol>
        <li><strong>L1 (Binding role):</strong> the role set on the specific category-to-webhook binding, a per-webhook override</li>
        <li><strong>L2 (Category default role):</strong> the role set on the category itself</li>
        <li><strong>L3 (Webhook legacy role):</strong> the <code>role_mention</code> column on the webhook itself, carried over from the original release for backward compatibility</li>
        <li>No mention if all three tiers are empty</li>
    </ol>
    <p>The <strong>Routing Map</strong> tab in Settings shows the resolved tier (L1 / L2 / L3) for every category-to-webhook binding at a glance. Role-mention inputs also translate raw Discord role IDs into readable role names when a Discord role source is installed.</p>',
    'v31_role_picker_title' => 'Discord Role Picker',
    'v31_role_picker_desc' => 'When one or more Discord role sources are detected on your SeAT install, the Notifications page shows a role picker button next to every role-mention input. Clicking it opens a searchable dropdown populated from every installed source, deduplicated by Discord role ID and tagged with a source badge.',
    'v31_role_picker_sources' => '<strong>Supported sources (union all installed):</strong>
        <ul>
            <li><strong>mattfalahe/seat-discord-pings</strong> — reads the <code>discord_roles</code> table. Curated list with colors and pre-built mention strings. Preferred when roles exist in both sources.</li>
            <li><strong>warlof/seat-connector</strong> + <strong>warlof/seat-discord-connector</strong> — reads <code>seat_connector_sets</code> rows with <code>connector_type=\'discord\'</code>. Full guild-synced list, no colors.</li>
            <li><strong>Manual input</strong> — if no source is installed, the role-mention field accepts raw <code>&lt;@&amp;ROLE_ID&gt;</code> or numeric IDs.</li>
        </ul>',
    'v31_role_picker_behavior' => '<strong>Picker behavior when multiple sources are installed:</strong>
        <ul>
            <li>Both sources contribute roles — nothing is filtered out</li>
            <li>Roles appearing in both sources are shown once, using the richer source\'s data (color, name) and tagged with a "+N" indicator listing the other sources</li>
            <li>A source filter dropdown appears inside the picker so you can narrow by provider</li>
            <li>Picking a role stores the exact mention string from the source — if a source is uninstalled later, previously-picked roles keep working because the string is static</li>
        </ul>',
    'v31_category_list_title' => 'Shipped Categories (seeded on install)',
    'v31_category_list_desc' => 'v2.0.0 ships with 18 categories across three namespaces (upwell / events / pos). The eight listed below are the core set covering Upwell fuel, structure events, and POS legacy alerts. The remaining ten (cyno_reagents, services_offline, sovereignty, the six pre_timer_* reminders, and attacker_threat_intel) are documented in the dedicated feature sections of this help page. No webhooks are auto-bound on install — operator explicitly binds each category via the Notifications panel.',
    'v31_category_list' => '<table style="width:100%; border-collapse:collapse;">
        <thead><tr><th style="text-align:left; padding:6px; border-bottom:1px solid #454d55;">Namespace</th><th style="text-align:left; padding:6px; border-bottom:1px solid #454d55;">Category</th><th style="text-align:left; padding:6px; border-bottom:1px solid #454d55;">What triggers it</th></tr></thead>
        <tbody>
            <tr><td style="padding:6px;">upwell</td><td style="padding:6px;"><code>fuel</code></td><td style="padding:6px;">Upwell fuel bay below warning/critical/1h thresholds</td></tr>
            <tr><td style="padding:6px;">upwell</td><td style="padding:6px;"><code>magmatic_gas</code></td><td style="padding:6px;">Metenox gas supply below thresholds</td></tr>
            <tr><td style="padding:6px;">events</td><td style="padding:6px;"><code>structure_attack</code></td><td style="padding:6px;">UnderAttack, LostShields, LostArmor, Destroyed, Skyhook variants</td></tr>
            <tr><td style="padding:6px;">events</td><td style="padding:6px;"><code>structure_lifecycle</code></td><td style="padding:6px;">Anchoring, unanchoring, ownership transferred, skyhook deployed</td></tr>
            <tr><td style="padding:6px;">events</td><td style="padding:6px;"><code>structure_fuel_events</code></td><td style="padding:6px;">Low power, high power restored, services offline, CCP fuel alerts</td></tr>
            <tr><td style="padding:6px;">pos</td><td style="padding:6px;"><code>fuel</code></td><td style="padding:6px;">POS fuel blocks + sovereignty charter low-alerts</td></tr>
            <tr><td style="padding:6px;">pos</td><td style="padding:6px;"><code>strontium</code></td><td style="padding:6px;">Strontium clathrate reinforcement alerts</td></tr>
            <tr><td style="padding:6px;">pos</td><td style="padding:6px;"><code>lifecycle</code></td><td style="padding:6px;">POS state changes (online/offline/reinforced)</td></tr>
        </tbody>
    </table>',

    // ============================================================
    // Manager Core — overview (what it is, why install it, optional)
    // ============================================================
    'mc_overview_title' => 'Manager Core — Recommended Companion',
    'mc_overview_positioning' => '<strong>Important upgrade, not a hard requirement.</strong> Structure Manager v2.0.0 works perfectly on its own. Installing <a href="https://github.com/MattFalahe/Manager-Core" target="_blank" rel="noopener">Manager Core</a> alongside it unlocks faster detection, cross-plugin event broadcasting, and shared infrastructure that becomes more valuable as you add other Structure Manager-ecosystem plugins.',

    'mc_what_it_is_title' => 'What Manager Core Is',
    'mc_what_it_is_desc' => 'Manager Core is a foundational plugin for the Structure Manager ecosystem. Think of it as two things bundled together:',
    'mc_what_it_is_list' => '<ul>
        <li><strong>A central Event Bus</strong> — a pub/sub system plugins use to announce things (a structure got attacked, prices updated, a notification was received) and react to announcements from other plugins. With this, plugins stop having to integrate pairwise and instead integrate through one shared channel.</li>
        <li><strong>A shared ESI tool layer</strong> — fast-polling infrastructure, a director key holder pool, an ESI notification registry, pricing/appraisal/SDE services. Multiple plugins use these without each having to implement their own version.</li>
    </ul>',

    'mc_benefits_for_sm_title' => 'What Manager Core Gives Structure Manager',
    'mc_benefits_for_sm_list' => '<ul>
        <li><strong>~2-minute ESI attack detection</strong> via the shared fast-poll (vs. SeAT\'s native 20&ndash;30 minute bucket). Shield-down / armor-down / destroyed alerts land roughly 10x faster than SeAT\'s native cadence. Detection time depends on pool <em>composition</em> — adding directors from different corps speeds detection per corp; adding directors from the same corp adds fault tolerance. See the table in the ESI Events section for specifics. Authoritative architectural reference: <a href="https://github.com/MattFalahe/Manager-Core#-esi-fast-poll-deep-dive" target="_blank" rel="noopener">Manager Core README → ESI Fast-Poll deep dive</a>.</li>
        <li><strong>Shared director key pool</strong> — add your directors to Manager Core once; every MC-aware plugin uses the same pool. Adding a second or third plugin doesn\'t require reconfiguring directors. Auto-recovery on transient token failures plus a manual "Resume" button mean operators don\'t have to babysit the pool.</li>
        <li><strong>Fuel Economics page</strong>: weekly / monthly / quarterly / yearly fuel ISK projections, per-system + per-structure breakdowns, daily-trend chart, cheapest-fuel-block suggestion with optimization savings banner. Uses MC\'s pricing service for ISK conversion. Configurable market and price method per plugin in MC &rsaquo; Pricing Preferences. See the Fuel Economics section for details.</li>
        <li><strong>Cross-plugin events</strong> — Structure Manager publishes notification events on Manager Core\'s EventBus; other plugins (Mining Manager, SeAT Broadcast, Corp Wallet, HR Manager) subscribe and react via the same bus. Mining Manager already consumes <code>structure.alert.*</code> events for extraction-at-risk alerting; SeAT Broadcast will consume them for fleet-coverage calendaring. Unlocks features like automated FC pings at T-24h before a structure reinforces.</li>
        <li><strong>Better diagnostics</strong>: Structure Manager\'s diagnostic page queries MC\'s shared tables to show you the combined health picture (shared pool status, notification counts by source, registered handlers, pricing-integration status).</li>
        <li><strong>Future-proofing</strong> — as Structure Manager adds features like the Command Board (planned), the event-bus integration becomes the conduit for SeAT Broadcast calendar sync and other cross-plugin coordination.</li>
    </ul>',

    'mc_without_title' => 'If You Don\'t Install Manager Core',
    'mc_without_desc' => '<p>Structure Manager still does everything it documents:</p>
        <ul>
            <li>Full POS + Upwell fuel tracking</li>
            <li>All notification categories fire to all configured webhooks</li>
            <li>Critical alerts, logistics reports, fuel reserves, Metenox dual-fuel (unchanged)</li>
            <li>ESI attack notifications still detected via SeAT\'s native <code>character_notifications</code> sweep (15-20 min cadence)</li>
        </ul>
        <p>The only thing you lose is <strong>fast-poll speed</strong> and the <strong>cross-plugin integrations</strong> that ride on MC\'s event bus (Mining Manager extraction-at-risk alerts, SeAT Broadcast notification routing, upcoming fleet calendar). Nothing breaks, no data is lost, every current feature keeps working.</p>',

    'mc_install_title' => 'Installing Manager Core',
    'mc_install_steps' => '<p>SeAT Docker installs plugins from a list in the <code>.env</code> file at the root of your <code>seat-docker</code> directory; the container entrypoint runs composer-install on boot. <strong>Do not run <code>composer require</code> inside the running container</strong> — that change vanishes on the next container rebuild.</p>
        <ol>
            <li>Edit your seat-docker <code>.env</code> file and add <code>mattfalahe/manager-core</code> to the SeAT plugins list (typically the <code>SEAT_PLUGINS</code> environment variable, comma-separated with your existing plugins).</li>
            <li>Restart the SeAT stack so the entrypoint picks up the new plugin list, composer-installs it, and runs Manager Core\'s migrations automatically on container boot. Pass all three compose files so the database and reverse-proxy services come back correctly:
                <pre style="margin:4px 0;">docker compose -f docker-compose.yml -f docker-compose.mariadb.yml -f docker-compose.traefik.yml down
docker compose -f docker-compose.yml -f docker-compose.mariadb.yml -f docker-compose.traefik.yml up -d</pre>
            </li>
            <li>Watch the front container logs while it boots until SeAT reports it is ready:
                <pre style="margin:4px 0;">docker compose -f docker-compose.yml -f docker-compose.mariadb.yml -f docker-compose.traefik.yml logs -f front</pre>
                The migration phase will create Manager Core\'s tables; the bridge-discovery phase registers compatible plugins (Structure Manager included).</li>
            <li>Structure Manager auto-detects Manager Core at boot and registers its notification handler. No configuration changes needed in Structure Manager.</li>
            <li>Navigate to <code>Manager Core &gt; ESI Key Pool</code> (superuser only) and add one or more director characters. More directors = faster rotation + better fault tolerance.</li>
            <li>(Optional) Check <code>Structure Manager &gt; Diagnostics</code> &mdash; the ESI Notification Path panel should now show "Fast-poll via Manager Core" and confirm Structure Manager is registered.</li>
        </ol>
        <p style="margin-top:8px; font-size:0.9em;"><strong>Note:</strong> the exact environment variable name in your <code>.env</code> depends on which version of the <code>eveseat/seat-docker</code> template you started from. Recent versions use <code>SEAT_PLUGINS</code>; some older templates use a different name. Check your <code>.env</code> file for an existing plugin list (other plugins like <code>mattfalahe/structure-manager</code> are already in there) and add Manager Core to the same line.</p>',

    'mc_ecosystem_title' => 'The Broader Ecosystem',
    'mc_ecosystem_desc' => 'Manager Core is designed to serve multiple plugins, not just Structure Manager. Each plugin that integrates contributes different capabilities to the shared bus:',
    'mc_ecosystem_list' => '<ul>
        <li><strong>Structure Manager</strong> &mdash; publishes the <code>structure.alert.*</code> event family (shield_reinforced, armor_reinforced, hull_reinforced, destroyed, destroyed_confirmed, fuel_critical, fuel_recovered, anchoring_started, sov_reinforced, entosis_in_progress) and the <code>structure_manager.timer.*</code> family (created, updated, dismissed, elapsed, upcoming_24h, upcoming_1h, recovered). Registers an ESI notification handler on Manager Core\'s shared fast-poll. Consumes MC pricing for the Fuel Economics page.</li>
        <li><strong>Mining Manager</strong> &mdash; already integrated. Subscribes to <code>structure.alert.*</code> for extraction-at-risk alerts when a moon-mining refinery enters reinforce. Publishes its own <code>mining.*</code> event family (taxes, theft, jackpots, sessions, events). Uses MC\'s pricing service and the shared director key holder pool.</li>
        <li><strong>SeAT Broadcast [<code>seat-discord-pings</code>]</strong> &mdash; already integrated with Manager Core\'s EventBus. A planned calendar build will consume SM\'s tactical-planning events to render a fleet-coverage calendar with configurable pre-timer reminder pings (24h / 2h / 30min before reinforce / anchoring timers).</li>
        <li><strong>Corp Wallet Manager</strong> &mdash; integrated with Manager Core. Publishes <code>wallet.*</code> events for cross-plugin reactions (Mining Manager uses these for invoice payment matching).</li>
        <li><strong>HR Manager</strong> &mdash; integrated with Manager Core for cross-plugin member-lifecycle events. Used with Corp Wallet Manager for onboarding and accounting workflows.</li>
    </ul>
    <p style="margin-top:8px;">You don\'t need to install every plugin to benefit from Manager Core. Even for a solo Structure Manager install, MC gives you the ESI fast-poll. As you add more plugins, the event bus value multiplies.</p>',

    // Fuel Economics page (requires Manager Core for pricing)
    'economics_title' => 'Fuel Economics',
    'economics_intro' => 'The Fuel Economics page projects what your structures will cost in ISK over the next 90 / 180 / 365 days, broken down per system, per structure, and per fuel type. It uses the same active-services consumption math as the Logistics Report (so the per-structure rates match), multiplied by Manager Core\'s market prices. Hidden when Manager Core is not installed.',

    'economics_what_it_shows_title' => 'What the page shows',
    'economics_what_it_shows_html' => '<ul>
        <li><strong>4 totals cards</strong> at the top: Weekly / Monthly / Quarterly / Yearly fuel ISK across every structure you can see. The values scale linearly from a calculator-derived hourly rate, not from the consumption-tracker history, so projections are accurate even on a fresh install.</li>
        <li><strong>Cheapest fuel block banner:</strong> picks the lowest-priced of the 4 fuel block types right now (Nitrogen / Hydrogen / Helium / Oxygen) and uses it to price all Upwell + Metenox fuel-block projections. POS towers consume their racial type and can\'t substitute, so they price at racial regardless. Click "All 4 block prices" to see the comparison and verify the suggestion.</li>
        <li><strong>Optimization banner</strong> (amber, only shown when there\'s real savings): X structures are running on a more expensive fuel type than the cheapest. Switching them all would save Y ISK / month. Per-structure savings appear in the table below.</li>
        <li><strong>Structure breakdown banner:</strong> count of Upwell / Metenox / POS structures included in the projection, with race split for POSes (e.g. 2 Caldari + 1 Minmatar + 1 Gallente). Use this to verify the page is detecting every structure you expect.</li>
        <li><strong>By Solar System table:</strong> rows sorted by spend descending so the most-expensive systems are at the top.</li>
        <li><strong>Daily ISK Trend chart:</strong> stacked area over the look-back window. Flat-zero days mean the structure was offline (low-power) or the tracker had a gap.</li>
        <li><strong>By Fuel Type doughnut:</strong> period total split by fuel typeID. Useful for "should we bulk-buy a specific block this month" decisions.</li>
        <li><strong>By Structure table:</strong> per-structure rows with Current fuel + status (already optimal / switch suggestion / racial locked), Weekly / Monthly / Period total ISK, Monthly savings if optimizable, and Offline days.</li>
    </ul>',

    'economics_pricing_title' => 'How pricing works',
    'economics_pricing_html' => '<p>Manager Core\'s PricingService is the source of all ISK values. SM registers a pricing preference at boot (default: <strong>Jita SELL</strong>) and subscribes the 12 fuel-related typeIDs to MC\'s price-refresh system. MC fetches prices from ESI on its own schedule and caches them.</p>
    <p>You can override the market and price type per plugin in
        <a href="{{ url(\'manager-core/pricing-preferences\') }}"><strong>Manager Core &rsaquo; Pricing Preferences</strong></a>.
        Available markets default to Jita / Amarr / Dodixie / Hek / Rens. Available price types: SELL (cheapest sell order, what you pay) / BUY (highest buy order, what you get when selling) / AVG (midpoint).</p>
    <p><strong>Nullsec / lowsec operators:</strong> Manager Core supports adding custom citadel markets via <a href="{{ url(\'manager-core/markets\') }}"><strong>Manager Core &rsaquo; Markets</strong></a>. Point an authenticated character (with <code>esi-markets.structure_markets.v1</code> scope + docking access) at your alliance\'s local citadel and the Fuel Economics page will price fuel at that market instead. Useful when your structures are far from the canonical hubs and your alliance trades fuel at a local citadel hub. See MC\'s Pricing > Custom Markets section for full setup.</p>
    <p>The page header shows the current pricing source (e.g. "SELL on JITA - admin override"). When the source changes in MC, the next Economics page load reflects it (5-minute cache, or click Force refresh).</p>',

    'economics_substitutable_title' => 'Substitutable vs locked fuel',
    'economics_substitutable_html' => '<table style="width:100%; border-collapse:collapse; margin-top:8px;">
        <tr style="background:#2a2f3a;"><th style="padding:6px; text-align:left;">Structure type</th><th style="padding:6px; text-align:left;">Substitutable?</th><th style="padding:6px; text-align:left;">Pricing source</th></tr>
        <tr><td style="padding:6px;"><strong>Upwell</strong> (Astrahus, Fortizar, Sotiyo, etc.)</td><td style="padding:6px; color:#28a745;">Yes - 4 block types</td><td style="padding:6px;">Cheapest of 4 fuel blocks</td></tr>
        <tr><td style="padding:6px;"><strong>Metenox</strong> fuel-block side</td><td style="padding:6px; color:#28a745;">Yes - 4 block types</td><td style="padding:6px;">Cheapest of 4 fuel blocks</td></tr>
        <tr><td style="padding:6px;"><strong>Metenox</strong> magmatic gas side</td><td style="padding:6px; color:#dc3545;">No - fixed type 81143</td><td style="padding:6px;">Magmatic Gas only</td></tr>
        <tr><td style="padding:6px;"><strong>POS Control Tower</strong> fuel</td><td style="padding:6px; color:#dc3545;">No - racial only</td><td style="padding:6px;">Racial fuel block (Caldari/Minmatar/Amarr/Gallente)</td></tr>
        <tr><td style="padding:6px;"><strong>POS</strong> charters (high-sec only)</td><td style="padding:6px; color:#dc3545;">No - faction-specific</td><td style="padding:6px;">Actual charter typeID currently in fuel bay</td></tr>
    </table>
    <p style="margin-top:8px;">The "Current fuel" column on the per-structure table tells you which category each row falls into.</p>',

    'economics_offline_title' => 'Offline days detection',
    'economics_offline_html' => '<p>Real outage time, computed from <strong>fuel-history gaps</strong>. For each structure SM walks the history rows in time order. When a row\'s projected fuel_expires is BEFORE the next row\'s created_at, the gap between them = offline duration.</p>
    <pre style="background:#1f242c; padding:8px; border-radius:3px;">prev row:  created_at = day 5,  fuel_expires = day 10
next row:  created_at = day 15
        =&gt; fuel ran out at day 10
        =&gt; next snapshot arrived at day 15
        =&gt; offline duration = 5 days</pre>
    <p>Gaps shorter than 1 hour are ignored to avoid false positives from tracker scheduling jitter (a snapshot 5 minutes after fuel_expires probably means "just refueled" not "5-minute outage"). Only counts gaps INSIDE observed history, so a newly-installed tracker doesn\'t penalize structures for missing earlier days.</p>
    <p>Offline ISK equivalent = projected daily rate &times; offline days. That\'s "what fuel ISK would have been spent if those days had been active." Surfaces the cost-of-downtime per structure.</p>',

    'economics_settings_title' => 'Settings &rsaquo; Economics tab',
    'economics_settings_html' => '<p>SM Settings has a dedicated Economics tab with two main controls:</p>
    <ul>
        <li><strong>Mode dropdown:</strong> Auto (default - register with MC at boot, show page in sidebar) or Disabled (skip registration, hide the page even though MC is installed). Useful for operators who want to keep MC installed for ESI fast-poll only and not consume pricing.</li>
        <li><strong>Re-register now button:</strong> manually fires the boot-time registration call inside the user-request lifecycle. Use this when the diagnostic page reports "MC pricing reachable but Structure Manager has not registered a preference yet" - the registration is guaranteed to land. Idempotent: re-clicking is harmless.</li>
    </ul>
    <p>When MC is not installed, the same tab shows install instructions instead of the controls.</p>',

    'economics_diagnostic_title' => 'Diagnostic integration',
    'economics_diagnostic_html' => '<p>The Diagnostic page (admin-only at <code>/structure-manager/diagnostic</code>) has a "Pricing Integration (Manager Core)" health check that reports:</p>
    <ul>
        <li><strong>OK:</strong> registered with MC, all 12 fuel typeIDs cached</li>
        <li><strong>WARN:</strong> registered, but some typeIDs are missing prices (lists which ones). Click Re-register to subscribe + refresh.</li>
        <li><strong>INFO (operator-disabled):</strong> mode set to Disabled in SM Settings; page intentionally hidden. Deeplink to the settings tab.</li>
        <li><strong>INFO (MC absent):</strong> Manager Core not installed. Install link in the detail block.</li>
    </ul>
    <p>The check reads the live <code>manager_core_market_prices</code> table so it reflects MC\'s actual cache state, not just the registration row.</p>',

    // ESI events + Manager Core
    'esi_events_title' => 'ESI Events & Manager Core Integration',
    'esi_events_intro' => 'Structure attack alerts, anchoring notifications, and CCP fuel-alert messages come from EVE\'s ESI notification stream. Structure Manager has two detection paths depending on whether Manager Core is installed.',
    'esi_events_with_mc' => '<strong>With Manager Core installed (recommended):</strong>
        <ul>
            <li>Manager Core polls the ESI notifications endpoint every 2 minutes using an adaptive per-corp fair rotation, then dispatches new notifications to Structure Manager\'s <code>StructureEventHandler</code>.</li>
            <li>Detection drops from SeAT\'s native ~15-20 minutes to <strong>~2 minutes per corp</strong>. A corp with 1 director gets the same coverage as a corp with 50 (extra directors = fault tolerance, not speed).</li>
            <li>Cascade retry on CCP transient failures, auto-recovery on token issues, plus a 10-minute SeAT-native sweep as belt-and-braces safety net.</li>
            <li>Key holder pool is shared across every Manager Core-aware plugin — configure once in <strong>Manager Core → ESI Key Pool</strong>.</li>
        </ul>
        <p style="margin-top:8px;"><strong>Full operator reference</strong> (algorithm, scaling math, CCP rate-limit alignment, per-character cooldown ladder, troubleshooting) is in <strong>Manager Core → Help → ESI Fast-Poll</strong> in your SeAT install. For a Github summary or pre-install reading, see the <a href="https://github.com/MattFalahe/Manager-Core#-esi-fast-poll-one-paragraph-summary" target="_blank" rel="noopener">Manager Core README</a>.</p>',
    'esi_events_standalone' => '<strong>Without Manager Core (standalone):</strong>
        <ul>
            <li>Structure Manager reads from SeAT\'s native <code>character_notifications</code> table on its own schedule.</li>
            <li>Detection latency: ~15-20 minutes (set by SeAT\'s ESI bucket cadence — SeAT itself only refreshes <code>character_notifications</code> from CCP every 20 min).</li>
            <li>Same categories, same role mentions, same webhook routing — just slower because SeAT\'s upstream refresh is slow.</li>
            <li>No director key holders required. Works on a fresh SeAT install with zero configuration.</li>
            <li>The same shielding mechanics for category routing, role mentions, and webhook scoping all work identically.</li>
        </ul>',
    'esi_events_how_to_enable' => '<strong>Enabling fast-poll:</strong>
        <ol>
            <li>Install <a href="https://github.com/MattFalahe/Manager-Core" target="_blank">Manager Core</a> alongside Structure Manager</li>
            <li>On container restart, Manager Core\'s migrations create its shared tables</li>
            <li>Navigate to Manager Core > ESI Key Pool (superuser only)</li>
            <li>Add one or more director characters from the eligible-characters list. More directors = faster rotation + better fault tolerance.</li>
            <li>Structure Manager automatically detects Manager Core at boot and registers its handler. No configuration needed.</li>
        </ol>',
    'esi_events_detection_mode' => '<strong>Checking which mode is active:</strong> Settings > Structure Events tab shows the current detection mode banner. Diagnostics page also reports it with a per-mode health panel (shared key holders, recent notification counts, registered handler status).',

    // Detection path identification (added 2026-05-11 after end-to-end pipeline verification)
    'esi_events_paths_title' => 'Reading the Detection Path from Discord',
    'esi_events_paths_intro' => 'Every Structure Manager alert embed includes two pieces of evidence telling you which detection path delivered it: the <strong>Detection</strong> field inside the embed body, and the footer line under the embed. Use these to tell at a glance whether a fresh attack was caught by fast-poll, whether the sweep fallback picked it up, or whether you\'re running standalone without Manager Core.',
    'esi_events_paths_table' => '<table style="width:100%; border-collapse:collapse; margin-top:8px; font-size:0.9em;">
            <thead><tr style="background:#23262d; color:#dfe3eb;">
                <th style="padding:6px 10px; text-align:left;">Footer Label</th>
                <th style="padding:6px 10px; text-align:left;">Detection Field</th>
                <th style="padding:6px 10px; text-align:left;">What happened</th>
                <th style="padding:6px 10px; text-align:left;">Typical latency</th>
            </tr></thead>
            <tbody>
                <tr>
                    <td style="padding:6px 10px;"><strong>Fast Poll (Manager Core)</strong></td>
                    <td style="padding:6px 10px;"><code>via fast_poll</code></td>
                    <td style="padding:6px 10px;">Manager Core polled a director\'s ESI notifications endpoint directly and found this fresh from CCP. The expected path for new in-game events when MC is installed.</td>
                    <td style="padding:6px 10px;"><strong>~2 minutes</strong> from in-game event (per corp covered by the pool)</td>
                </tr>
                <tr>
                    <td style="padding:6px 10px;"><strong>SeAT Sweep (Manager Core)</strong></td>
                    <td style="padding:6px 10px;"><code>via seat_fallback</code></td>
                    <td style="padding:6px 10px;">Fast-poll missed it (key holder token expired transiently, ESI was down, etc.) so Manager Core\'s 10-minute sweep read it from SeAT\'s <code>character_notifications</code> table instead. Same routing, same embed, just slower.</td>
                    <td style="padding:6px 10px;">~10 min after SeAT itself sees it (15-20 min after the in-game event)</td>
                </tr>
                <tr>
                    <td style="padding:6px 10px;"><strong>SeAT Native</strong></td>
                    <td style="padding:6px 10px;"><code>via seat_native</code></td>
                    <td style="padding:6px 10px;">Manager Core is not installed (or the operator set <code>esi_detection_mode = seat_native</code> to opt out). Structure Manager\'s own <code>process-notifications</code> cron read from SeAT\'s native table and dispatched. This is the default standalone behaviour.</td>
                    <td style="padding:6px 10px;">~1 min after SeAT\'s 15-20 min bucket</td>
                </tr>
            </tbody>
        </table>
        <p style="margin-top:8px;">Both <strong>SeAT Sweep</strong> and <strong>SeAT Native</strong> wait on SeAT\'s 15-20 minute ESI bucket cadence (CCP only refreshes notifications via the bulk path once every 15-20 minutes per character). The difference is just routing: through MC\'s shared registry vs. SM\'s direct fallback. <strong>Fast Poll</strong> is the only path that beats SeAT\'s cadence by hitting CCP per-character at a tighter rotation.</p>',

    'esi_events_paths_testing_title' => 'Testing each detection path',
    'esi_events_paths_testing' => '<p><strong>Fast Poll test (Manager Core installed, mode=auto):</strong></p>
        <ul>
            <li>Have someone apply 1 point of damage to any structure in-game (a single shot fires <code>StructureUnderAttack</code> without entering reinforce)</li>
            <li>Within 1-2 minutes the Discord embed lands with footer <em>Fast Poll (Manager Core)</em></li>
            <li>You can\'t backdate or fake-inject a fast-poll detection; CCP only returns recent notifications from the ESI endpoint, so fresh events are the only way to exercise this path</li>
        </ul>

        <p style="margin-top:14px;"><strong>SeAT Sweep test (Manager Core installed, mode=auto):</strong></p>
        <ul>
            <li>Take a notification already present in <code>character_notifications</code> (something SeAT pulled in the last 24 hours)</li>
            <li>Reset its dedup state in both tables, then backdate its timestamp so it falls back into the 2-hour sweep window:
                <pre style="margin:4px 0;">DELETE FROM manager_core_esi_notifications WHERE notification_id = &lt;id&gt;;
DELETE FROM structure_manager_esi_notifications WHERE notification_id = &lt;id&gt;;
UPDATE character_notifications
   SET timestamp = NOW() - INTERVAL 30 MINUTE, updated_at = NOW()
 WHERE notification_id = &lt;id&gt;;</pre>
            </li>
            <li>Within ~60 seconds the next sweep cycle picks it up and the embed lands with footer <em>SeAT Sweep (Manager Core)</em></li>
        </ul>

        <p style="margin-top:14px;"><strong>SeAT Native test (without uninstalling Manager Core):</strong></p>
        <ul>
            <li>Switch the detection mode to seat_native:
                <pre style="margin:4px 0;">UPDATE structure_manager_settings
   SET value = \'seat_native\', updated_at = NOW()
 WHERE `key` = \'esi_detection_mode\';</pre>
            </li>
            <li>Restart the SeAT stack so SM stops registering with MC\'s registry (take it down and back up — pass all three compose files):
                <pre style="margin:4px 0;">docker compose -f docker-compose.yml -f docker-compose.mariadb.yml -f docker-compose.traefik.yml down
docker compose -f docker-compose.yml -f docker-compose.mariadb.yml -f docker-compose.traefik.yml up -d</pre>
            </li>
            <li>Apply the same backdating recipe as the SeAT Sweep test above. The minute-cron <code>structure-manager:process-notifications</code> will pick it up via SM\'s native fallback and the embed lands with footer <em>SeAT Native</em></li>
            <li>Revert when done: <code>UPDATE structure_manager_settings SET value = \'auto\' WHERE `key` = \'esi_detection_mode\';</code> then take the stack down and back up again</li>
        </ul>

        <p style="margin-top:14px;"><strong>Pro tip:</strong> grep the laravel log live in another window while testing to watch each step:</p>
        <pre style="margin:4px 0;">docker exec -it seat-docker-worker-1 sh -c "tail -F /var/www/seat/storage/logs/laravel-$(date +%Y-%m-%d).log | grep -E \'PollEsi|SweepSeat|ProcessStructureNotifications|StructureEventHandler|Dispatched: [1-9]\'"</pre>',

    // ============================================================
    // Pre-timer reminders (added 2026-05-17, dev-4.0 / v2.1)
    // ============================================================
    // Scheduled Discord pings 24h/6h/1h before a structure timer expires.
    // Requires Manager Core (handler subscribes to MC's EventBus).
    'pre_timer_title' => 'Pre-Timer Reminder Pings (T-24h / T-6h / T-1h)',
    'pre_timer_intro' => 'When a structure enters reinforce (or a manual op is scheduled), Structure Manager fires <strong>three scheduled reminder pings to Discord</strong> at <code>T-24h</code>, <code>T-6h</code>, and <code>T-1h</code> before the timer expires. This gives fleet leadership a predictable rhythm to plan: tomorrow we organize, this evening we finalize, in an hour we ping fleet to login. Reminders are <strong>separate from the under-attack alert</strong> that fires the moment CCP says your citadel was just shot - that channel still pings immediately, this one is the scheduled follow-up so the timer never sneaks up on the fleet.',
    'pre_timer_requires_mc' => '<strong>Manager Core required.</strong> The reminder system is built on MC\'s EventBus and scheduled-event infrastructure. Without MC, Structure Manager still fires under-attack alerts via SeAT\'s native notification path - it just won\'t produce the scheduled T-24h/6h/1h reminders. Install Manager Core to unlock this feature.',
    'pre_timer_event_types_title' => 'Which timers fire reminders',
    'pre_timer_event_types_desc' => 'Reminders fire automatically for the timer types where fleet logistics matter most. Manual ops are opt-in so admins who schedule an op deliberately can decide whether to reminder-ping or not.',
    'pre_timer_event_types_table' => '<table style="width:100%; border-collapse:collapse; margin-top:8px; font-size:0.9em;">
            <thead><tr style="background:#23262d; color:#dfe3eb;">
                <th style="padding:6px 10px; text-align:left;">Event Type</th>
                <th style="padding:6px 10px; text-align:left;">Fires Reminder?</th>
                <th style="padding:6px 10px; text-align:left;">Reason</th>
            </tr></thead>
            <tbody>
                <tr>
                    <td style="padding:6px 10px;"><strong>Armor Reinforced</strong></td>
                    <td style="padding:6px 10px; color:#22c55e;">YES (always)</td>
                    <td style="padding:6px 10px;">Hull timer ~2-3 days out; fleet needs planning lead time</td>
                </tr>
                <tr>
                    <td style="padding:6px 10px;"><strong>Hull Reinforced</strong></td>
                    <td style="padding:6px 10px; color:#22c55e;">YES (always)</td>
                    <td style="padding:6px 10px;">Final defense fleet must coordinate before decloak</td>
                </tr>
                <tr>
                    <td style="padding:6px 10px;"><strong>Sov Reinforced</strong></td>
                    <td style="padding:6px 10px; color:#22c55e;">YES (always)</td>
                    <td style="padding:6px 10px;">TCU/IHub decloak window - sov fleet form-up</td>
                </tr>
                <tr>
                    <td style="padding:6px 10px;"><strong>Command Nodes Spawning</strong></td>
                    <td style="padding:6px 10px; color:#22c55e;">YES (always)</td>
                    <td style="padding:6px 10px;">Sov capture phase begins; entosis fleet needed</td>
                </tr>
                <tr>
                    <td style="padding:6px 10px;"><strong>Hostile Op</strong></td>
                    <td style="padding:6px 10px; color:#f59e0b;">YES (opt-in)</td>
                    <td style="padding:6px 10px;">Manual op scheduled by admin; toggle on Settings if you want auto-reminders</td>
                </tr>
                <tr>
                    <td style="padding:6px 10px;"><strong>Defense Op</strong></td>
                    <td style="padding:6px 10px; color:#f59e0b;">YES (opt-in)</td>
                    <td style="padding:6px 10px;">Manual op scheduled by admin; toggle on Settings if you want auto-reminders</td>
                </tr>
                <tr>
                    <td style="padding:6px 10px;"><strong>Shield Reinforced</strong></td>
                    <td style="padding:6px 10px; color:#ef4444;">NO</td>
                    <td style="padding:6px 10px;">Under-attack alert fires immediately; reminder would duplicate</td>
                </tr>
                <tr>
                    <td style="padding:6px 10px;"><strong>Destroyed</strong></td>
                    <td style="padding:6px 10px; color:#ef4444;">NO</td>
                    <td style="padding:6px 10px;">Post-event; reminders make no sense after the fact</td>
                </tr>
                <tr>
                    <td style="padding:6px 10px;"><strong>Entosis Active</strong></td>
                    <td style="padding:6px 10px; color:#ef4444;">NO</td>
                    <td style="padding:6px 10px;">Happening RIGHT NOW (40-min window) - no lead time to remind on</td>
                </tr>
                <tr>
                    <td style="padding:6px 10px;"><strong>Fuel / Anchor / Ownership</strong></td>
                    <td style="padding:6px 10px; color:#ef4444;">NO</td>
                    <td style="padding:6px 10px;">Different audience (logistics / industry), not fleet ops</td>
                </tr>
            </tbody>
        </table>',
    'pre_timer_routing_title' => 'Routing reminders to webhooks (per event type)',
    'pre_timer_routing_desc' => 'Each timer event type that fires reminders gets its own notification category, so admins can route sov reminders to <code>#sov-fleet</code> with <code>@SovFC</code>, hull reminders to <code>#all-hands</code> with <code>@everyone</code>, and so on - all through the existing <strong>Settings &gt; Notifications</strong> panel. No new UI surface; the same per-category toggles, role-mention precedence, per-binding overrides, and role-picker integration you already use for under-attack alerts now extend to reminders.',
    'pre_timer_categories_table' => '<table style="width:100%; border-collapse:collapse; margin-top:8px; font-size:0.9em;">
            <thead><tr style="background:#23262d; color:#dfe3eb;">
                <th style="padding:6px 10px; text-align:left;">Category</th>
                <th style="padding:6px 10px; text-align:left;">Fires when</th>
                <th style="padding:6px 10px; text-align:left;">Default state</th>
            </tr></thead>
            <tbody>
                <tr>
                    <td style="padding:6px 10px;"><code>events.pre_timer_armor</code></td>
                    <td style="padding:6px 10px;">Armor reinforce timer (hull cycle)</td>
                    <td style="padding:6px 10px; color:#22c55e;">Enabled + auto-bound to structure_attack webhooks</td>
                </tr>
                <tr>
                    <td style="padding:6px 10px;"><code>events.pre_timer_hull</code></td>
                    <td style="padding:6px 10px;">Hull reinforce timer (final defense)</td>
                    <td style="padding:6px 10px; color:#22c55e;">Enabled + auto-bound</td>
                </tr>
                <tr>
                    <td style="padding:6px 10px;"><code>events.pre_timer_sov</code></td>
                    <td style="padding:6px 10px;">Sov structure reinforced (TCU / IHub decloak)</td>
                    <td style="padding:6px 10px; color:#22c55e;">Enabled + auto-bound</td>
                </tr>
                <tr>
                    <td style="padding:6px 10px;"><code>events.pre_timer_nodes</code></td>
                    <td style="padding:6px 10px;">Command nodes spawning (sov capture)</td>
                    <td style="padding:6px 10px; color:#22c55e;">Enabled + auto-bound</td>
                </tr>
                <tr>
                    <td style="padding:6px 10px;"><code>events.pre_timer_hostile</code></td>
                    <td style="padding:6px 10px;">Admin-created hostile op</td>
                    <td style="padding:6px 10px; color:#f59e0b;">Disabled (opt-in via category toggle)</td>
                </tr>
                <tr>
                    <td style="padding:6px 10px;"><code>events.pre_timer_defense</code></td>
                    <td style="padding:6px 10px;">Admin-created defense op</td>
                    <td style="padding:6px 10px; color:#f59e0b;">Disabled (opt-in via category toggle)</td>
                </tr>
            </tbody>
        </table>
        <p style="margin-top:8px;">On a fresh install the four combat categories auto-bind to whatever webhooks already receive <code>events.structure_attack</code> alerts, so the default behavior matches existing routing. From there: re-route each category to its own channel, set a different role mention per category, override per-binding (a webhook can have its own role mention that wins over the category default), or disable a category entirely if you decide a particular event type shouldn\'t fire reminders.</p>',
    'pre_timer_settings_title' => 'Settings',
    'pre_timer_settings_list' => '<ul style="margin-top:6px;">
            <li><code>pre_timer_reminders_enabled</code> - <strong>master kill-switch.</strong> Default <code>true</code> when Manager Core is installed. Turn this off to silence all reminders in one click, regardless of category bindings (useful for downtime, fleet stand-downs, or testing without spamming Discord).</li>
        </ul>
        <p style="margin-top:8px; font-size:0.9em; color:#9ca3af;">Granular control (which event types fire, where they go, what role mention they ping) lives in <strong>Settings &gt; Notifications</strong> on the six <code>pre_timer_*</code> categories. Edits take effect immediately - no worker restart required (the handler reads settings + bindings on every dispatch).</p>',
    'pre_timer_cadence_title' => 'When reminders fire (the math)',
    'pre_timer_cadence_desc' => 'Structure Manager runs a scheduled job (<code>structure-manager:publish-timer-schedule-events</code>) every 5 minutes that scans active timers and fires <code>timer.upcoming_24h</code>, <code>timer.upcoming_6h</code>, and <code>timer.upcoming_1h</code> events when a timer enters each window. Each event fires <strong>at most once per timer</strong> thanks to per-window latch columns - even if the job runs 12 times while a timer is in the 24h window, only the first run fires the event.',
    'pre_timer_cadence_table' => '<table style="width:100%; border-collapse:collapse; margin-top:8px; font-size:0.9em;">
            <thead><tr style="background:#23262d; color:#dfe3eb;">
                <th style="padding:6px 10px; text-align:left;">Window</th>
                <th style="padding:6px 10px; text-align:left;">When the reminder lands</th>
                <th style="padding:6px 10px; text-align:left;">Embed color</th>
                <th style="padding:6px 10px; text-align:left;">Audience intent</th>
            </tr></thead>
            <tbody>
                <tr>
                    <td style="padding:6px 10px;"><strong>T-24h</strong></td>
                    <td style="padding:6px 10px;">23h 55m - 24h 00m before <code>eve_time</code></td>
                    <td style="padding:6px 10px;"><span style="display:inline-block; width:12px; height:12px; background:#f59e0b; border-radius:2px; vertical-align:middle;"></span> Amber</td>
                    <td style="padding:6px 10px;">Planning - "tomorrow at this time, organize fleet"</td>
                </tr>
                <tr>
                    <td style="padding:6px 10px;"><strong>T-6h</strong></td>
                    <td style="padding:6px 10px;">5h 55m - 6h 00m before <code>eve_time</code></td>
                    <td style="padding:6px 10px;"><span style="display:inline-block; width:12px; height:12px; background:#ea580c; border-radius:2px; vertical-align:middle;"></span> Orange</td>
                    <td style="padding:6px 10px;">Preparation - "tonight, finalize roster + doctrine"</td>
                </tr>
                <tr>
                    <td style="padding:6px 10px;"><strong>T-1h</strong></td>
                    <td style="padding:6px 10px;">55m - 60m before <code>eve_time</code></td>
                    <td style="padding:6px 10px;"><span style="display:inline-block; width:12px; height:12px; background:#dc2626; border-radius:2px; vertical-align:middle;"></span> Red</td>
                    <td style="padding:6px 10px;">Pre-fleet - "ping fleet to login, undock in 30"</td>
                </tr>
            </tbody>
        </table>
        <p style="margin-top:8px; font-size:0.9em; color:#9ca3af;">The 5-minute scan cadence means an FC gets <em>between 55 and 60 minutes</em> of warning for the T-1h ping (not exactly 60). Acceptable for fleet ops - and there is no way to be more precise without a per-second scheduler. The same imprecision applies to the 6h and 24h windows but matters less the further out you are.</p>',
    'pre_timer_v21_badge' => 'v2.0.0',

    // ============================================================
    // Attacker threat intel (added 2026-05-17, dev-4.0 / v2.2)
    // ============================================================
    // Opt-in async zKillboard enrichment fired after each under-attack alert.
    'threat_intel_title' => 'Attacker Threat Intel (zKillboard enrichment)',
    'threat_intel_intro' => 'Optional follow-up Discord embed dispatched <strong>~1-2 seconds after</strong> each attack notification (structure under attack, shield reinforce, armor reinforce, sov reinforce, entosis). Looks up the attacker on zKillboard and posts a separate <em>"who is shooting you"</em> embed with the attacker\'s kill count, top-flown ship, danger ratio, gang ratio, last activity date, and a synthesized threat tier ("Professional", "Active", "Casual", "Dormant", "Cold"). Lets the FC decide fleet form-up based on threat assessment before the cyno even lands.',
    'threat_intel_opt_in' => '<strong>Opt-in by design.</strong> Disabled by default because the feature makes external HTTP calls to <code>zkillboard.com</code> every time an attack alert fires. Operators who want it explicitly enable both the master toggle (Settings &gt; Structure Events) AND bind the <code>events.attacker_threat_intel</code> category to a webhook on the Notifications panel.',
    'threat_intel_async_title' => 'Why a separate async embed',
    'threat_intel_async_desc' => 'The primary under-attack alert is time-critical and must NOT wait on zKB. Inline lookups would add 200-2000ms to dispatch and bottleneck during a major op. Splitting threat intel into its own async job + separate webhook category solves three problems simultaneously:',
    'threat_intel_async_list' => '<ol style="margin-top:6px;">
            <li><strong>Speed:</strong> primary alert lands in fleet channels within seconds of ESI detection (unchanged). Threat intel arrives ~1-2s later, after zKB responds.</li>
            <li><strong>Fail-open:</strong> if zKB is down, timeout, rate-limited, or doesn\'t have the attacker indexed, the primary alert is unaffected. Threat intel embed is simply skipped silently.</li>
            <li><strong>Routing:</strong> intel typically goes to a different Discord channel than the primary alert (intel team vs. fleet response team). Separate category = separate routing + separate role mention without coupling.</li>
        </ol>',
    'threat_intel_what_zkb_sees' => 'What zKillboard receives + returns',
    'threat_intel_data_flow' => '<table style="width:100%; border-collapse:collapse; margin-top:8px; font-size:0.9em;">
            <thead><tr style="background:#23262d; color:#dfe3eb;">
                <th style="padding:6px 10px; text-align:left;">Direction</th>
                <th style="padding:6px 10px; text-align:left;">Data</th>
                <th style="padding:6px 10px; text-align:left;">Sensitivity</th>
            </tr></thead>
            <tbody>
                <tr>
                    <td style="padding:6px 10px;">SeAT → zKB</td>
                    <td style="padding:6px 10px;">Attacker\'s character ID (public field on every killmail)</td>
                    <td style="padding:6px 10px; color:#22c55e;">Public — already on every zKB-indexed killmail</td>
                </tr>
                <tr>
                    <td style="padding:6px 10px;">zKB → SeAT</td>
                    <td style="padding:6px 10px;">Public stats: lifetime kills/losses, most-flown ship, danger/gang ratio, recent activity months</td>
                    <td style="padding:6px 10px; color:#22c55e;">Public — same data shown on zKB\'s web profile</td>
                </tr>
                <tr>
                    <td style="padding:6px 10px;">SeAT → zKB</td>
                    <td style="padding:6px 10px;"><em>(nothing else)</em></td>
                    <td style="padding:6px 10px; color:#22c55e;">No defender data, no structure name, no corp data, no system, no timer leaves SeAT</td>
                </tr>
            </tbody>
        </table>
        <p style="margin-top:8px;">Opsec-wise this is safe: only attacker char IDs leave SeAT, and those IDs are already public the moment zKB indexes any killmail involving that pilot. No defender intel is exposed.</p>',
    'threat_intel_tiers_title' => 'Threat tier classification',
    'threat_intel_tiers_desc' => 'Raw kill counts aren\'t actionable on their own. The embed synthesizes a single tier label from kill cadence + last activity so the FC gets a one-glance read:',
    'threat_intel_tiers_table' => '<table style="width:100%; border-collapse:collapse; margin-top:8px; font-size:0.9em;">
            <thead><tr style="background:#23262d; color:#dfe3eb;">
                <th style="padding:6px 10px; text-align:left;">Tier</th>
                <th style="padding:6px 10px; text-align:left;">Threshold</th>
                <th style="padding:6px 10px; text-align:left;">Likely meaning</th>
            </tr></thead>
            <tbody>
                <tr>
                    <td style="padding:6px 10px;">🔥 Professional</td>
                    <td style="padding:6px 10px;">≥50 kills last ~30 days</td>
                    <td style="padding:6px 10px;">Active killer/hunter; structure shooter; expect coordinated follow-up</td>
                </tr>
                <tr>
                    <td style="padding:6px 10px;">⚠️ Active</td>
                    <td style="padding:6px 10px;">≥10 kills last ~30 days</td>
                    <td style="padding:6px 10px;">Regular PvP pilot; competent threat but not a dedicated structure shooter</td>
                </tr>
                <tr>
                    <td style="padding:6px 10px;">🔍 Casual</td>
                    <td style="padding:6px 10px;">1-9 kills last ~30 days</td>
                    <td style="padding:6px 10px;">Occasional PvP; opportunist; defensive posture probably sufficient</td>
                </tr>
                <tr>
                    <td style="padding:6px 10px;">💤 Dormant</td>
                    <td style="padding:6px 10px;">No kills in 90+ days</td>
                    <td style="padding:6px 10px;">Returning player or alpha trial; lower threat than raw lifetime count suggests</td>
                </tr>
                <tr>
                    <td style="padding:6px 10px;">❄️ Cold</td>
                    <td style="padding:6px 10px;">No recent activity tracked</td>
                    <td style="padding:6px 10px;">No data on zKB (newbro / never killed before)</td>
                </tr>
            </tbody>
        </table>',
    'threat_intel_setup_title' => 'How to enable',
    'threat_intel_setup_list' => '<ol style="margin-top:6px;">
            <li>Toggle <strong>Enable attacker threat intel</strong> on the <strong>Settings &gt; Structure Events</strong> tab.</li>
            <li>On the <strong>Settings &gt; Notifications</strong> tab, find the <code>events.attacker_threat_intel</code> category (sort order 80, near the bottom of events).</li>
            <li>Enable the category\'s master toggle.</li>
            <li>Bind the category to one or more webhooks (typically a dedicated intel channel separate from your fleet-response channel).</li>
            <li>Set a role mention if desired (e.g. <code>@IntelOfficer</code> or <code>@SeniorFC</code>).</li>
            <li>Test by waiting for the next attack alert — the intel embed lands ~1-2 seconds after the primary alert.</li>
        </ol>',
    'threat_intel_caching_title' => 'Caching + rate limits',
    'threat_intel_caching_desc' => 'Attacker profiles cache for <strong>7 days</strong> in Laravel\'s cache (Redis on a standard SeAT install). Repeat attackers in coordinated ops resolve from cache without re-querying zKB, so even a sustained assault from one fleet only hits zKB once per attacker per week. zKB rate limit responses (HTTP 429) cache as a 1-hour miss so the system recovers automatically without hammering.',
    'threat_intel_v22_badge' => 'v2.0.0',

    // ============================================================
    // Final-timer awareness (added 2026-05-17, v2.0.0 release)
    // ============================================================
    // Surfaces in alerts + board + reminders for structures with no
    // separate hull reinforce timer (mediums, FLEX, Metenox, Skyhook).
    'final_timer_title' => 'FINAL TIMER awareness',
    'final_timer_intro' => 'EVE Online structures don\'t all share the same reinforce cycle. <strong>Medium Upwell structures</strong> (Astrahus, Raitaru, Athanor), <strong>FLEX navigation</strong> (Ansiblex, Pharolux, Tenebrex), and the <strong>Equinox single-cycle structures</strong> (Metenox Moon Drill, Orbital Skyhook) have <strong>no separate hull reinforce timer</strong>. When the armor cycle elapses, defenders get ONE fight to keep the structure. If armor falls, hull comes down in the same window and the structure dies. <strong>Large/XL structures</strong> (Fortizar, Tatara, Azbel, Keepstar, Sotiyo, Palatine Keepstar) get TWO reinforce cycles, giving defenders a second chance at the hull timer.',
    'final_timer_surfaces' => '<p>To make this difference operationally visible, Structure Manager surfaces a FINAL TIMER indicator in three places:</p>
        <ol style="margin-top:6px;">
            <li><strong>Under-attack Discord embed</strong> (<code>StructureLostShields</code> and friends): a <code>🚨 FINAL TIMER</code> field appears prominently in the embed with copy explaining "no hull reinforce follows". The Discord push-notification preview is also amplified so FCs see the stakes before clicking through.</li>
            <li><strong>Pre-timer reminder embeds</strong> (T-24h / T-6h / T-1h): same indicator, so fleet planning is informed by whether you have one fight or two.</li>
            <li><strong>Structure Board badge</strong>: a red <code>⚠ FINAL TIMER</code> pill next to the event label. Visually distinct from the standard category badge so a packed board scans correctly.</li>
        </ol>
        <p style="margin-top:8px;">All notification stages still fire normally (Shield / Armor / Hull / Destroyed) — this is purely informational. The change is in what the operator sees, not in which events get processed.</p>',
    'final_timer_classification' => '<table style="width:100%; border-collapse:collapse; margin-top:8px; font-size:0.9em;">
            <thead><tr style="background:#23262d; color:#dfe3eb;">
                <th style="padding:6px 10px; text-align:left;">Structure family</th>
                <th style="padding:6px 10px; text-align:left;">Has hull reinforce timer?</th>
                <th style="padding:6px 10px; text-align:left;">FINAL TIMER marker?</th>
            </tr></thead>
            <tbody>
                <tr>
                    <td style="padding:6px 10px;">Medium Upwell (Astrahus, Raitaru, Athanor)</td>
                    <td style="padding:6px 10px; color:#ef4444;">No (skip hull state)</td>
                    <td style="padding:6px 10px; color:#22c55e;">YES on armor reinforce</td>
                </tr>
                <tr>
                    <td style="padding:6px 10px;">FLEX navigation (Ansiblex, Pharolux, Tenebrex)</td>
                    <td style="padding:6px 10px; color:#ef4444;">No (direct vulnerability)</td>
                    <td style="padding:6px 10px; color:#22c55e;">YES</td>
                </tr>
                <tr>
                    <td style="padding:6px 10px;">Equinox single-cycle (Metenox, Orbital Skyhook)</td>
                    <td style="padding:6px 10px; color:#ef4444;">No (one short cycle total)</td>
                    <td style="padding:6px 10px; color:#22c55e;">YES</td>
                </tr>
                <tr>
                    <td style="padding:6px 10px;">Large Upwell (Azbel, Fortizar, Tatara)</td>
                    <td style="padding:6px 10px; color:#22c55e;">Yes (separate armor + hull cycles)</td>
                    <td style="padding:6px 10px; color:#9ca3af;">No (defenders get hull timer too)</td>
                </tr>
                <tr>
                    <td style="padding:6px 10px;">XL Upwell (Sotiyo, Keepstar, Palatine Keepstar)</td>
                    <td style="padding:6px 10px; color:#22c55e;">Yes (separate cycles)</td>
                    <td style="padding:6px 10px; color:#9ca3af;">No</td>
                </tr>
            </tbody>
        </table>',
    'final_timer_design' => 'The classification lives in <code>StructureTimerMechanics::ARMOR_IS_FINAL_TYPE_IDS</code>. CCP doesn\'t expose this design intent as a queryable SDE attribute, so the list is hardcoded and reviewed against CCP patch notes. When CCP introduces a new single-cycle structure (rare), its typeID gets added in the same commit that updates these docs. Manual operator-created ops on the board never carry the FINAL TIMER badge — operators write their own framing in the timer notes field.',
    'final_timer_v23_badge' => 'v2.0.0',

    // Operational security stance on tactical data (added 2026-05-12 after explicit
    // discussion of why ICS calendar export will never ship)
    'opsec_title' => 'Operational Security: Tactical Data Boundaries',
    'opsec_intro' => 'Structure timers are <strong>military intelligence</strong> in EVE. When a structure enters reinforce, the exact moment it comes out is the most valuable piece of information an attacker can have to time fleet form-up, mid arrivals, and engagement windows. Structure Manager treats this data accordingly: timer information stays inside two trust zones and never leaves them.',
    'opsec_trust_zones' => '<strong>Trust zones for timer data:</strong>
        <ol>
            <li><strong>Inside SeAT itself</strong> (the Structure Board, fuel pages, diagnostic views). Behind your SeAT authentication, your operators see what your permissions model lets them see.</li>
            <li><strong>Discord/Slack webhooks you control</strong>. Operators paste in their own corp/alliance channel webhook URL. Channel access is governed by your Discord/Slack role model. SeAT does not store any data on the destination server.</li>
        </ol>',
    'opsec_no_external_export_title' => 'Why we will never add ICS / iCal / calendar feed export',
    'opsec_no_external_export' => '<p>A recurring request for plugins of this kind is "export upcoming reinforce timers as an iCalendar (.ics) feed so operators can subscribe in Google Calendar / Apple Calendar / Outlook." It would be one of the easiest features to build technically. <strong>It will not ship.</strong> Reasons:</p>
        <ul>
            <li><strong>Calendar services can read the data.</strong> Google, Apple, and Microsoft staff have audit access to user calendar contents under their terms of service. A spy with access to a calendar provider could enumerate timer data across many operators systematically.</li>
            <li><strong>Subscription URLs leak.</strong> ICS subscriptions typically use unauthenticated or weakly-authenticated URLs because most clients do not support OAuth flows. A URL share, accidental commit, or screenshot exposes the entire timer set to anyone with the link.</li>
            <li><strong>Sync everywhere.</strong> Subscribing in Google Calendar replicates the data to the operator\'s phone, cloud backups, sometimes their watch, and any shared calendars they participate in. Surface area for accidental disclosure grows dramatically.</li>
            <li><strong>Aggregation risk.</strong> A predictable URL scheme could be enumerated by automated scrapers across SeAT installs and shared on third-party intel sites.</li>
            <li><strong>Operator chain-of-custody breaks down.</strong> An operator forwards their calendar to a friend for unrelated reasons. The friend turns out to be a spy. The defender did nothing wrong yet handed the attacker hours of preparation time.</li>
        </ul>
        <p>None of these failure modes apply to the trust zones we DO support: SeAT auth-gates everything inside the app, and Discord webhook channels are operator-controlled with the operator\'s own role model.</p>',
    'opsec_alternatives_title' => 'How to get the same operational value without leaking timer data',
    'opsec_alternatives' => '<ul>
            <li><strong>Use the Structure Board.</strong> All upcoming and active timers are visible there, mobile-responsive so FCs can pull up the page on a phone during ops. Stays inside SeAT auth.</li>
            <li><strong>Trust the existing Discord pings.</strong> The same channel that already receives <code>StructureLostShields</code> and <code>StructureLostArmor</code> alerts is the operator-controlled trust zone for timer info. Pre-timer reminder pings (future enhancement: 24h / 1h / 15min before reinforce expires) will use the same webhooks — same trust profile, same role mention setup.</li>
            <li><strong>Manual abstraction.</strong> If an operator personally needs a timer in their calendar, they can write "structure timer ends ~21:30" manually — without dates, structure names, or location. The calendar has a reminder; the data has no value to a leak.</li>
        </ul>',
    // IdResolver feature documentation (added 2026-05-12 for v2.0.0)
    'id_resolver_title' => 'Name Resolution via Public ESI',
    'id_resolver_intro' => 'Discord embeds for combat events show real character / corporation / alliance names where possible — even when the attacker has never been a member of any corp on this SeAT install. Structure Manager achieves this through a three-tier name resolver (<code>IdResolver</code> service) that escalates from local DB to public ESI with a 7-day cache.',
    'id_resolver_chain' => '<strong>Resolution chain (per ID lookup):</strong>
        <ol>
            <li><strong>SeAT local info table</strong> — <code>character_infos</code> / <code>corporation_infos</code> / <code>alliance_infos</code>. Instant, free. Hit rate is high for entities your operators are members of or have interacted with.</li>
            <li><strong>SeAT universe_names cache</strong> — secondary lookup table SeAT uses for bulk name resolution. Catches entities that SeAT has touched via killmail enrichment, bulk lookups, etc.</li>
            <li><strong>CCP public ESI endpoint</strong> — <code>esi.evetech.net/latest/characters/{id}/</code> (and equivalent for corps + alliances). Public, no auth needed. 2-second timeout to keep alert dispatch snappy. Successful results cached in Laravel\'s cache for 7 days.</li>
            <li><strong>Fallback</strong> — if all three tiers miss, the embed renders the ID-only form ("Pilot ID #N (name not cached)") exactly as before. ESI outages degrade gracefully; alerts always fire.</li>
        </ol>',
    'id_resolver_what_resolves' => '<strong>What gets resolved:</strong>
        <ul>
            <li><strong>Attacker pilot</strong> — the character who fired the entosis / launched the dread / shot the structure. Critical info for tactical response.</li>
            <li><strong>Attacker corporation</strong> — usually carried by CCP in notification YAML, but resolved as backfill when CCP omits it (older notification formats, sov events).</li>
            <li><strong>Attacker alliance</strong> — same backfill pattern as corporation.</li>
            <li><strong>Transferring character</strong> on <code>OwnershipTransferred</code> events.</li>
            <li><strong>Old / new corporations</strong> on ownership transfer events (when names are not in YAML).</li>
        </ul>',
    'id_resolver_performance' => '<strong>Performance considerations:</strong>
        <ul>
            <li>First-time attacker resolution adds ~250ms to alert dispatch (one ESI call). Acceptable for a critical security ping.</li>
            <li>Subsequent attacks by the same pilot / corp / alliance resolve instantly from the 7-day cache.</li>
            <li>Real-world threat actors tend to be recurring — cache hit rate is high once an install has been running for a few weeks.</li>
            <li>Per CCP\'s third-party developer guidelines, ESI calls include a User-Agent identifying the plugin: <code>SeAT-StructureManager/2.0.1 (+https://github.com/MattFalahe/structure-manager)</code>.</li>
        </ul>',
    'id_resolver_opsec' => '<strong>Opsec note:</strong> attacker character / corp / alliance IDs are <strong>public information</strong> in EVE. CCP exposes them through public ESI and zKillboard already renders them on every kill. Looking them up does not leak defender intel. Names rarely change in EVE (paid service for characters; even rarer for corps/alliances), so the 7-day cache TTL is a balance between freshness and ESI load. Operators can force-refresh a single resolution via <code>IdResolver::forget(\'character\', $id)</code> in tinker if a known rename happens.',
    'id_resolver_admin_force_refresh' => '<strong>Force-refresh a single entity:</strong>
        <pre style="margin:4px 0;">docker exec -it seat-docker-front-1 php artisan tinker --execute "
\\StructureManager\\Services\\IdResolver::forget(\'character\', 12345);     // force re-fetch on next lookup
\\StructureManager\\Services\\IdResolver::forget(\'corporation\', 6789);
\\StructureManager\\Services\\IdResolver::forget(\'alliance\', 1234);
"</pre>',

    // Tactical-planning events (SeAT Broadcast integration contract — added 2026-05-12)
    'tactical_events_title' => 'Tactical-Planning Event Contract (for SeAT Broadcast + future consumers)',
    'tactical_events_intro' => 'Structure Manager publishes a family of <code>structure.alert.*</code> events on Manager Core\'s EventBus specifically for fleet-planning use cases. SeAT Broadcast [<code>seat-discord-pings</code>] (or any future "fleet calendar" consumer) can subscribe to these events to populate a calendar view of upcoming critical operations and fire pre-timer reminders (e.g. "2h before reinforce timer ends, ping the FC channel").',
    'tactical_events_design' => '<strong>Design rationale:</strong> these events are distinct from the per-corp Discord webhook alerts SM already fires. The webhooks go to your everyone-on-Discord operational channels ("heads up, this happened"). The EventBus events go to fleet-command tooling that does <em>planning</em> ("calendar this, remind me at 2h, prepare fleet coverage"). Different audiences, different cadences, different jobs. Not duplication.',
    'tactical_events_table' => '<table style="width:100%; border-collapse:collapse; margin-top:8px; font-size:0.9em;">
            <thead><tr style="background:#23262d; color:#dfe3eb;">
                <th style="padding:6px 10px; text-align:left;">Event name</th>
                <th style="padding:6px 10px; text-align:left;">Fires for</th>
                <th style="padding:6px 10px; text-align:left;">timer_ends_at</th>
                <th style="padding:6px 10px; text-align:left;">Severity</th>
                <th style="padding:6px 10px; text-align:left;">Tactical action</th>
            </tr></thead>
            <tbody>
                <tr>
                    <td style="padding:6px 10px;"><code>structure.alert.shield_reinforced</code></td>
                    <td style="padding:6px 10px;">StructureLostShields, SkyhookLostShields</td>
                    <td style="padding:6px 10px;">Armor entry time</td>
                    <td style="padding:6px 10px;"><span class="badge badge-danger">critical</span></td>
                    <td style="padding:6px 10px;">Defend at armor exit</td>
                </tr>
                <tr>
                    <td style="padding:6px 10px;"><code>structure.alert.armor_reinforced</code></td>
                    <td style="padding:6px 10px;">StructureLostArmor</td>
                    <td style="padding:6px 10px;">Hull entry time</td>
                    <td style="padding:6px 10px;"><span class="badge badge-danger">critical</span></td>
                    <td style="padding:6px 10px;">Final defense or evacuate</td>
                </tr>
                <tr>
                    <td style="padding:6px 10px;"><code>structure.alert.destroyed</code></td>
                    <td style="padding:6px 10px;">StructureDestroyed, SkyhookDestroyed</td>
                    <td style="padding:6px 10px;">null (already happened)</td>
                    <td style="padding:6px 10px;"><span class="badge badge-danger">critical</span></td>
                    <td style="padding:6px 10px;">After-action; clear timers; update intel</td>
                </tr>
                <tr>
                    <td style="padding:6px 10px;"><code>structure.alert.anchoring_started</code></td>
                    <td style="padding:6px 10px;">StructureAnchoring, AllAnchoringMsg</td>
                    <td style="padding:6px 10px;">Anchor completion (~24h)</td>
                    <td style="padding:6px 10px;"><span class="badge badge-warning">warning</span></td>
                    <td style="padding:6px 10px;">Contest anchor before completion</td>
                </tr>
                <tr>
                    <td style="padding:6px 10px;"><code>structure.alert.sov_reinforced</code></td>
                    <td style="padding:6px 10px;">SovStructureReinforced</td>
                    <td style="padding:6px 10px;">Decloak time</td>
                    <td style="padding:6px 10px;"><span class="badge badge-danger">critical</span></td>
                    <td style="padding:6px 10px;">Fleet coverage for sov defense</td>
                </tr>
                <tr>
                    <td style="padding:6px 10px;"><code>structure.alert.entosis_in_progress</code></td>
                    <td style="padding:6px 10px;">EntosisCaptureStarted, SovCommandNodeEventStarted</td>
                    <td style="padding:6px 10px;">null (live op)</td>
                    <td style="padding:6px 10px;"><span class="badge badge-danger">critical</span></td>
                    <td style="padding:6px 10px;">Counter-entosis NOW</td>
                </tr>
                <tr>
                    <td style="padding:6px 10px;"><code>structure.alert.fuel_critical</code></td>
                    <td style="padding:6px 10px;">(SM fuel poll detecting low fuel)</td>
                    <td style="padding:6px 10px;">Fuel exhaustion time</td>
                    <td style="padding:6px 10px;"><span class="badge badge-danger">critical</span></td>
                    <td style="padding:6px 10px;">Schedule refuel run</td>
                </tr>
                <tr>
                    <td style="padding:6px 10px;"><code>structure.alert.fuel_recovered</code></td>
                    <td style="padding:6px 10px;">(SM fuel poll detecting refill)</td>
                    <td style="padding:6px 10px;">null</td>
                    <td style="padding:6px 10px;"><span class="badge badge-success">info</span></td>
                    <td style="padding:6px 10px;">Clear calendar; cancel reminders</td>
                </tr>
            </tbody>
        </table>',
    'tactical_events_payload' => '<p style="margin-top:8px;"><strong>Payload schema</strong> (every <code>structure.alert.*</code> event ships these fields via <code>AlertEventEnvelope</code>):</p>
        <ul>
            <li><code>source_plugin</code>: always <code>structure-manager</code></li>
            <li><code>schema_version</code>: int, currently 1 (consumers should branch on this when SM bumps it)</li>
            <li><code>event_id</code>: UUID for one-shot correlation</li>
            <li><code>event_type</code>: the bit after <code>structure.alert.</code> (e.g. <code>shield_reinforced</code>)</li>
            <li><code>category_group</code>: <code>fuel</code> / <code>tactical</code> / <code>lifecycle</code></li>
            <li><code>severity</code>: <code>info</code> / <code>warning</code> / <code>critical</code></li>
            <li><code>corporation_id</code>: owner corp (null for AllAnchoringMsg system-wide warnings)</li>
            <li><code>structure_id</code>: Upwell / Skyhook / sov entity ID (nullable for AllAnchoringMsg)</li>
            <li><code>structure_name</code>, <code>structure_type_id</code></li>
            <li><code>system_id</code>, <code>system_name</code>, <code>system_security</code></li>
            <li><code>eve_time</code>, <code>seconds_until</code>, <code>is_elapsed</code>: derived from <code>timer_ends_at</code> for forward events, from <code>destroyed_at</code> for destruction events</li>
            <li><code>timer_ends_at</code>: ISO 8601 timestamp the timer expires (null when not applicable)</li>
            <li><code>attacker_resolution_status</code>, <code>attacker_character_*</code>, <code>attacker_corporation_*</code>, <code>attacker_alliance_*</code>: combat events only; <code>null</code> for fuel / anchoring / sov / entosis</li>
            <li><code>url</code>: deeplink to SM Structure Board for this structure</li>
            <li><code>source_reference</code>: stable per-notification key (e.g. <code>esi-notif:NNN</code>) used by MC\'s idempotency dedup</li>
        </ul>',
    'tactical_events_subscriber_guide' => '<p><strong>How a fleet-planning subscriber consumes these events:</strong></p>
        <ol>
            <li>Register an EventBus subscription via Manager Core: <code>event_pattern = \'structure.alert.*\'</code>, <code>handler_capability = \'your-plugin.structure_calendar\'</code></li>
            <li>Implement the capability handler to receive <code>($eventName, $publisherPlugin, $payload)</code></li>
            <li>Filter to events with non-null <code>timer_ends_at</code> for calendar entries; treat null-timer events as "react NOW" pings</li>
            <li>Store the event in your own DB keyed by <code>event_id</code> for idempotency. Re-emissions of the same logical event within MC\'s 1h dedup window are suppressed at publish time, but if you do see a duplicate, the <code>event_id</code> tells you to merge.</li>
            <li>For pre-timer reminders, schedule a job (or cron) that periodically queries your DB for events where <code>timer_ends_at - reminder_offset BETWEEN NOW() AND NOW() + 1min</code> and fires one ping per match.</li>
        </ol>',
    'tactical_events_opsec_note' => 'These events stay inside MC\'s EventBus (database-backed, behind your SeAT auth) and only reach the subscribers you have installed. They do not leave the trust zone. The SeAT Broadcast calendar view, if installed, lives inside SeAT and is auth-gated. Per-timer reminder pings go through operator-controlled Discord webhooks (the same trust pattern as SM\'s direct alerts).',

    'opsec_other_data_title' => 'Other tactical data SM keeps in-perimeter',
    'opsec_other_data' => 'The same trust-zone discipline applies to other tactically-sensitive data SM holds:
        <ul>
            <li><strong>Structure inventories</strong> (corporation_assets contents) — visible to authorized SeAT users only, never exported.</li>
            <li><strong>Fuel consumption rates / depletion projections</strong> — knowing when a structure will run out of fuel is nearly as valuable as knowing its reinforce timer. Stays inside SeAT + your Discord.</li>
            <li><strong>POS strontium reserves</strong> — explicit reinforce-cap intel. Same trust zones.</li>
            <li><strong>Attacker / aggressor names</strong> from notification YAML — these come from public ESI feeds (zKillboard already shows them), so they are not your defender intel. Safe to enrich and display.</li>
        </ul>
        <p style="margin-top:8px;">If a future feature request involves "export this data to a third-party service", the default answer is no. The bar to ship such a feature is showing the third-party has a stronger operational-security profile than SeAT itself — which is almost never true for general-purpose services.</p>',

    'webhook_features' => 'Webhook Features',
    'webhook_features_desc' => '<ul>
        <li><strong>Discord & Slack Support:</strong> Compatible with both Discord and Slack webhook URLs</li>
        <li><strong>Rich Embeds:</strong> Color-coded messages with detailed resource information</li>
        <li><strong>Severity Levels:</strong> Visual distinction between critical (red) and warning (yellow) alerts</li>
        <li><strong>Role Mentions:</strong> Optional Discord role mentions for critical alerts to notify specific teams</li>
        <li><strong>Status-Based Alerts:</strong> Notifications sent on status changes (good→warning→critical) to prevent spam</li>
        <li><strong>Final Alert System:</strong> Urgent notification 1 hour before POS goes offline</li>
        <li><strong>Customizable Avatar:</strong> Uses avatar configured in your Discord webhook settings</li>
        <li><strong>Limiting Factor Highlighting:</strong> Emphasizes which resource needs immediate attention</li>
        <li><strong>System Information:</strong> Includes system name, security status, and corporation details</li>
        <li><strong>Automated Scheduling:</strong> Checks every 10 minutes with intelligent status tracking</li>
    </ul>',
    
    'notification_types' => 'Notification Types',
    'fuel_charter_notifications' => 'Fuel & Charter Alerts',
    'fuel_charter_desc' => 'Sent when fuel blocks or starbase charters change status (good→warning→critical). Optional interval reminders during critical stage (default: 6 hours, configurable). Final alert sent 1 hour before POS goes offline.',
    
    'strontium_notifications' => 'Strontium Alerts',
    'strontium_desc' => 'Sent when strontium clathrates change status (good→warning→critical). Optional interval reminders during critical stage (default: 2 hours, configurable for faster defensive response). Final alert sent 1 hour before strontium depletes.',
    
    'notification_thresholds' => 'Alert Thresholds',
    'critical_thresholds' => 'Critical Thresholds (Red Alerts)',
    'critical_thresholds_list' => '<ul>
        <li><strong>Fuel/Charters:</strong> Less than 7 days remaining</li>
        <li><strong>Strontium:</strong> Less than 6 hours remaining</li>
    </ul>',
    
    'warning_thresholds' => 'Warning Thresholds (Yellow Alerts)',
    'warning_thresholds_list' => '<ul>
        <li><strong>Fuel/Charters:</strong> Less than 14 days remaining</li>
        <li><strong>Strontium:</strong> Less than 12 hours remaining</li>
    </ul>',
    
    'notification_cooldowns' => 'Notification System',
    'cooldown_explanation' => 'Smart notification system prevents spam while ensuring critical issues are never missed:',
    'cooldown_list' => '<ul>
        <li><strong>Status Change Alerts:</strong> Sent immediately when POS moves between good→warning→critical states</li>
        <li><strong>Final Alert (1 Hour):</strong> Urgent notification when only 1 hour of fuel/strontium remains</li>
        <li><strong>Optional Critical Reminders:</strong>
            <ul>
                <li>Fuel/Charter: Configurable interval reminders during critical stage (default: 6 hours)</li>
                <li>Strontium: Configurable interval reminders during critical stage (default: 2 hours)</li>
                <li>Can be disabled by setting interval to 0 (status change alerts only)</li>
            </ul>
        </li>
        <li><strong>Independent Resource Tracking:</strong> Fuel and strontium alerts tracked separately per POS</li>
        <li><strong>Status Reset:</strong> When POS returns to good status, notification flags reset automatically</li>
    </ul>',
    
    'discord_role_mentions' => 'Discord Role Mentions',
    'role_mention_desc' => 'Configure Discord role mentions to ping specific teams for critical alerts:',
    'role_mention_steps' => '<ol>
        <li>Enable Developer Mode in Discord (User Settings → Advanced → Developer Mode)</li>
        <li>Go to Server Settings → Roles</li>
        <li>Right-click the role you want to mention → Copy ID</li>
        <li>Format as: <code>&lt;@&amp;ROLE_ID&gt;</code></li>
        <li>Paste into Settings → Notification Settings → Discord Role Mention field</li>
    </ol>',
    
    'notification_examples' => 'Notification Examples',
    'critical_example' => '<p><strong>Critical Alert Example:</strong></p>
<pre style="white-space: pre-wrap; line-height: 1.5; margin: 0;">&#x1F6A8; <strong>CRITICAL POS FUEL ALERT</strong>

<strong>Tower:</strong>        Death Star (Large Amarr Control Tower)
<strong>System:</strong>       3-FKCZ (Null-Sec)
<strong>Corporation:</strong>  Test Corp

<strong>Fuel Blocks:</strong>  245 remaining (4.3 days) [LIMITING FACTOR]
<strong>Strontium:</strong>    15,432 remaining

<strong>Status:</strong>       CRITICAL - Refuel immediately!</pre>',
    
    'warning_example' => '<p><strong>Warning Alert Example:</strong></p>
<pre style="white-space: pre-wrap; line-height: 1.5; margin: 0;">&#x26A0;&#xFE0F; <strong>POS FUEL WARNING</strong>

<strong>Tower:</strong>        Moon Mining Base (Medium Caldari Control Tower)
<strong>System:</strong>       J123456 (W-Space)

<strong>Fuel Blocks:</strong>  5,840 remaining (12.2 days)
<strong>Strontium:</strong>    8,200 remaining

<strong>Status:</strong>       LOW - Schedule refuel operation</pre>',

    'zero_strontium_title' => 'Zero Strontium Behavior',
    'zero_strontium_intro' => 'Structure Manager has intelligent handling for POSes with zero strontium clathrates, recognizing different scenarios and alerting appropriately.',
    'zero_strontium_scenarios' => '<strong>Scenarios:</strong>
        <ul>
            <li><strong>Online POS (State 4) with 0 Strontium:</strong>
                <ul>
                    <li>Alert message: "Strontium Clathrates - Structure in Possible Danger"</li>
                    <li>Severity: Warning level (yellow)</li>
                    <li>Meaning: POS is operational but has no reinforcement protection</li>
                    <li>Impact: If attacked, POS will enter reinforced mode without a timer</li>
                    <li>Recommended action: Add strontium for defensive protection</li>
                </ul>
            </li>
            <li><strong>Reinforced POS (State 3) with 0 Strontium:</strong>
                <ul>
                    <li>Alert message: "Strontium Clathrates - Structure in Danger!"</li>
                    <li>Severity: Critical level (red)</li>
                    <li>Meaning: POS is reinforced but has no timer protection</li>
                    <li>Impact: POS can come out of reinforcement at any moment</li>
                    <li>Recommended action: URGENT - Add strontium immediately</li>
                </ul>
            </li>
        </ul>',
    'zero_strontium_notification_behavior' => '<strong>Notification Behavior:</strong>
        <ul>
            <li><strong>One-time notification:</strong> Initial alert sent when 0 strontium is first detected</li>
            <li><strong>Status change alerts:</strong> Additional notifications sent if POS state changes (online ↔ reinforced)</li>
            <li><strong>No spam protection:</strong> Does not send repeated interval reminders for prolonged 0 strontium (configurable grace period: 2 hours)</li>
            <li><strong>Grace period behavior:</strong> After initial notification, only sends new alerts if status changes or after grace period expires</li>
            <li><strong>Final alert exemption:</strong> No "1 hour remaining" alert for strontium since it\'s already at 0</li>
        </ul>',
    'zero_strontium_use_cases' => '<strong>Common Use Cases:</strong>
        <ul>
            <li><strong>Economic POS:</strong> Low-value POSes in safe space where reinforcement protection isn\'t critical</li>
            <li><strong>Planned decommission:</strong> POSes being shut down where defensive capabilities aren\'t needed</li>
            <li><strong>High-sec only:</strong> POSes in very high security systems with minimal attack risk</li>
            <li><strong>Emergency situations:</strong> When strontium supplies are depleted and immediate restocking isn\'t possible</li>
        </ul>',

    'multiple_webhooks_title' => 'Multiple Webhook Support',
    'multiple_webhooks_intro' => 'Structure Manager supports any number of webhooks with per-webhook corporation filtering. Role mentions and per-category routing are handled through the Notifications page rather than on the webhook row itself. This makes complex multi-corp, multi-channel setups clean without creating duplicate webhooks for different categories.',
    'multiple_webhooks_features' => '<strong>Features:</strong>
        <ul>
            <li><strong>Unlimited webhooks:</strong> Configure as many Discord or Slack webhook URLs as you need</li>
            <li><strong>Corporation filtering:</strong> Each webhook can target specific corporations or "all corporations"</li>
            <li><strong>Independent enable state:</strong> Each webhook has its own master on/off toggle</li>
            <li><strong>Per-category bindings:</strong> Each webhook receives only the notification categories it\'s bound to</li>
            <li><strong>Per-binding role mentions:</strong> Override the category default role for a specific webhook (e.g. different roles on corp vs. alliance Discord)</li>
            <li><strong>Per-binding enable toggle:</strong> Silence a specific category → webhook pairing without deleting it</li>
            <li><strong>Optional descriptions:</strong> Label each webhook for easier identification</li>
            <li><strong>Individual testing:</strong> Test each webhook separately from the Settings page</li>
        </ul>',
    'multiple_webhooks_use_cases' => '<strong>Use Cases:</strong>
        <ul>
            <li><strong>Multi-Corp Hosting:</strong> Main corporation uses Webhook #1, alt corporations #2-5 use separate webhooks
                <ul>
                    <li>Webhook #1 → Main Corp Discord (Corporation filter: "Main Corp" / Role: @Logistics-Main)</li>
                    <li>Webhook #2 → Alt Corp 1 Discord (Corporation filter: "Alt Corp 1" / Role: @Logistics-Alt1)</li>
                    <li>Webhook #3 → Alt Corp 2 Discord (Corporation filter: "Alt Corp 2" / Role: @Logistics-Alt2)</li>
                    <li>Result: Each corporation receives only their POS alerts in their own channel</li>
                </ul>
            </li>
            <li><strong>Alert Segregation:</strong> Separate channels for different alert types
                <ul>
                    <li>Webhook #1 → General POS alerts (All corps / Role: @Everyone)</li>
                    <li>Webhook #2 → Critical-only channel (All corps / Role: @Directors)</li>
                    <li>Configure different notification thresholds per channel</li>
                </ul>
            </li>
            <li><strong>Regional Coordination:</strong> Multiple alliances sharing SeAT infrastructure
                <ul>
                    <li>Each alliance gets their own webhook targeting their corporations</li>
                    <li>Alerts stay within alliance boundaries</li>
                    <li>Different role mentions per alliance for appropriate escalation</li>
                </ul>
            </li>
            <li><strong>Redundancy:</strong> Send critical alerts to multiple channels
                <ul>
                    <li>Webhook #1 → Primary ops channel</li>
                    <li>Webhook #2 → Backup logistics channel</li>
                    <li>Both configured for same corporations with different role mentions</li>
                </ul>
            </li>
        </ul>',
    'multiple_webhooks_configuration' => '<strong>Configuration workflow:</strong>
        <ol>
            <li>Navigate to <strong>Settings > POS Notifications > Webhook Configuration</strong> and add your webhook(s):
                <ul>
                    <li>Discord or Slack URL (https only, default port 443, approved hosts)</li>
                    <li>Corporation filter: "All Corporations" or a specific corp</li>
                    <li>Description to identify the webhook</li>
                    <li>Legacy role-mention field (optional, used only as a last-resort fallback — prefer the Notifications page below)</li>
                </ul>
            </li>
            <li>Test the webhook with "Test Webhook" to verify connectivity.</li>
            <li>Navigate to <strong>Structure Manager > Notifications</strong> (sidebar).</li>
            <li>For each category you care about:
                <ul>
                    <li>Toggle the master enable switch</li>
                    <li>Set a default role mention (picker if a Discord source is installed, manual otherwise)</li>
                    <li>Pick webhooks from the "Bind Webhook" dropdown and click Add</li>
                </ul>
            </li>
            <li>To set a different role mention on a specific webhook binding: edit the role override on that row, then click Save. Leaving it blank inherits the category default.</li>
            <li>Per-binding enable switches let you temporarily silence one binding without deleting it.</li>
        </ol>',
    'multiple_webhooks_example' => '<strong>Example Multi-Corp Setup:</strong><br>
        <pre>Webhook #1:
  URL: https://discord.com/api/webhooks/.../primary-corp
  Corporation: Main Corp Alliance
  Role Mention: &lt;@&amp;123456789&gt; (@Fuel-Team-Main)
  Description: Main corp fuel alerts
  Status: ✅ Enabled

Webhook #2:
  URL: https://discord.com/api/webhooks/.../alt-corp-1
  Corporation: Alt Corp Alpha
  Role Mention: &lt;@&amp;987654321&gt; (@Fuel-Team-Alpha)
  Description: Alpha alt corp alerts
  Status: ✅ Enabled

Webhook #3:
  URL: https://discord.com/api/webhooks/.../alt-corp-2
  Corporation: Alt Corp Beta
  Role Mention: &lt;@&amp;111222333&gt; (@Fuel-Team-Beta)
  Description: Beta alt corp alerts
  Status: ✅ Enabled</pre>',
    
    // Settings Section
    'settings_title' => 'Settings & Configuration',
    'settings_intro' => 'Configure Structure Manager to match your corporation\'s needs. Access settings from the main navigation menu.',
    'settings_notification_note' => '<strong>Note:</strong> For detailed information about the notification system, webhook behavior, zero strontium alerts, and multiple webhook support, see the <a href="#notifications">Notifications section</a>.',
    
    'webhook_settings' => 'Webhook Settings',
    'webhook_settings_desc' => 'Configure Discord or Slack webhook notifications for POS fuel alerts.',
    
    'webhook_url_setting' => 'Webhook URL',
    'webhook_url_desc' => 'Enter your Discord or Slack webhook URL. Obtain this from:',
    'webhook_url_steps' => '<ul>
        <li><strong>Discord:</strong> Server Settings → Integrations → Webhooks → New Webhook</li>
        <li><strong>Slack:</strong> Apps → Incoming Webhooks → Add to Slack</li>
    </ul>',
    
    'enable_notifications' => 'Enable POS Webhook Notifications',
    'enable_desc' => 'Toggle notifications on/off without removing webhook configuration.',
    
    'notification_intervals' => 'Critical Stage Reminder Intervals',
    'fuel_interval_desc' => '<strong>Fuel/Charter Interval:</strong> How often to send reminder alerts during critical stage for fuel/charters (default: 6 hours, range: 1-24 hours). Set to 0 to disable interval reminders and only receive status change alerts.',
    'strontium_interval_desc' => '<strong>Strontium Interval:</strong> How often to send reminder alerts during critical stage for strontium (default: 2 hours, range: 1-12 hours). Set to 0 to disable interval reminders and only receive status change alerts.',
    
    'threshold_settings' => 'POS Alert Threshold Settings',
    'threshold_desc' => 'Customize when POS alerts are triggered for each resource type. Wormhole and null-sec deployments often need higher critical thresholds for extended response times — these are configurable per install. <em>Note: Upwell structure thresholds (citadels, refineries, Metenoxes, etc.) are locked at 7d critical / 14d warning and not configurable here — see the Upwell Notifications section.</em>',

    'fuel_thresholds' => 'POS Fuel & Charter Thresholds',
    'fuel_critical_setting' => '<strong>Critical:</strong> Alert when fuel/charters drop below X days (default: 7 days, configurable)',
    'fuel_warning_setting' => '<strong>Warning:</strong> Alert when fuel/charters drop below X days (default: 14 days, configurable)',

    'strontium_thresholds' => 'POS Strontium Thresholds',
    'strontium_critical_setting' => '<strong>Critical:</strong> Alert when strontium drops below X hours (default: 6 hours, configurable)',
    'strontium_warning_setting' => '<strong>Warning:</strong> Alert when strontium drops below X hours (default: 12 hours, configurable)',
    
    'test_webhook_button' => 'Test Webhook',
    'test_webhook_desc' => 'Send a test notification to verify your webhook configuration is working correctly.',
    
    'reserves_tracking_settings' => 'Reserves Tracking Settings',
    'reserves_tracking_desc' => 'Configure which CorpSAG hangars are included in Upwell fuel reserves calculations. POS towers have no CorpSAG hangars; their fuel/stront/charter inventories are tracked directly on POS detail pages and are unaffected by this setting.',
    
    'hangar_exclusion_title' => 'Hangar Exclusion',
    'hangar_exclusion_desc' => 'Select which corporate hangars (1-7) should be EXCLUDED from fuel reserves tracking. This is useful for excluding hangars used for:',
    'hangar_exclusion_uses' => '<ul>
        <li><strong>Market Trading:</strong> Hangars containing fuel destined for market sales</li>
        <li><strong>Logistics Staging:</strong> Fuel being held for other operations</li>
        <li><strong>Personal Storage:</strong> Hangars not intended for structure refueling</li>
        <li><strong>Contract Fulfillment:</strong> Fuel blocks reserved for external contracts</li>
    </ul>',
    'hangar_exclusion_note' => '<strong>Note:</strong> Checked hangars are tracked, unchecked hangars are excluded. Fuel in excluded CorpSAG hangars will not appear in reserves reports, logistics calculations, or the Upwell Reserves page. POS resources are unaffected (POSes have no CorpSAG hangars to exclude).',
    
    'settings_tips' => 'Configuration Tips',
    'settings_tips_list' => '<ul>
        <li><strong>Start Conservative:</strong> Begin with longer intervals (6+ hours) to avoid notification fatigue</li>
        <li><strong>Adjust Based on Activity:</strong> Active POS locations may need more frequent checks</li>
        <li><strong>Test First:</strong> Always test your webhook before enabling production notifications</li>
        <li><strong>Role Mentions:</strong> Use role mentions sparingly for truly critical alerts only</li>
        <li><strong>Monitor Cooldowns:</strong> If you\'re not receiving notifications, check if cooldown periods are too long</li>
        <li><strong>Hangar Exclusions:</strong> Regularly review excluded hangars to ensure they match your current operations</li>
    </ul>',
    
    'upwell_notifications_note' => 'Upwell Structure Notifications',
    'upwell_notifications_desc' => 'Discord/Slack webhook notifications are available for both POSes and Upwell structures (Citadels, Refineries, Engineering Complexes, Metenox Moon Drills). Upwell alerts use proactive polling every 10 minutes with thresholds locked at sensible defaults (7-day critical, 14-day warning). Notifications fire on status transitions (good/warning/critical) with an automatic final alert at 1 hour remaining. Metenox structures show dual-fuel intelligence (fuel blocks + magmatic gas) with limiting-factor highlighting. POS thresholds are configurable per install (Settings > POS Notifications) since wormhole/null-sec deployments need extended response time; Upwell thresholds are locked for cross-surface consistency. Both POS and Upwell alerts share the same webhook configurations.',

    // ============================================================
    // Upwell Structure Notifications (detailed)
    // ============================================================
    'upwell_detailed_title' => 'Upwell Structure Notifications — Detailed Guide',
    'upwell_detailed_intro' => 'Upwell notifications are Structure Manager\'s polling-based alert system for Citadels, Engineering Complexes, Refineries, and Metenox Moon Drills. Unlike CCP\'s reactive <code>StructureFuelAlert</code> (which fires only once, ~24 hours before empty), Structure Manager proactively polls fuel bays every 10 minutes and fires multi-stage alerts as your fuel crosses two locked thresholds (7-day critical, 14-day warning). The 13-day operational window between SM\'s first warning and CCP\'s last-mile alert is the difference between "schedule a refuel run this week" and "scramble logistics RIGHT NOW".',

    'upwell_what_tracked_title' => 'Structures Covered',
    'upwell_what_tracked_list' => '<ul>
        <li><strong>Citadels</strong> — Astrahus, Fortizar, Keepstar</li>
        <li><strong>Engineering Complexes</strong> — Raitaru, Azbel, Sotiyo</li>
        <li><strong>Refineries</strong> — Athanor, Tatara (fuel bonuses applied automatically when service has the bonus)</li>
        <li><strong>Observatories</strong> — Tenebrex Cyno Jammer (type ID 37534)</li>
        <li><strong>Flex Structures</strong> — Ansiblex Jump Gates, Pharolux Cyno Beacons</li>
        <li><strong>Metenox Moon Drills</strong> — tracked with dual-fuel logic (fuel blocks + magmatic gas)</li>
    </ul>
    <p style="margin-top:8px;">Any structure with a fuel bay that SeAT can read via ESI is covered. Structures that CCP marks as "unfueled" (NULL <code>fuel_expires</code>) are ignored.</p>',

    'upwell_detection_title' => 'How Detection Works',
    'upwell_detection_list' => '<ol>
        <li><strong>Every 10 minutes</strong>, the <code>structure-manager:notify-upwell-fuel</code> job dispatches (cron <code>*/10 * * * *</code>)</li>
        <li>The job reads all <code>corporation_structures</code> rows where <code>fuel_expires IS NOT NULL</code></li>
        <li>For each structure it loads:
            <ul>
                <li>Current fuel block count from <code>corporation_assets</code> (location_id = structure_id, location_flag = StructureFuel)</li>
                <li>Time remaining via <code>fuel_expires - NOW()</code></li>
                <li>Active service count from <code>corporation_structure_services</code> (state = \'online\')</li>
                <li>Consumption rate via <code>FuelCalculator::getFuelRequirement()</code> (handles refinery bonuses)</li>
            </ul>
        </li>
        <li>For Metenox: also loads magmatic gas from the same fuel bay (type ID 81143) and computes effective days as <code>min(fuel_days, gas_days)</code></li>
        <li>Status is determined against the locked Upwell thresholds (7d critical / 14d warning) and stored in <code>structure_notification_status</code> (one row per structure)</li>
        <li>Notifications fire on status transitions, plus a latched final alert at 1 hour remaining</li>
    </ol>',

    'upwell_status_flow_title' => 'Status Flow',
    'upwell_status_flow_desc' => 'Each structure has a status that transitions through four states. Notifications fire on <strong>transitions</strong>, not on every poll, to prevent spam.',
    'upwell_status_flow_table' => '<table style="width:100%; border-collapse:collapse; margin-top:10px;">
        <thead><tr>
            <th style="text-align:left; padding:8px; border-bottom:1px solid #454d55;">Status</th>
            <th style="text-align:left; padding:8px; border-bottom:1px solid #454d55;">Trigger</th>
            <th style="text-align:left; padding:8px; border-bottom:1px solid #454d55;">Fires Notification?</th>
            <th style="text-align:left; padding:8px; border-bottom:1px solid #454d55;">Color</th>
        </tr></thead>
        <tbody>
            <tr>
                <td style="padding:8px;"><strong>good</strong></td>
                <td style="padding:8px;">days_remaining &ge; warning_days</td>
                <td style="padding:8px;">No (baseline)</td>
                <td style="padding:8px;">—</td>
            </tr>
            <tr>
                <td style="padding:8px;"><strong>warning</strong></td>
                <td style="padding:8px;">critical_days &le; days_remaining &lt; warning_days</td>
                <td style="padding:8px;">Yes, on entry (good &rarr; warning)</td>
                <td style="padding:8px; color:#ffc107;">Yellow</td>
            </tr>
            <tr>
                <td style="padding:8px;"><strong>critical</strong></td>
                <td style="padding:8px;">days_remaining &lt; critical_days</td>
                <td style="padding:8px;">Yes, on entry + optional interval reminders</td>
                <td style="padding:8px; color:#dc3545;">Red</td>
            </tr>
            <tr>
                <td style="padding:8px;"><strong>final</strong></td>
                <td style="padding:8px;">hours_remaining &le; 1 AND &gt; 0</td>
                <td style="padding:8px;">Yes, latched (fires once; re-arms on recovery)</td>
                <td style="padding:8px; color:#8b0000;">Dark red</td>
            </tr>
        </tbody>
    </table>
    <p style="margin-top:8px;"><strong>Recovery behavior:</strong> when a structure\'s status returns above critical (usually after refueling), Structure Manager resets the <code>fuel_final_alert_sent</code> latch. A future drop back to the 1-hour mark will fire a fresh final alert rather than staying silent.</p>',

    'upwell_metenox_dual_fuel_title' => 'Metenox Dual-Fuel Logic',
    'upwell_metenox_dual_fuel_desc' => 'Metenox Moon Drills consume both fuel blocks AND magmatic gas simultaneously. If either resource runs out, the structure stops working — so the alert uses whichever runs out first.',
    'upwell_metenox_dual_fuel_math' => '<ul>
        <li><strong>Consumption rates:</strong> 5 blocks/hour + 200 gas/hour (120 blocks/day + 4,800 gas/day)</li>
        <li><strong>Effective days remaining:</strong> <code>min(fuel_days, gas_days)</code></li>
        <li><strong>Limiting factor:</strong> the resource with fewer days left — shown prominently in the embed with a <code>[LIMITING]</code> badge</li>
        <li><strong>Weekly requirement:</strong> 840 blocks + 33,600 gas per Metenox</li>
        <li><strong>No refinery bonus:</strong> Metenox drills do NOT receive Athanor/Tatara bonuses (CCP-intended design)</li>
    </ul>
    <p style="margin-top:8px;">Example: a Metenox with 100 fuel blocks (~20 hours) and 48,000 gas (10 days) shows <strong>fuel blocks as limiting</strong>. The alert prioritizes fuel-block hauling even though gas reserves look fine.</p>',

    'upwell_config_title' => 'Configuration',
    'upwell_config_thresholds' => '<strong>Thresholds (locked, NOT configurable):</strong>
        <ul>
            <li><strong>Critical:</strong> &lt; 7 days remaining (<code>FuelThresholds::UPWELL_FUEL_CRITICAL_DAYS</code>)</li>
            <li><strong>Warning:</strong> &lt; 14 days remaining (<code>FuelThresholds::UPWELL_FUEL_WARNING_DAYS</code>)</li>
            <li><strong>Final alert:</strong> &le; 1 hour remaining (latched, not configurable)</li>
        </ul>
        <p style="margin-top:6px;">Upwell thresholds are locked at sensible defaults so every display surface (list, detail, board, webhooks, Critical Alerts) agrees on what counts as critical / warning. This avoids the "settings drift" problem where the embed fires at one threshold while the UI flags structures at another. POS thresholds remain configurable in Settings &gt; POS Notifications because wormhole / null-sec deployments need different response times — Upwell deployments are typically more uniform across high-sec / null-sec.</p>
        <p style="margin-top:6px;"><strong>Configurable (cadence):</strong></p>
        <ul>
            <li><code>upwell_fuel_notification_interval</code> — hours between reminder pings during critical stage (0 = disabled, only status transitions fire). Settings &gt; Upwell Structures.</li>
        </ul>',
    'upwell_config_webhooks' => '<strong>Webhooks &amp; Role Mentions (Notifications page):</strong>
        <ol>
            <li>Add your webhook URL(s) in <code>Settings &gt; POS Notifications &gt; Webhook Configuration</code></li>
            <li>Go to <code>Structure Manager &gt; Notifications</code></li>
            <li>Enable the <code>upwell.fuel</code> category (and <code>upwell.magmatic_gas</code> for Metenox gas alerts)</li>
            <li>Bind the webhook(s) you want to receive Upwell alerts</li>
            <li>Set a default role mention on the category, or per-binding for fine control</li>
        </ol>',

    'upwell_vs_pos_title' => 'Upwell vs POS — Key Differences',
    'upwell_vs_pos_table' => '<table style="width:100%; border-collapse:collapse; margin-top:10px;">
        <thead><tr>
            <th style="text-align:left; padding:8px; border-bottom:1px solid #454d55;">Aspect</th>
            <th style="text-align:left; padding:8px; border-bottom:1px solid #454d55;">Upwell</th>
            <th style="text-align:left; padding:8px; border-bottom:1px solid #454d55;">POS (legacy)</th>
        </tr></thead>
        <tbody>
            <tr>
                <td style="padding:8px;">Fuel detection source</td>
                <td style="padding:8px;">ESI <code>fuel_expires</code> + fuel bay polling</td>
                <td style="padding:8px;">ESI <code>assets</code> + Structure Manager fuel rate math (<code>TypeIdRegistry</code> + <code>PosFuelCalculator</code>, hardcoded rates with SDE <code>invControlTowerResources</code> as fallback for racial-fuel-type lookups)</td>
            </tr>
            <tr>
                <td style="padding:8px;">Reinforcement timer</td>
                <td style="padding:8px;">Via ESI notifications (StructureUnderAttack, etc.)</td>
                <td style="padding:8px;">Strontium clathrate hours when <code>state = \'reinforced\'</code></td>
            </tr>
            <tr>
                <td style="padding:8px;">Dual fuel?</td>
                <td style="padding:8px;">Metenox only (blocks + gas)</td>
                <td style="padding:8px;">No (just blocks + charter in high-sec)</td>
            </tr>
            <tr>
                <td style="padding:8px;">Status tracking table</td>
                <td style="padding:8px;"><code>structure_notification_status</code></td>
                <td style="padding:8px;">Columns on <code>starbase_fuel_history</code> latest row</td>
            </tr>
            <tr>
                <td style="padding:8px;">Notification categories</td>
                <td style="padding:8px;"><code>upwell.fuel</code>, <code>upwell.magmatic_gas</code></td>
                <td style="padding:8px;"><code>pos.fuel</code>, <code>pos.strontium</code>, <code>pos.lifecycle</code></td>
            </tr>
            <tr>
                <td style="padding:8px;">Poll cadence</td>
                <td style="padding:8px;">Every 10 minutes</td>
                <td style="padding:8px;">Every 10 minutes</td>
            </tr>
            <tr>
                <td style="padding:8px;">Final alert latch</td>
                <td style="padding:8px;">1 hour remaining</td>
                <td style="padding:8px;">1 hour (fuel) / 30 min (strontium)</td>
            </tr>
        </tbody>
    </table>
    <p style="margin-top:8px; font-size:0.9em;"><strong>Why Structure Manager uses hardcoded POS fuel rates instead of pure SDE math:</strong> SeAT\'s SDE for POS towers has documented inaccuracies in some fields (notably faction-tower modifiers and at least one type-ID discrepancy where Tenebrex Cyno Jammer is listed as 35839 in some snapshots but is actually 37534 in live). <code>TypeIdRegistry</code> hardcodes the verified-correct values (POS_TOWERS metadata with faction modifiers, POS_BASE_FUEL_RATES per size) and uses <code>invControlTowerResources</code> only for the racial-fuel-type lookup where SDE is reliable. The hourly rate calculation prefers SDE\'s <code>invControlTowerResources.quantity</code> when present and falls back to <code>POS_BASE_FUEL_RATES[size] &times; faction_modifier</code> otherwise. See <code>TypeIdRegistry.php</code>\'s "HARDCODED ON PURPOSE" docblock for the full reasoning.</p>',

    'upwell_embed_example_title' => 'Example Discord Embed',
    'upwell_embed_example' => '<pre>CRITICAL: Upwell Structure Low Fuel &mdash; 1 structure needs attention
&lt;@&amp;123456789&gt;

FINAL ALERT: "3-FKCZ Fortizar"
📍 Location: 3-FKCZ (-0.07)
Structure Type: Fortizar
⏰ Last Update: just now
GOING OFFLINE IN: 47 minutes

Fuel Blocks: 47 blocks remaining
Consumption Rate: 40.0 blocks/hour
Active Services: 4 service(s) online
Weekly Requirement: 6,720 blocks

SeAT Structure Manager | Structure ID: 1042938412345</pre>',

    // Pages Guide
    'pages_intro' => 'Structure Manager consists of several pages, each designed for a specific aspect of structure and fuel management. They are listed below in the same order they appear in the sidebar.',

    'dashboard_page_title' => 'Upwell Structures (Main Page)',
    'dashboard_page_desc' => '<ul>
        <li><strong>Structure overview:</strong> Complete list of all corporation structures</li>
        <li><strong>Fuel status indicators:</strong> Color-coded badges showing fuel levels
            <ul>
                <li>🔴 Critical (0-7 days)</li>
                <li>🟡 Warning (7-14 days)</li>
                <li>🟢 Good (14-30 days)</li>
                <li>🔵 Excellent (30+ days)</li>
            </ul>
        </li>
        <li><strong>Quick filters:</strong> Filter by corporation, structure type, or fuel status</li>
        <li><strong>Searchable table:</strong> Find structures quickly by name or system</li>
        <li><strong>One-click details:</strong> Click any structure to view detailed consumption analytics</li>
    </ul>',

    'pos_page_title' => 'Control Towers (POS)',
    'pos_page_desc' => '<ul>
        <li><strong>POS overview:</strong> Complete list of all corporation Control Towers (POS)</li>
        <li><strong>Fuel status indicators:</strong> Color-coded badges showing fuel levels
            <ul>
                <li>🔴 Critical (0-7 days)</li>
                <li>🟡 Warning (7-14 days)</li>
                <li>🟢 Good (14-30 days)</li>
                <li>🔵 Excellent (30+ days)</li>
            </ul>
        </li>
        <li><strong>Quick filters:</strong> Filter by corporation, space type, or fuel status</li>
        <li><strong>Searchable table:</strong> Find structures quickly by name or system</li>
        <li><strong>One-click details:</strong> Click any structure to view detailed consumption analytics and statuses</li>
    </ul>',

    'critical_alerts_page_title' => 'Critical Alerts Page',
    'critical_alerts_page_desc' => '<ul>
        <li><strong>Urgent structures only:</strong> Shows only structures with less than 14 days of fuel</li>
        <li><strong>Priority sorting:</strong> Sorted by most urgent first</li>
        <li><strong>Fuel requirements:</strong> Displays exactly how much fuel is needed</li>
        <li><strong>Metenox dual-status:</strong> Separate boxes for fuel blocks and gas with limiting factor badges</li>
        <li><strong>Quick action focus:</strong> Designed for rapid assessment and response</li>
    </ul>',

    'reserves_page_title' => 'Reserves Management Page',
    'reserves_page_desc' => '<ul>
        <li><strong>Reserve summary:</strong> Total fuel blocks and gas staged across all structures</li>
        <li><strong>Structure breakdown:</strong> Which structures have staged fuel</li>
        <li><strong>Division detail:</strong> Shows exact hangar divisions containing fuel</li>
        <li><strong>Custom names:</strong> Displays your corporation\'s custom hangar division names</li>
        <li><strong>Recent movements:</strong> Timeline of reserve additions and removals</li>
        <li><strong>Refuel events:</strong> Tracks when fuel was moved from reserves to bays</li>
        <li><strong>Purple indicators:</strong> Special badges for magmatic gas reserves (Metenox)</li>
    </ul>',

    'logistics_page_title' => 'Logistics Report Page',
    'logistics_page_desc' => '<ul>
        <li><strong>System organization:</strong> Fuel requirements grouped by solar system</li>
        <li><strong>Multi-timeframe:</strong> 30, 60, and 90-day projections</li>
        <li><strong>Hauler calculations:</strong> Number of hauler trips required (based on 62,500 m³ capacity)</li>
        <li><strong>Total volumes:</strong> m³ required for efficient cargo planning</li>
        <li><strong>Metenox support:</strong> Dual-fuel requirements listed separately</li>
        <li><strong>CSV export:</strong> Export data for your logistics team</li>
        <li><strong>Jump planning:</strong> System-by-system breakdown for route planning</li>
    </ul>',

    'economics_page_title' => 'Fuel Economics Page (requires Manager Core)',
    'economics_page_desc' => '<ul>
        <li><strong>ISK projections:</strong> weekly / monthly / quarterly / yearly fuel cost across every structure you can see, computed from the same active-services rate the Logistics Report uses</li>
        <li><strong>Cheapest fuel suggestion:</strong> picks the lowest-priced of the 4 fuel block types right now and uses it to price all Upwell + Metenox projections (those structures can substitute freely)</li>
        <li><strong>Optimization banner:</strong> when one or more structures are running on a more expensive type than the cheapest, surfaces the monthly / yearly savings you would unlock by switching</li>
        <li><strong>Per-system breakdown:</strong> table sorted by spend descending so the most-expensive systems are at the top</li>
        <li><strong>Per-structure breakdown:</strong> Current fuel + status (already optimal / switch suggestion / racial locked) plus per-structure monthly savings and offline days</li>
        <li><strong>Daily ISK trend:</strong> stacked area chart over the look-back window (90 / 180 / 365 days) showing daily fuel cost</li>
        <li><strong>By Fuel Type pie:</strong> doughnut chart breaking the period total down by fuel typeID</li>
        <li><strong>Structure breakdown banner:</strong> count of Upwell / Metenox / POS structures included, with race split for POSes</li>
        <li><strong>Force refresh button:</strong> bypasses the 5-minute cache when you need ground-truth numbers</li>
    </ul>',

    'detail_page_title' => 'Structure Detail Page',
    'detail_page_desc' => '<ul>
        <li><strong>Comprehensive fuel dashboard:</strong> Complete overview of one structure</li>
        <li><strong>Consumption breakdown:</strong> Hourly, daily, weekly, monthly rates</li>
        <li><strong>Service tracking:</strong> Lists active services and their fuel impact</li>
        <li><strong>Historical charts:</strong> Visual graphs of fuel consumption over time</li>
        <li><strong>Refuel event log:</strong> Timeline of when fuel was added</li>
        <li><strong>Recent Fuel Records:</strong> Per-poll event classification badges (v2.0.0 fuel forensics)</li>
        <li><strong>Reserve history:</strong> Staged fuel movements for this structure</li>
        <li><strong>Metenox dual-display:</strong> Separate charts and stats for fuel blocks and gas</li>
        <li><strong>Control Tower dual-display:</strong> Separate charts and stats for fuel blocks and charters (if required), separate status for Strontium Clathrates</li>
    </ul>
    <p><em>Not a sidebar entry — you reach the Structure Detail page by clicking any structure on the Upwell Structures or Control Towers pages.</em></p>',

    'command_board_page_title' => 'Structure Board',
    'command_board_page_desc' => '<ul>
        <li><strong>Timer board:</strong> Central view of every active structure timer — reinforcement timers, anchoring timers, and admin-created manual ops</li>
        <li><strong>Auto-population:</strong> ESI structure events (shield / armor reinforced, anchoring) create board entries automatically</li>
        <li><strong>Live countdowns:</strong> Countdown to each timer\'s exit, grouped and sorted by urgency</li>
        <li><strong>Manual ops:</strong> Admins can add hostile / defensive operation timers directly to the board, with a target structure type and notes</li>
        <li><strong>Auto-cleanup:</strong> Elapsed and resolved timers are pruned automatically so the board stays current</li>
    </ul>',

    'settings_page_title' => 'Settings',
    'settings_page_desc' => '<ul>
        <li><strong>Fuel thresholds:</strong> Warning and critical day cutoffs that drive fuel alerts</li>
        <li><strong>Reserves Tracking:</strong> Choose which CorpSAG hangars are included in reserve calculations</li>
        <li><strong>ESI Detection Mode:</strong> Choose how structure events are detected (auto / SeAT-native / off)</li>
        <li><strong>Notifications panel:</strong> Webhooks, notification categories, and Discord role mentions all live inside Settings</li>
        <li><strong>Admin-only:</strong> Requires the <code>structure-manager.admin</code> permission. See the dedicated Settings section of this help page for the full walkthrough.</li>
    </ul>',

    'pro_tip' => 'Pro Tip',
    'pages_pro_tip' => 'Start your workflow with the Critical Alerts page each day, then use the Logistics Report when planning hauling operations. The Dashboard provides the big picture view.',

    // Commands
    'commands_title' => 'Artisan Commands Reference',
    'commands_intro' => 'Structure Manager provides several artisan commands for manual operations and maintenance.',
    'commands_notification_note' => '<strong>Note:</strong> For detailed information about the notification system, webhook configuration, and alert behavior, see the <a href="#notifications">Notifications section</a>.',

    'track_fuel_cmd_title' => 'Track Fuel (Levels + Reserves)',
    'track_fuel_cmd_desc' => 'Tracks fuel consumption and reserves for all Upwell structures. This command performs two operations: (1) Fuel Bay Tracking - reads fuel bay data (what\'s being consumed), and (2) Reserve Tracking - scans corporation hangars for staged fuel (what\'s in storage). Supports both standard fuel blocks and magmatic gas for Metenox Moon Drills.',
    'track_fuel_cmd_note' => 'This command runs automatically every hour at :15 past the hour (e.g., 00:15, 01:15, 02:15...). Manual execution is only needed for troubleshooting or immediate updates.',

    'analyze_fuel_cmd_title' => 'Analyze Fuel Consumption',
    'analyze_fuel_cmd_desc' => 'Analyzes fuel bay history to calculate consumption rates, detect anomalies, and identify refuel events. Can analyze all structures, a specific structure, or all structures for a specific corporation.',
    'analyze_fuel_cmd_note' => 'This command runs automatically every hour at :30 past the hour (e.g., 00:30, 01:30, 02:30...). The analysis uses recent fuel bay snapshots to determine consumption patterns.',
    'analyze_fuel_cmd_options' => '<strong>Options:</strong>
    <ul>
        <li><code>--structure=ID</code> - Analyze a specific structure by ID</li>
        <li><code>--corporation=ID</code> - Analyze all structures for a specific corporation</li>
    </ul>
    <strong>Examples:</strong>
    <pre><code># Analyze all structures
php artisan structure-manager:analyze-consumption

# Analyze specific structure
php artisan structure-manager:analyze-consumption --structure=1234567890

# Analyze all structures for a corporation
php artisan structure-manager:analyze-consumption --corporation=98765432</code></pre>',

    'cleanup_history_cmd_title' => 'Clean Up Old History',
    'cleanup_history_cmd_desc' => 'Removes old fuel history records to maintain database performance and save storage space. Cleans up Upwell structure fuel history, POS fuel history, and consumption records.',
    'cleanup_history_cmd_note' => 'This command runs automatically daily at 3:00 AM. Once deleted, historical data cannot be recovered!',
    'cleanup_history_cmd_options' => '<strong>Options:</strong>
    <ul>
        <li><code>--days=180</code> - Days to retain Upwell structure history (default: 180)</li>
        <li><code>--pos-days=90</code> - Days to retain POS history (default: 90)</li>
    </ul>
    <strong>What gets cleaned:</strong>
    <ul>
        <li>Upwell structure fuel history older than specified days</li>
        <li>POS fuel history older than specified days</li>
        <li>Structure consumption records older than 6 months</li>
        <li>POS consumption records older than 3 months</li>
    </ul>
    <strong>Examples:</strong>
    <pre><code># Use defaults (180 days for structures, 90 days for POS)
php artisan structure-manager:cleanup-history

# Keep 365 days of structure history, 180 days of POS history
php artisan structure-manager:cleanup-history --days=365 --pos-days=180

# Keep only 30 days of all history
php artisan structure-manager:cleanup-history --days=30 --pos-days=30</code></pre>',

    'create_test_metenox_cmd_title' => 'Create Test Metenox Structures (Development)',
    'create_test_metenox_cmd_desc' => 'Creates test structures (Metenox Moon Drill + Astrahus) with realistic dual-fuel data for testing and demonstration purposes.',
    'create_test_metenox_cmd_note' => 'This command is designed for testing the plugin\'s Metenox dual-fuel tracking features. It creates:',
    'create_test_metenox_features' => '<ul>
        <li><strong>Test Metenox Moon Drill:</strong> With fuel blocks (1860 blocks = 15.5 days) and magmatic gas (59040 units = 12.3 days, LIMITING factor)</li>
        <li><strong>Test Astrahus:</strong> With staged fuel block reserves (3600 blocks in CorpSAG3) and magmatic gas reserves (144000 units in CorpSAG4)</li>
        <li><strong>Fuel history snapshots:</strong> Initial tracking data for both structures</li>
        <li><strong>Active services:</strong> Automatic Moon Drilling on Metenox, Clone Bay on Astrahus</li>
    </ul>',
    'create_test_metenox_usage' => 'Usage examples:',
    'create_test_metenox_create' => '<pre><code>php artisan structure-manager:create-test-metenox</code></pre>
    <p>Creates test structures with realistic dual-fuel scenarios</p>',
    'create_test_metenox_cleanup' => '<pre><code>php artisan structure-manager:create-test-metenox --cleanup</code></pre>
    <p>Removes all test data when you\'re done testing</p>',
    'create_test_metenox_uses' => '<strong>What you can test with this command:</strong>
    <ul>
        <li>Dual-fuel tracking for Metenox structures (fuel blocks + magmatic gas)</li>
        <li>Limiting factor detection (gas runs out before fuel blocks in this test)</li>
        <li>Purple visual indicators for gas-related data</li>
        <li>Reserve tracking for both fuel types</li>
        <li>Logistics report generation with dual-fuel requirements</li>
        <li>Critical alerts for structures with multiple fuel types</li>
        <li>Fuel detail pages showing separate charts for each fuel type</li>
    </ul>',

    // POS Management
    'pos_management_title' => 'POS (Player Owned Starbase) Management',
    'pos_overview' => 'Structure Manager provides comprehensive fuel tracking and monitoring for legacy Player Owned Starbases (POS towers). The system monitors fuel blocks, strontium clathrates, and starbase charters across all security space types.',
    
    'pos_features_title' => 'POS Features',
    'pos_features_desc' => '<ul>
        <li><strong>Multi-resource tracking:</strong> Monitors fuel blocks, strontium clathrates, and starbase charters (for High Security space)</li>
        <li><strong>Security space awareness:</strong> Automatically detects charter requirements for High Security systems</li>
        <li><strong>Real-time monitoring:</strong> Fuel tracking every 10 minutes with precise day/hour calculations</li>
        <li><strong>Consumption analytics:</strong> Historical data tracking (90 days) and daily consumption analysis</li>
        <li><strong>Critical alerts:</strong> Status-based notifications for low fuel, strontium, or charter levels</li>
        <li><strong>Limiting factor detection:</strong> Identifies which resource will run out first</li>
        <li><strong>Discord/Slack integration:</strong> Webhook notifications with separate alerting for fuel and strontium</li>
        <li><strong>Smart notification system:</strong> Status changes, optional critical reminders, and 1-hour final alerts</li>
    </ul>',

    'pos_fuel_types_title' => 'POS Fuel Types',
    'pos_fuel_types_desc' => 'POSes require different resources depending on configuration and location:',
    'pos_fuel_blocks' => '<strong>Fuel Blocks:</strong> Standard fuel consumed at variable rates based on tower size (Small: 10/hour, Medium: 20/hour, Large: 40/hour). Required for all POS operations.',
    'pos_strontium' => '<strong>Strontium Clathrates:</strong> Used exclusively for reinforcement mode. Consumption rate varies by tower size (Small: 100/hour, Medium: 200/hour, Large: 400/hour). Not consumed during normal operations.',
    'pos_charters' => '<strong>Starbase Charters:</strong> Required ONLY for POSes in High Security space. Consumed at 1 charter per hour regardless of tower size. Not required in null-sec, low-sec, or NPC null-sec.',

    'pos_space_types_title' => 'Security Space Tracking',
    'pos_space_types_desc' => 'The plugin automatically detects and tracks charter requirements based on system sovereignty:',
    'pos_sovereign_nullsec' => '<strong>High Security space:</strong> Requires starbase charters (1/hour). Plugin tracks charter levels alongside fuel and strontium.',
    'pos_other_space' => '<strong>All Other Space:</strong> No charters required. Plugin tracks only fuel blocks and strontium.',

    'pos_notifications_title' => 'POS Fuel Notifications',
    'pos_notifications_desc' => 'Automated Discord/Slack notifications with intelligent status-based alerting:',
    'pos_notification_features' => '<ul>
        <li><strong>Separate fuel & strontium alerts:</strong> Independent notifications for operational fuel vs defensive strontium</li>
        <li><strong>Configurable thresholds:</strong>
            <ul>
                <li>Fuel critical: Default 7 days (configurable)</li>
                <li>Fuel warning: Default 14 days (configurable)</li>
                <li>Strontium critical: Default 6 hours (configurable)</li>
                <li>Strontium warning: Default 12 hours (configurable)</li>
                <li>Charter critical: Default 7 days (configurable)</li>
            </ul>
        </li>
        <li><strong>Smart notification triggers:</strong>
            <ul>
                <li>Status change alerts: Sent when POS moves between good→warning→critical</li>
                <li>Final alert (1 hour): Urgent notification when 1 hour of fuel/strontium remains</li>
                <li>Optional critical reminders: Configurable intervals during critical stage</li>
                <li>Fuel/charter reminders: Default 6 hours (or disable with 0)</li>
                <li>Strontium reminders: Default 2 hours (or disable with 0)</li>
            </ul>
        </li>
        <li><strong>Rich embed format:</strong> Color-coded alerts with location, tower type, resource levels, and limiting factors</li>
        <li><strong>Role mentions:</strong> Optional Discord role pinging for critical alerts only</li>
        <li><strong>Customizable avatar:</strong> Uses avatar configured in your Discord webhook settings</li>
    </ul>',

    'pos_settings_title' => 'POS Notification Settings',
    'pos_settings_desc' => 'Configure POS notifications in the Settings page:',
    'pos_settings_webhook' => '<strong>Webhook URL:</strong> Your Discord or Slack webhook URL for notifications',
    'pos_settings_thresholds' => '<strong>Alert Thresholds:</strong> Customize when to receive critical and warning alerts',
    'pos_settings_intervals' => '<strong>Notification Intervals:</strong> Set how often to receive repeated alerts for the same POS',
    'pos_settings_mentions' => '<strong>Discord Role Mention:</strong> Optionally mention a role (e.g., @FuelTeam) for critical alerts',

    'pos_commands_title' => 'POS Commands',
    'pos_track_fuel_title' => 'Track POS Fuel',
    'pos_track_fuel_desc' => 'Monitors all corporation POSes for fuel blocks, strontium, and charters. Runs every 10 minutes via scheduler for real-time data.',
    'pos_track_fuel_cmd' => 'php artisan structure-manager:track-poses-fuel',
    
    'pos_analyze_title' => 'Analyze POS Consumption',
    'pos_analyze_desc' => 'Analyzes POS fuel consumption patterns and calculates daily usage rates. Runs daily at 1:00 AM via scheduler.',
    'pos_analyze_cmd' => 'php artisan structure-manager:analyze-pos-consumption',
    
    'pos_notify_title' => 'POS Low Fuel Notifications',
    'pos_notify_desc' => 'Checks all POSes for low fuel/strontium/charter levels and sends webhook notifications. Runs every 10 minutes via scheduler.',
    'pos_notify_cmd' => 'php artisan structure-manager:notify-pos-fuel',

    'simulate_consumption_title' => 'Simulate Fast Consumption (Development)',
    'simulate_consumption_desc' => 'Simulates rapid fuel consumption for testing notifications without waiting days. Only works with test POSes created by the create-test-poses command. Reduces fuel levels artificially in 20-minute cycles.',
    'simulate_consumption_cmd' => 'php artisan structure-manager:simulate-consumption',
    'simulate_consumption_options' => '<strong>Options:</strong>
    <ul>
        <li><code>--cycles=1</code> - Number of 20-minute cycles to simulate (default: 1)</li>
        <li><code>--test-only</code> - Only process test POSes (IDs >= 1000000000)</li>
    </ul>
    <strong>Examples:</strong>
    <pre><code># Simulate one 20-minute cycle
php artisan structure-manager:simulate-consumption

# Simulate 10 cycles (simulate ~3.3 hours of consumption)
php artisan structure-manager:simulate-consumption --cycles=10

# Simulate 72 cycles (simulate 24 hours in ~24 minutes)
php artisan structure-manager:simulate-consumption --cycles=72</code></pre>
    <strong>Testing Workflow:</strong>
    <pre><code># 1. Create test POSes with fast consumption
php artisan structure-manager:create-test-poses --fast-consumption

# 2. Simulate consumption cycles
php artisan structure-manager:simulate-consumption --cycles=20

# 3. Trigger notifications
php artisan structure-manager:notify-pos-fuel

# 4. Cleanup when done
php artisan structure-manager:create-test-poses --cleanup</code></pre>
    <div class="warning-box"><i class="fas fa-exclamation-triangle"></i> <strong>Testing Only:</strong> This manipulates fuel levels artificially. Do not use in production!</div>',

    'create_test_poses_title' => 'Create Test POSes (Development)',
    'create_test_poses_desc' => 'Creates realistic test POSes across multiple corporations for testing webhook filtering, notifications, and fuel consumption scenarios. Uses safe ID ranges far outside EVE\'s data allocation (corporations: 2.1B range, POSes: 2.2B range).',
    'create_test_poses_cmd' => 'php artisan structure-manager:create-test-poses',
    'create_test_poses_features' => '<strong>Features:</strong>
        <ul>
            <li><strong>Multiple corporations:</strong> Create 1-10 test corporations (default: 3)</li>
            <li><strong>Multiple POSes per corp:</strong> Add 1-10 POSes to each corporation (default: 2)</li>
            <li><strong>Varied scenarios:</strong> Automatically creates POSes with different fuel levels (critical, warning, good, zero strontium)</li>
            <li><strong>Security space variety:</strong> POSes in high-sec (with charters), low-sec, and null-sec systems</li>
            <li><strong>Tower type diversity:</strong> Various Amarr, Caldari, Gallente, and Minmatar towers (small/medium/large)</li>
            <li><strong>Fuel reserves:</strong> Automatically creates hangar reserves for testing reserve tracking</li>
            <li><strong>Fast consumption mode:</strong> Optional 20-minute fuel cycles for rapid testing</li>
            <li><strong>Complete cleanup:</strong> Easy removal of all test data with --cleanup flag</li>
        </ul>',
    'create_test_poses_usage_title' => 'Usage Examples',
    'create_test_poses_usage' => '<pre><code># Create 3 corporations with 2 POSes each (defaults)
php artisan structure-manager:create-test-poses

# Create 5 corporations with 4 POSes each
php artisan structure-manager:create-test-poses --corporations=5 --poses-per-corp=4

# Enable fast consumption for rapid testing (20-min cycles)
php artisan structure-manager:create-test-poses --fast-consumption

# Remove all test data
php artisan structure-manager:create-test-poses --cleanup</code></pre>',
    'create_test_poses_use_cases' => '<strong>Testing Scenarios:</strong>
        <ul>
            <li><strong>Webhook filtering:</strong> Create multiple test corporations to verify webhook corporation filters work correctly</li>
            <li><strong>Notification testing:</strong> Generate POSes at critical/warning fuel levels to test Discord alerts</li>
            <li><strong>Multiple webhooks:</strong> Test that notifications are sent to correct channels based on corporation filters</li>
            <li><strong>Fuel consumption:</strong> Use --fast-consumption to verify fuel tracking and calculations</li>
            <li><strong>Zero strontium behavior:</strong> Test POSes include scenarios with 0 strontium for testing alerts</li>
            <li><strong>Charter tracking:</strong> High-sec POSes automatically require charters for testing</li>
            <li><strong>Role mentions:</strong> Test per-webhook role mentions with different corporations</li>
        </ul>',

    // 2026-05-12: documentation for the 7 commands that were shipped but
    // never made it into the Commands page. Grouped into two subsections —
    // operational (cron-driven background jobs operators should know about
    // because they appear in schedules + Horizon) and test (the test-data
    // family operators interact with directly during verification).
    'commands_additional_title' => 'Additional Commands',
    'commands_additional_intro' => 'These commands were previously undocumented but are part of the shipped plugin. Most run on schedule; a few are operator-invoked for testing.',

    'commands_operational_title' => 'Operational Background Jobs',
    'commands_operational_intro' => 'Scheduled commands you will see in Horizon and the SeAT scheduler. You normally do not run these manually, but knowing what they do helps when reading logs or troubleshooting.',

    'process_notifications_title' => 'process-notifications',
    'process_notifications_desc' => 'SeAT-native fallback for ESI notification processing. Reads from SeAT\'s <code>character_notifications</code> table and dispatches Structure Manager webhooks. Used when Manager Core is absent (MC fast-poll is unavailable) or when the operator has set <code>esi_detection_mode = seat_native</code> to opt out of MC fast-poll. The job\'s handle() includes a mode-aware gate so it correctly no-ops when MC is handling notifications.',
    'process_notifications_cron' => 'Cron: <code>* * * * *</code> (every minute). Detection floor is SeAT\'s 15-20 min bucket cadence; running the job every minute means SM picks up new rows immediately when SeAT writes them.',

    'track_structure_presence_title' => 'track-structure-presence',
    'track_structure_presence_desc' => 'Destruction-detection medium-confidence path. Tracks corporation_structures membership over time so structures that vanish for 3+ polls (~30 min absent) can be classified as destroyed / likely_transferred / bulk_vanished. The high-confidence path (CCP StructureDestroyed notification) fires from StructureEventHandler regardless of whether MC is installed; this medium-confidence path is the safety net for cases where the notification was missed.',
    'track_structure_presence_cron' => 'Cron: <code>*/10 * * * *</code> (every 10 minutes). Three consecutive absences = classified as gone.',

    'publish_timer_schedule_events_title' => 'publish-timer-schedule-events',
    'publish_timer_schedule_events_desc' => 'Cross-plugin timer-lifecycle event publisher. Fires <code>structure_manager.timer.upcoming_24h</code>, <code>.upcoming_1h</code>, and <code>.elapsed</code> events on Manager Core\'s EventBus for each tracked timer that crosses a threshold. Consumed by SeAT Broadcast (when its calendar feature lands) and any future fleet-planning subscriber.',
    'publish_timer_schedule_events_cron' => 'Cron: <code>*/5 * * * *</code> (every 5 minutes). Without this command running, the Family B timer.* events never fire even though the Family A alert.* events still do.',

    'prune_structure_board_timers_title' => 'prune-structure-board-timers',
    'prune_structure_board_timers_desc' => 'Daily housekeeping. Deletes old dismissed Structure Board timer rows so the table does not grow unbounded. Without this scheduled, dismissed rows accumulate forever.',
    'prune_structure_board_timers_cron' => 'Cron: <code>0 4 * * *</code> (daily at 04:00 UTC).',

    'commands_test_title' => 'Test-Data Commands',
    'commands_test_intro' => 'For end-to-end verification of webhook delivery, EventBus publishing, and the dispatch chain. All test data lives in declared safe ID ranges (corporations 2.1B / structures 2.3B / characters 2.4B / POSes 2.2B / notifications 8e18+) so production data cannot be accidentally affected.',

    'create_test_upwell_structures_title' => 'create-test-upwell-structures',
    'create_test_upwell_structures_desc' => 'Creates 12 test Upwell structures (every published Upwell type) anchored in a test system, owned by a test corporation. Used as targets for inject-test-notification. Idempotent — running it twice does not create duplicates.',
    'create_test_upwell_structures_usage' => '<pre><code>php artisan structure-manager:create-test-upwell-structures</code></pre>',

    'inject_test_notification_title' => 'inject-test-notification',
    'inject_test_notification_desc' => 'Inject a fake CCP-shaped notification into SeAT\'s <code>character_notifications</code> table, then synchronously dispatch via StructureEventHandler. Used for verifying the full dispatch chain (embed building, role mention injection, webhook delivery, EventBus publishing) without waiting for a real in-game event.',
    'inject_test_notification_usage' => '<pre><code># Most commonly used flavors
php artisan structure-manager:inject-test-notification --structure-id=2300000001 --type=StructureUnderAttack --attacker-corp="Test Aggressor"
php artisan structure-manager:inject-test-notification --structure-id=2300000001 --type=StructureLostShields --time-left=86400
php artisan structure-manager:inject-test-notification --structure-id=2300000001 --type=StructureDestroyed

# See all 23 supported types
php artisan structure-manager:inject-test-notification --list</code></pre>',
    'inject_test_notification_safety' => '<strong>Safety:</strong> all gates fail-closed. Structure ID must be in the test range (2.3B+), character ID must be in the test character range, and the generated notification_id (8e18+) cannot collide with real CCP IDs. Refuses to inject against a real structure under any circumstances.',

    'cleanup_test_data_title' => 'cleanup-test-data',
    'cleanup_test_data_desc' => 'Symmetric teardown for everything the test-data commands create. Wraps <code>TestDataGenerator::cleanupAll()</code> and extends the sweep to Manager Core\'s notification dedup table. Bounded by the same safe-range constants as the create commands, so real CCP data cannot be touched.',
    'cleanup_test_data_usage' => '<pre><code># Show what would be deleted, without deleting
php artisan structure-manager:cleanup-test-data --dry-run

# Interactive with confirmation prompt
php artisan structure-manager:cleanup-test-data

# Skip the prompt (useful in CI scripts)
php artisan structure-manager:cleanup-test-data --force</code></pre>',
    'cleanup_test_data_output' => '<strong>Output:</strong> a table showing rows-deleted per affected table (character_notifications, structure_manager_esi_notifications, manager_core_esi_notifications, corporation_structures, character_infos, etc.) plus a summary count. Idempotent — running twice on already-clean data is a harmless no-op.',

    'pos_example_title' => 'POS Tracking Example',
    'pos_example_desc' => 'A Large Amarr Control Tower in High Security requires:',
    'pos_example_list' => '<ul>
        <li><strong>Fuel Blocks:</strong> 40 per hour (960 per day)</li>
        <li><strong>Strontium:</strong> 400 per hour when reinforced (9,600 per day)</li>
        <li><strong>Charters:</strong> 1 per hour (24 per day) - High Security only</li>
    </ul>',
    'pos_example_limiting' => 'If this POS has 20,000 fuel blocks, 5,000 strontium, and 100 charters, the <strong>charters</strong> would be marked as the limiting factor (4.2 days remaining vs 20.8 days of fuel).',

    'automation' => 'Automation',
    'automation_note' => 'All commands are configured to run automatically via Laravel\'s task scheduler. The plugin registers these schedules during installation, so no cron configuration is needed beyond SeAT\'s standard setup.',

    'note' => 'Note',
    'commands_warning' => 'Manual command execution is rarely necessary. The automatic scheduling handles all routine operations. Only use manual commands for testing or troubleshooting purposes.',

    // Custom Styling
    'custom_styling' => 'Custom Styling',
    'custom_styling_guide' => 'CSS Overrides Guide',
    'custom_styling_intro' => 'Structure Manager wraps every page in CSS hook classes, so you can restyle any part of the plugin from SeAT\'s custom CSS feature or your own theme stylesheet — without editing the plugin\'s files (which are overwritten on every update).',
    'css_class_hierarchy' => 'CSS Class Hierarchy',
    'css_class_hierarchy_desc' => 'Structure Manager uses a small, deliberate set of hook classes:',
    'css_base_class' => '<code>.structure-manager-wrapper</code> — present on EVERY plugin page. The global hook: style this to affect all of Structure Manager at once.',
    'css_settings_class' => '<code>.settings-page</code> — added alongside the wrapper on the Settings page only.',
    'css_diagnostic_class' => '<code>.diagnostic-page</code> — added alongside the wrapper on the Admin Diagnostics page only.',
    'css_components_title' => 'Reusable Component Classes',
    'css_components_desc' => 'Most of the plugin\'s chrome is built from a few shared component classes — target these to restyle a widget everywhere it appears:',
    'css_component_card' => '<code>.card-dark</code> — the dark card chrome (header + body) used on every page.',
    'css_component_cardtitle' => '<code>.card-title</code> — the heading inside a card header.',
    'css_component_cardtools' => '<code>.card-tools</code> — the button / filter cluster on the right of a card header.',
    'css_component_infobox' => '<code>.info-box</code> + <code>.info-box-icon</code> — the coloured stat cards (Fuel Summary, Critical / Warning / Total Fuel Needed). <code>.info-box-icon</code> is the coloured square; the icon glyph inside it sizes with <code>font-size</code>.',
    'css_component_btn' => '<code>.btn-sm-primary</code> — the indigo primary buttons (Refresh, Save, etc.).',
    'css_example_title' => 'Example Overrides',
    'css_example_global' => '/* Tint the background of every Structure Manager page */',
    'css_example_global_code' => '.structure-manager-wrapper { background-color: #0d0d12; }',
    'css_example_specific' => '/* Restyle the card border on the Settings page only */',
    'css_example_specific_code' => '.settings-page .card-dark { border-color: #667eea; }',
    'css_example_icon' => '/* Resize the coloured stat-card icons (handy on a custom SeAT theme) */',
    'css_example_icon_code' => '.structure-manager-wrapper .info-box .info-box-icon i { font-size: 2.25rem; }',
    'css_where_to_add' => 'Where to Add Custom CSS',
    'css_where_to_add_desc' => 'SeAT auto-loads two custom stylesheets if they exist: <code>custom-layout.css</code> (applies app-wide) and <code>custom-layout-mini.css</code> (the sign-in page only). On a bare-metal install, drop <code>custom-layout.css</code> into SeAT\'s <code>public/</code> directory (e.g. <code>/var/www/seat/public/custom-layout.css</code>). On SeAT Docker, place it in <code>/opt/seat-docker/custom/</code> and mount it to <code>/var/www/seat/public/</code> via <code>docker-compose.override.yml</code>, then bring the stack back up. The file is detected automatically — there is no SeAT setting to toggle. Never edit the plugin\'s own files; they are overwritten on every update. Full guide: <a href="https://eveseat.github.io/docs/styling/" target="_blank" rel="noopener">SeAT styling docs</a>.',
    'custom_styling_note' => 'Structure Manager\'s own stylesheet is written for the standard SeAT layout. If you run a custom SeAT theme, small visual tweaks belong in your theme\'s CSS — keeping the plugin standard-clean and your theme adjustments separate means both survive updates independently.',

    // FAQ
    'frequently_asked' => 'Frequently Asked Questions',
    
    'faq_q1' => 'Q1: Why are my fuel consumption numbers different from what I calculated?',
    'faq_a1' => 'Make sure you\'re accounting for all online services and using correct fuel mechanics. Remember: (1) Only online services consume fuel, (2) Multi-service modules count as one module, (3) Moon drills get NO bonuses, (4) Refinery bonuses ONLY apply to reprocessing and reactions. Check the Fuel Mechanics section for detailed examples.',

    'faq_q2' => 'Q2: My structures aren\'t showing up in the dashboard. What\'s wrong?',
    'faq_a2' => 'First, verify that SeAT has synced your corporation\'s structure data. Check Corporation > Structures in SeAT to confirm structures are visible there. If structures exist in SeAT but not in Structure Manager, wait for the next hourly tracking cycle or manually run <code>php artisan structure-manager:track-fuel</code>.',

    'faq_q3' => 'Q3: Can I track fuel for multiple corporations?',
    'faq_a3' => 'Yes! Structure Manager automatically tracks all corporations that your SeAT installation manages. The dashboard includes filters to view specific corporations, and all pages support multi-corporation data.',

    'faq_q4' => 'Q4: How often does the plugin check fuel levels?',
    'faq_a4' => 'Upwell structures: fuel bay levels tracked hourly, consumption analysis runs every 30 minutes, CorpSAG hangar reserves tracked hourly as part of the same pass. POS towers: fuel bay, strontium, and charter inventories all tracked every 10 minutes for real-time monitoring; notifications checked every 10 minutes. POSes have no CorpSAG hangars and are not represented on the Reserves page. These schedules are automatic and require no configuration.',

    'faq_q5' => 'Q5: What happens if I refuel a structure?',
    'faq_a5' => 'The plugin automatically detects refuel events by analyzing fuel bay history. Significant increases in fuel levels are logged as refuel events, which appear in the structure detail page and can help track refueling operations.',

    'faq_q6' => 'Q6: Does the plugin work with offline structures?',
    'faq_a6' => 'The plugin tracks all structures, but offline structures don\'t consume fuel so they won\'t show fuel consumption data. Once a structure comes online and services activate, fuel tracking begins automatically.',

    'faq_q7' => 'Q7: Can I see historical fuel data?',
    'faq_a7' => 'Yes! The plugin retains 6 months of fuel bay history for Upwell structures and 90 days for POSes (updated more frequently). Reserve history is retained for 3 months. Visit any structure\'s detail page to see consumption charts, refuel events, and historical trends.',

    'faq_q8' => 'Q8: How does reserve tracking work?',
    'faq_a8' => 'The plugin scans all structure hangars for fuel blocks (the four block types — Nitrogen 4051, Hydrogen 4246, Helium 4247, Oxygen 4312) and magmatic gas (Type ID: 81143) in CorpSAG divisions. It tracks quantities and locations, identifying which structures have staged fuel ready for use.',

    'faq_q9' => 'Q9: What is the "limiting factor" on Metenox drills?',
    'faq_a9' => 'The limiting factor is whichever resource (fuel blocks or magmatic gas) will run out first. Since both are required for operation, the plugin highlights which resource needs priority hauling with a purple "LIMITING" badge.',

    'faq_q10' => 'Q10: Can I export logistics data?',
    'faq_a10' => 'Yes! The Logistics Report page includes a CSV export button. This exports fuel requirements by system, perfect for sharing with your logistics team or importing into other tools.',

    'faq_q11' => 'Q11: How accurate are fuel consumption calculations?',
    'faq_a11' => 'Very accurate. The plugin uses official EVE Online fuel mechanics and, when possible, calculates consumption from actual fuel bay data rather than service counts. It correctly handles multi-service modules, refinery bonuses, moon drills, and Metenox dual-fuel requirements.',

    'faq_q12' => 'Q12: Does the plugin send Discord notifications?',
    'faq_a12' => 'Yes! Discord/Slack webhook notifications are available for both POS and Upwell structure fuel alerts. Configure webhook URLs in Webhook Configuration (sidebar), then bind them to categories under Settings > Notifications. POS thresholds are configurable (Settings > POS Notifications) — useful for wormhole/null-sec deployments that need extended response times. Upwell thresholds are locked at 7d critical / 14d warning so every display surface (list, detail, board, embeds) agrees on what counts as critical. Notifications use status-based alerting (good→warning→critical transitions) with optional critical-stage reminders. Final alerts fire 1 hour before a structure goes offline. Metenox Moon Drills show dual-fuel data (blocks + magmatic gas) with limiting factor. Both POS and Upwell alerts share the same webhook configuration including per-webhook corporation filters and role mentions.',

    'faq_q13' => 'Q13: How does POS charter tracking work?',
    'faq_a13' => 'Starbase charters are automatically tracked for POSes in high-security space. The plugin detects system security level and monitors charter consumption (1/hour) alongside fuel blocks. POSes in low-sec, null-sec (both sovereign and NPC), or wormhole space don\'t require charters and won\'t show charter tracking.',

    'faq_q14' => 'Q14: Why are my POS fuel and strontium alerts separate?',
    'faq_a14' => 'Fuel blocks and strontium serve different purposes and have different urgency levels. Fuel is for daily operations (default critical at 7 days, configurable per install), while strontium is defensive (default critical at 6 hours, configurable per install). Wormhole and null-sec POS deployments often need higher critical thresholds for extended response times — adjust in Settings > POS Notifications. Separate status tracking with optional different reminder intervals (6 hours for fuel, 2 hours for strontium during critical stage) ensures appropriate notification frequency for each resource type.',

    'faq_q15' => 'Q15: What happens if a POS runs low on multiple resources?',
    'faq_a15' => 'The plugin identifies the "limiting factor" - whichever resource will run out first. This appears with a [LIMITING FACTOR] badge in alerts and on the POS detail page. For example, if fuel lasts 20 days but charters only last 4 days, charters are marked as limiting.',

    'faq_q16' => 'Q16: My Recent Fuel Records now shows a "Withdrawal from Bay" badge. What does that mean?',
    'faq_a16' => 'v2.0.0 reclassifies every fuel-tracking poll into one of eight event types. Most polls are <code>consumption_normal</code> (bay burned within ±15% of expected). A <code>withdrawal_bay</code> badge means the bay went down by more than 1.5x expected consumption — someone likely yanked fuel from the structure. <code>withdrawal_reserves</code> means a CorpSAG hangar dropped >=500 blocks without the bay gaining, which means fuel left the corp. For each withdrawal event, click the small magnifying-glass icon to see the forensic candidate list — corp members who collaterally match four signals (online during the window, personal hangar gain, has corp title, sold matching fuel on market). The list is probabilistic inference, NOT proof: ESI does not expose actor identity for asset moves, so the candidates are inferred from collateral SeAT data. False positives are inevitable (logistics alts moving fuel between hangars look identical to thieves).',

    'faq_q17' => 'Q17: What appears under an "External" card on the Fuel Reserves page?',
    'faq_a17' => 'v2.0.0 tracks CorpSAG fuel staged in two kinds of locations beyond your own structures: (a) <strong>NPC stations</strong> where your corp rents Offices (e.g. fuel staged in Jita 4-4 for hauling out), and (b) <strong>foreign Upwell structures</strong> where your corp has CorpSAG access — typically via Office rentals in friendly alliance-mates\' Fortizars used as forward staging points. Both appear as "External" badged cards under their real solar system, with the location name resolved from <code>staStations</code> (NPC) or <code>universe_structures</code> (foreign Upwell). If you don\'t see a location you expect, check: SeAT has polled <code>corporation_assets</code> recently (1-hour ESI cache), and SM\'s <code>track-fuel</code> command has run since the asset row updated (runs hourly at <code>:15</code>). Force-trigger with <code>php artisan structure-manager:track-fuel</code> if you don\'t want to wait.',

    'faq_q18' => 'Q18: How do I tell if my Discord webhook is actually working?',
    'faq_a18' => 'Go to <code>/structure-manager/diagnostic</code> (admin-only) and look at the "Webhook Delivery Health (Last 24h)" section on the Health Checks tab. Per-webhook table shows attempt count, success rate (green ≥95% / yellow ≥50% / red <50%), average response time, last attempt timestamp, and most recent failure with HTTP code + error preview. If a webhook shows 0 attempts for 24h but is enabled, either no notifications fired in that window OR your category bindings need review (Webhook Configuration tab → check what categories the webhook is bound to). Every dispatch since v2.0.0 is logged to <code>structure_manager_webhook_deliveries</code> with 30-day retention.',

    // Troubleshooting
    'troubleshooting_guide' => 'Troubleshooting Guide',
    'troubleshooting_intro' => 'Common issues and their solutions.',
    'common_issues' => 'Common Issues',

    'issue1_title' => '1. Structures Not Appearing',
    'issue1_desc' => 'If your structures don\'t show up in Structure Manager:',
    'issue1_solutions' => '<ul>
        <li><strong>Check SeAT structure data:</strong> Go to Corporation > Structures in SeAT. If structures aren\'t there, SeAT hasn\'t synced them yet.</li>
        <li><strong>Verify API tokens:</strong> Ensure your corporation has valid ESI tokens with structure permissions.</li>
        <li><strong>Run manual tracking:</strong> Execute <code>php artisan structure-manager:track-fuel</code> to force immediate tracking.</li>
        <li><strong>Check permissions:</strong> Verify you have the "structure-manager.view" permission assigned.</li>
        <li><strong>Wait for sync:</strong> Initial sync after installation can take 1-2 hours.</li>
    </ul>',

    'issue2_title' => '2. Incorrect Fuel Consumption Numbers',
    'issue2_desc' => 'If consumption numbers seem wrong:',
    'issue2_solutions' => '<ul>
        <li><strong>Verify service status:</strong> Check that your services are actually online in-game.</li>
        <li><strong>Review fuel mechanics:</strong> Ensure you understand EVE\'s fuel mechanics (see Fuel Mechanics section).</li>
        <li><strong>Check for recent changes:</strong> Recent service activations or deactivations may not reflect immediately.</li>
        <li><strong>Wait for analysis:</strong> Consumption rates are calculated from actual fuel bay data every 30 minutes.</li>
        <li><strong>Inspect detail page:</strong> View the structure detail page for consumption breakdown and anomaly indicators.</li>
    </ul>',

    'issue3_title' => '3. Missing Reserve Data',
    'issue3_desc' => 'If Upwell fuel reserves aren\'t showing on the Reserves page:',
    'issue3_solutions' => '<ul>
        <li><strong>Check hangar divisions:</strong> Reserves must be in CorpSAG hangar divisions (not personal hangars). POS towers do not have CorpSAG hangars and never appear on the Reserves page — their fuel/stront/charter inventories are on the POS detail pages instead.</li>
        <li><strong>Verify item types:</strong> The Reserves page tracks the four fuel block types (4051 Nitrogen, 4246 Hydrogen, 4247 Helium, 4312 Oxygen) plus Magmatic Gas (81143, Metenox only).</li>
        <li><strong>Asset sync required:</strong> SeAT must have synced corporation asset data (~1-hour ESI cache).</li>
        <li><strong>Run the tracker:</strong> Execute <code>php artisan structure-manager:track-fuel</code> to force a poll. CorpSAG reserves are detected as part of this same pass.</li>
        <li><strong>Wait for next cycle:</strong> CorpSAG reserves are scanned hourly with the main fuel pass.</li>
        <li><strong>Excluded hangar check:</strong> If a known hangar is missing, verify it isn\'t unchecked in Settings → Reserves Tracking.</li>
    </ul>',

    'issue4_title' => '4. Metenox Dual-Fuel Not Showing',
    'issue4_desc' => 'If Metenox Moon Drills aren\'t showing dual-fuel tracking:',
    'issue4_solutions' => '<ul>
        <li><strong>Verify structure type:</strong> Ensure the structure actually has a Metenox Moon Drill installed.</li>
        <li><strong>Check for magmatic gas:</strong> The drill needs magmatic gas present to show dual-fuel status.</li>
        <li><strong>Wait for tracking:</strong> Dual-fuel detection occurs during the next fuel tracking cycle.</li>
        <li><strong>Review service status:</strong> The Metenox Moon Drill service must be online.</li>
    </ul>',

    'issue5_title' => '5. Charts Not Loading on Detail Page',
    'issue5_desc' => 'If consumption charts don\'t appear:',
    'issue5_solutions' => '<ul>
        <li><strong>JavaScript required:</strong> Charts require JavaScript enabled in your browser.</li>
        <li><strong>Check console:</strong> Open browser developer console (F12) for error messages.</li>
        <li><strong>Clear cache:</strong> Try clearing your browser cache and reloading the page.</li>
        <li><strong>Insufficient data:</strong> Charts require at least a few hours of fuel history to display.</li>
        <li><strong>Verify history:</strong> Run analysis command to ensure fuel history exists.</li>
    </ul>',

    'issue6_title' => '6. POS Notifications Not Working or Triggering Incorrectly',
    'issue6_desc' => 'If POS webhook notifications aren\'t being sent or are triggering too frequently:',
    'issue6_solutions' => '<ul>
        <li><strong>Verify webhook configuration:</strong> Go to Settings and ensure webhook URL is correct and notifications are enabled.</li>
        <li><strong>Test webhook:</strong> Use the "Test Webhook" button in Settings to verify connectivity.</li>
        <li><strong>Check notification intervals:</strong> Review fuel and strontium reminder intervals. Set to 0 to disable interval reminders (receive only status change alerts).</li>
        <li><strong>Verify POS status:</strong> Notifications only trigger when POS status changes (good→warning→critical) or during critical interval reminders.</li>
        <li><strong>Check notification tracking:</strong> Review database table <code>starbase_fuel_history</code> for columns <code>last_fuel_notification_at</code>, <code>last_strontium_notification_at</code>, <code>last_fuel_notification_status</code>, and <code>last_strontium_notification_status</code> to see last notification times and status.</li>
        <li><strong>Inspect scheduler:</strong> Verify that <code>structure-manager:notify-pos-fuel</code> command is running every 10 minutes in SeAT scheduler.</li>
        <li><strong>Review logs:</strong> Check Laravel logs for webhook errors: <code>storage/logs/laravel.log</code></li>
        <li><strong>Validate thresholds:</strong> Ensure critical thresholds are less than warning thresholds in Settings.</li>
        <li><strong>Per-webhook role mentions:</strong> Verify each webhook\'s role ID format is correct: <code>&lt;@&amp;ROLE_ID&gt;</code></li>
        <li><strong>Final alert timing:</strong> Remember that final alerts are sent at exactly 1 hour remaining, regardless of intervals.</li>
        <li><strong>Notifications for removed POSes:</strong> If you receive alerts for POSes that no longer exist (unanchored/removed), this was fixed in v1.0.11. Update to the latest version. The tracking job now automatically detects and marks orphaned POSes as unanchored.</li>
    </ul>',

    // ===================================================================
    // Admin Troubleshooting (Diagnostics page + Test Notification Lab)
    // ===================================================================

    'admin_diagnostics_title' => 'Admin Troubleshooting & Diagnostics',
    'admin_diagnostics_intro' => 'Structure Manager ships with a hidden diagnostic page for admins. It is intentionally not in the sidebar (matches Mining Manager\'s convention) so daily ops aren\'t cluttered with troubleshooting tools, but the page is one URL away when you need it.',

    'admin_diagnostics_url_title' => 'Accessing the Diagnostics Page',
    'admin_diagnostics_url_desc' => 'The page lives at <code>/structure-manager/diagnostic</code>. It is gated by the <code>structure-manager.admin</code> permission — a regular pilot typing the URL gets a 403. Bookmark it once you\'ve found it.',

    'admin_diagnostics_what_title' => 'What\'s On The Diagnostics Page',
    'admin_diagnostics_what_list' => '<ul>
        <li><strong>Health Checks</strong> (default landing tab): environment, required tables, plugin tables, type ID verification (SDE), schedule status, webhook configuration, ESI coverage, notification state, registered Manager Core handler status, <strong>Pricing Integration</strong>, <strong>Webhook Delivery Health (Last 24h)</strong> (v2.0.0 — per-webhook attempt counts, success rate, last failure with HTTP code), and your resolved corp scope. Heavy checks cached 60s.</li>
        <li><strong>Type IDs (SDE)</strong>: verifies that every type ID the plugin hardcodes (fuel blocks, structure types, charters, magmatic gas) resolves correctly against your installed SDE. Flags name mismatches as informational warnings (not errors, since the plugin keys on type IDs not names).</li>
        <li><strong>Master Test</strong>: aggregates every Health Check into a pass / warn / fail score grouped by category (Runtime / Schema / Constants / Notifications / Other). Single-page health overview.</li>
        <li><strong>System Validation</strong>: verifies hardcoded constants and dependencies are sound. Threshold ordering, plugin classes autoload, plugin Eloquent models autoload, SeAT package classes still exist, Manager Core capability surface present, notification-handler coverage, PHP / Laravel baseline. Lazy-loaded — first visit triggers compute, then cached 30 min.</li>
        <li><strong>Settings Health</strong>: per-key audit of every plugin setting. Current value vs default, has-it-been-changed flag, is-it-respected flag, per-key validator. Detects orphan keys. Lazy-loaded; 30s cache. <strong>v2.0.0</strong>: deprecated settings at their default values are hidden (small footer at the bottom lists what\'s suppressed) — they\'d only show with a loud WARN if an operator accidentally set a value.</li>
        <li><strong>Data Integrity</strong>: read-only DB-level consistency checks. Plugin table inventory, FK orphans, stale dedup rows, settings table integrity, failed_jobs queue health. Lazy-loaded; 5-min cache.</li>
        <li><strong>Fuel Trace</strong>: pick one structure or POS, walk the full fuel pipeline showing what the plugin sees and would do for that specific row. Input row, universe context, reserves snapshot, fuel history, <strong>v2.0.0 forensics surfaces</strong> (event classification breakdown for the last 30 polls + forensic candidates list for the latest withdrawal event), threshold determination, notification gate, recent ESI dedup entries. Most powerful "why didn\'t I get alerted about X" debugging surface.</li>
        <li><strong>Notification Testing</strong>: buttons that dispatch the real production notification jobs on demand against real data. "Run Upwell notification check" runs the cron command, "Run POS notification check" same, "Run notification job now" forces the next ESI poll. Real jobs only — no synthetic data on this tab.</li>
        <li><strong>Notification Lab (DEV)</strong>: all synthetic-data dispatch paths. Inject fake CCP-shaped notifications through the full SM pipeline (Structure Board upsert → EventBus publish → Discord webhook embed) end-to-end. Also hosts the "Send Sample Upwell Alert" embed-preview form. <strong>v2.0.0</strong>: now carries the same red danger-zone warning as Test Data — without a Test webhook URL set, fake injections WILL hit real Discord channels. See the next section for full details.</li>
        <li><strong>Test Data (DEV)</strong>: generate test corporations, test POSes, test Metenox + Astrahus structures with realistic fuel scenarios. Used for exercising webhook filtering and dual-fuel logic. Includes a one-click cleanup that removes everything in safe ID ranges (test corps 2.1B, test POSes 2.2B, test structures 2.3B, test characters 2.4B, test notifications 8e18).</li>
    </ul>
    <p style="margin-top:0.8rem;"><strong>Every tab opens with a uniform "What this tab does / When to use / Heads up" intro box (v2.0.0)</strong> because the diagnostic page is intentionally not in this Help & Documentation section — tab intros are where you learn each surface\'s purpose. Health Checks is always the default landing tab on a fresh visit.</p>',

    'test_lab_title' => 'Test Notification Lab',
    'test_lab_intro' => 'The Notification Lab is the most thorough way to verify your webhook setup. It generates fake CCP-shaped notifications, injects them through SM\'s real dispatch pipeline, and routes the resulting Discord embed to a test webhook URL only — production webhooks never see test traffic.',

    'test_lab_workflow_title' => 'Workflow',
    'test_lab_workflow_list' => '<ol>
        <li><strong>Save a Test Webhook URL</strong> — paste any Discord webhook URL into the Test Webhook field at the top of the Notification Lab tab. All test injections route to this URL only. Production webhooks (your corp\'s real channels) are never hit by test traffic.</li>
        <li><strong>Generate test structures</strong> — click "Generate test Upwell structures" to create one of each Upwell type (Astrahus, Fortizar, Keepstar, Raitaru, Azbel, Sotiyo, Athanor, Tatara, Metenox, Ansiblex, Pharolux, Tenebrex). All structures live in the safe 2.3B ID range, so they cannot collide with real EVE data.</li>
        <li><strong>Inject a fake notification</strong> — pick a target structure from the dropdown, optionally tweak attacker context (corp / alliance / timer duration), then click any of the 24 type buttons grouped by family (Attack, Lifecycle, Fuel, Services, Sov). The injection is synchronous: the embed lands in your test Discord within seconds, with a <code>[TEST INJECTION]</code> banner.</li>
        <li><strong>Send dual-fuel embed</strong> — for Metenox specifically, the lab has a dedicated "Send dual-fuel embed" button that fires Structure Manager\'s own analysis-path embed (the rich one with <code>[LIMITING]</code> flags and predictive offline time). Pick magmatic gas or fuel blocks as the limiting factor.</li>
        <li><strong>Watch recent injections</strong> — the Recent panel shows the last 10 injected notifications with their dispatch status (queued / pending / processed) and auto-refreshes every 30 seconds.</li>
        <li><strong>Clean up when done</strong> — "Delete all test data" wipes every test corp, character, structure, POS, and notification in the safe ID ranges. Production data is protected by ID-range guards. The cleanup result shows a per-table breakdown of what was removed.</li>
    </ol>',

    'test_lab_paths_title' => 'Two Notification Paths You Can Test',
    'test_lab_paths_desc' => 'Structure Manager has two independent notification pipelines for fuel/reagent alerts. Both are useful and complementary; the Test Lab can exercise either:',
    'test_lab_paths_list' => '<ol>
        <li><strong>SM Analysis Path</strong> (the rich one) — <code>NotifyUpwellLowFuel</code> calculates limiting factor from <code>corporation_assets</code> every 10 min. For Metenox: shows fuel blocks vs. magmatic gas with <code>[LIMITING]</code> flag, predictive offline time, weekly fuel + gas requirement. Test it via the lab\'s "Send dual-fuel embed" button.</li>
        <li><strong>CCP Notification Relay</strong> (the simple one) — <code>StructureEventHandler</code> renders an embed when CCP itself sends <code>StructureFuelAlert</code> / <code>StructureLowReagentsAlert</code> / <code>StructureNoReagentsAlert</code>. Test it via the lab\'s 24-button injection panel (Fuel + Power family).</li>
    </ol>
    <p>In production both paths fire at different points in the depletion timeline: SM\'s analysis warns first based on consumption math (predictive); CCP\'s last-mile alert fires closer to actual depletion (reactive). Together they give defense-in-depth.</p>',

    'test_lab_supported_types_title' => 'Notification Types Supported by the Lab',
    'test_lab_supported_types_desc' => 'All 24 CCP notification types Structure Manager handles can be injected, grouped by family:',
    'test_lab_supported_types_list' => '<ul>
        <li><strong>Attack family (7):</strong> StructureUnderAttack, StructureLostShields, StructureLostArmor, StructureDestroyed, SkyhookUnderAttack, SkyhookLostShields, SkyhookDestroyed</li>
        <li><strong>Lifecycle (5):</strong> StructureAnchoring, AllAnchoringMsg, StructureUnanchoring, OwnershipTransferred, SkyhookDeployed</li>
        <li><strong>Fuel + power (6):</strong> StructureWentLowPower, StructureWentHighPower, StructureFuelAlert, StructureLowReagentsAlert, StructureNoReagentsAlert, SkyhookOnline</li>
        <li><strong>Services (1):</strong> StructureServicesOffline</li>
        <li><strong>Sovereignty (4):</strong> EntosisCaptureStarted, SovStructureReinforced, SovStructureDestroyed, SovCommandNodeEventStarted</li>
    </ul>
    <p>Each type produces a CCP-faithful YAML payload (verified against SeAT core\'s reference notification templates) and routes through the same handler your real notifications use. The embed you see in Discord is byte-identical to a real one (modulo the <code>[TEST INJECTION]</code> banner).</p>',

    'test_lab_safety_title' => 'Safety Guarantees',
    'test_lab_safety_list' => '<ul>
        <li><strong>Test webhook isolation:</strong> notifications in the safe ID range (8e18 for notification_id, 2.3B for structure_id) route to <code>test_webhook_url</code> ONLY. Production binding lookup is skipped entirely — your real corp webhooks cannot receive test traffic.</li>
        <li><strong>Foreign-key cascade cleanup:</strong> deleting a test character cascade-deletes its notifications via SeAT\'s FK; deleting a test corp cascade-deletes its structures + POSes. No orphan rows.</li>
        <li><strong>SeAT update jobs ignore test data:</strong> ESI never returns our test character or notification IDs (they\'re outside CCP\'s allocation), so SeAT\'s sync jobs cannot accidentally touch our test rows.</li>
        <li><strong>Embed banner:</strong> every test embed is stamped <code>[TEST INJECTION]</code> in the content, title, and footer — anyone glancing at the test Discord sees immediately it\'s not a real attack.</li>
        <li><strong>Confirm-checkbox gating:</strong> every destructive action (generate, inject, cleanup) requires either an in-form checkbox tick or a hidden pre-set value, preventing accidental misclicks.</li>
        <li><strong>Admin-only access:</strong> all 14 diagnostic endpoints carry <code>middleware: can:structure-manager.admin</code> — non-admins get 403 even with a direct URL.</li>
    </ul>',

    'admin_diagnostics_when_to_use_title' => 'When To Use Each Path',
    'admin_diagnostics_when_to_use_list' => '<ul>
        <li><strong>Real Discord channel verification:</strong> use <em>Notification Testing > Send sample alert</em>. Posts to your actual configured corp webhooks. Good for confirming that role pings work, that the channel renders embeds correctly, and that the right team sees it.</li>
        <li><strong>Embed format / copy review:</strong> use <em>Notification Lab > 24-button inject panel</em>. Routes to your test Discord only. Good for reviewing how an attack alert vs. an OwnershipTransferred vs. a sov reinforce will look in the wild without bothering your operations channel.</li>
        <li><strong>Dual-fuel embed preview:</strong> use <em>Notification Lab > Send dual-fuel embed</em>. Synthesizes a critical Metenox scenario in memory (no DB mutation) and posts SM\'s rich analysis embed to the test webhook.</li>
        <li><strong>Pipeline troubleshooting:</strong> use <em>Notification Lab > inject + Recent panel</em>. The status transitions queued → pending → processed in real time tell you whether the polling job is running and whether dispatch is working end-to-end.</li>
    </ul>',

    'need_help' => 'Need More Help?',
    'support_message' => 'If you encounter issues not covered here, please open an issue on the GitHub repository with details about your problem, your SeAT version, and any relevant error messages from the logs.',
];
