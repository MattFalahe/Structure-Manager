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
    'feature_reserves_desc' => 'Monitor staged fuel in corporation hangars with selective hangar tracking. Choose which hangars to include in reserves calculations, track reserve movements, manage fuel distribution, and exclude hangars used for market trading or logistics. Supports both Upwell Structures and legacy POS reserves including strontium and charters.',
    'feature_logistics_title' => 'Logistics Planning',
    'feature_logistics_desc' => 'Generate comprehensive fuel requirements reports by system with hauling calculations and export capabilities.',
    'feature_metenox_title' => 'Metenox Moon Drill Support',
    'feature_metenox_desc' => 'Full dual-fuel tracking for Metenox drills requiring both fuel blocks and magmatic gas.',
    'feature_automated_title' => 'Automated Tracking',
    'feature_automated_desc' => 'Upwell structures tracked hourly with 30-minute analysis intervals. POSes tracked every 10 minutes for real-time monitoring. Automatic historical data retention with 90-day POS cleanup.',
    'feature_pos_title' => 'Legacy Player Owned Starbases (POS towers) Support',
    'feature_pos_desc' => 'Comprehensive POS monitoring with fuel blocks, strontium clathrates, and starbase charter tracking. Automatically detects security space, identifies the limiting factor, tracks reserves in corporate hangars, and calculates reinforcement timers. Full support for faction and officer tower fuel efficiency bonuses.',

    // Quick Links
    'quick_links_title' => 'Quick Links',
    'view_dashboard' => 'View Dashboard',
    'view_alerts' => 'View Critical Alerts',
    'view_logistics' => 'View Logistics Report',

    // Getting Started
    'getting_started_title' => 'Getting Started',
    'getting_started_desc' => 'Follow these steps to install and configure Structure Manager for your corporation.',
    'installation_title' => 'Installation',
    'install_step1_title' => 'Install via Composer',
    'install_step1_desc' => 'Run the following command in your SeAT directory:',
    'install_step2_title' => 'Run Migrations',
    'install_step2_desc' => 'After installation, run the database migrations:',
    'install_step3_title' => 'Wait for Data Sync',
    'install_step3_desc' => 'The plugin will automatically start tracking fuel levels on the next scheduled run. Wait for SeAT to sync your corporation structures data.',
    
    'first_time_setup_title' => 'First-Time Setup',
    'setup_step1_title' => 'Verify Permissions',
    'setup_step1_desc' => 'Make sure your user has the "structure-manager.view" permission in SeAT\'s permission system.',
    'setup_step2_title' => 'Check Your Dashboard',
    'setup_step2_desc' => 'Navigate to Structure Manager in the sidebar. You should see your corporation structures with fuel levels.',
    'setup_step3_title' => 'Configure Alerts',
    'setup_step3_desc' => 'Visit the Critical Alerts page to see which structures need immediate attention. Structures with less than 14 days of fuel are highlighted.',
    
    'success_tip' => 'Success Tip',
    'success_desc' => 'Upwell structures are tracked hourly with consumption analysis every 30 minutes. POSes are tracked every 10 minutes for real-time monitoring. Historical data is retained for 6 months for Upwell structures and 90 days for POSes.',

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
        <li><strong>Corporation hangar tracking:</strong> Monitors fuel stored in CorpSAG hangars</li>
        <li><strong>Structure-level reserves:</strong> See which structures have staged fuel ready</li>
        <li><strong>Division tracking:</strong> Identifies which hangar divisions contain fuel</li>
        <li><strong>Custom division names:</strong> Shows your corporation\'s custom hangar division names</li>
        <li><strong>Reserve history:</strong> 3 months of reserve movement tracking</li>
        <li><strong>Purple badges:</strong> Special indicators for magmatic gas reserves (Metenox support)</li>
        <li><strong>Selective Tracking:</strong> Configure which hangars to exclude from tracking (see Settings)</li>
        <p><strong>Note:</strong> Reserves tracking respects your hangar exclusion settings. Excluded hangars will not appear in reserves calculations.</p>
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
    'notifications_intro' => 'Configure automated webhook notifications to receive real-time alerts for critical POS fuel levels directly in your Discord or Slack channels.',
    
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
    'critical_example' => '<strong>Critical Alert Example:</strong><br><br>🚨 <strong>CRITICAL POS FUEL ALERT</strong><br><br><strong>Tower:</strong> Death Star (Large Amarr Control Tower)<br><strong>System:</strong> 3-FKCZ (Null-Sec)<br><strong>Corporation:</strong> Test Corp<br><br><strong>Fuel Blocks:</strong> 245 remaining (4.3 days) [LIMITING FACTOR]<br><strong>Strontium:</strong> 15,432 remaining<br><br><strong>Status:</strong> CRITICAL - Refuel immediately!',
    
    'warning_example' => '<strong>Warning Alert Example:</strong><br><br>⚠️ <strong>POS FUEL WARNING</strong><br><br><strong>Tower:</strong> Moon Mining Base (Medium Caldari Control Tower)<br><strong>System:</strong> J123456 (W-Space)<br><br><strong>Fuel Blocks:</strong> 5,840 remaining (12.2 days)<br><strong>Strontium:</strong> 8,200 remaining<br><br><strong>Status:</strong> LOW - Schedule refuel operation',

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
    'multiple_webhooks_intro' => 'Structure Manager supports up to 10 concurrent webhooks with per-webhook corporation filtering and role mentions. This is ideal for hosting multiple corporations with separate alert channels.',
    'multiple_webhooks_features' => '<strong>Features:</strong>
        <ul>
            <li><strong>Up to 10 webhooks:</strong> Configure multiple Discord or Slack webhook URLs simultaneously</li>
            <li><strong>Corporation filtering:</strong> Each webhook can target specific corporations or "all corporations"</li>
            <li><strong>Independent configuration:</strong> Each webhook has its own enabled/disabled state</li>
            <li><strong>Per-webhook role mentions:</strong> Different Discord role mentions for each webhook</li>
            <li><strong>Optional descriptions:</strong> Add notes to identify webhook purpose</li>
            <li><strong>Individual testing:</strong> Test each webhook separately to verify configuration</li>
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
    'multiple_webhooks_configuration' => '<strong>Configuration:</strong>
        <ol>
            <li>Navigate to Settings → POS Notifications → Webhook Configuration</li>
            <li>Click "Add Webhook" button (max 10 webhooks)</li>
            <li>Enter webhook URL (Discord or Slack)</li>
            <li>Select corporation filter:
                <ul>
                    <li>"All Corporations" - Receives alerts for all POSes regardless of corporation</li>
                    <li>Specific Corporation - Receives alerts only for that corporation\'s POSes</li>
                </ul>
            </li>
            <li>Configure Discord role mention (optional):
                <ul>
                    <li>Format: <code>&lt;@&amp;ROLE_ID&gt;</code> or just the role ID number</li>
                    <li>Only triggers for critical and final alerts</li>
                    <li>Leave empty for no mentions</li>
                </ul>
            </li>
            <li>Add description (optional) to identify webhook purpose</li>
            <li>Test webhook with "Test Webhook" button</li>
            <li>Enable webhook to start receiving notifications</li>
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
    
    'threshold_settings' => 'Alert Threshold Settings',
    'threshold_desc' => 'Customize when alerts are triggered for each resource type.',
    
    'fuel_thresholds' => 'Fuel & Charter Thresholds',
    'fuel_critical_setting' => '<strong>Critical:</strong> Alert when fuel/charters drop below X days (default: 7 days)',
    'fuel_warning_setting' => '<strong>Warning:</strong> Alert when fuel/charters drop below X days (default: 14 days)',
    
    'strontium_thresholds' => 'Strontium Thresholds',
    'strontium_critical_setting' => '<strong>Critical:</strong> Alert when strontium drops below X hours (default: 6 hours)',
    'strontium_warning_setting' => '<strong>Warning:</strong> Alert when strontium drops below X hours (default: 12 hours)',
    
    'test_webhook_button' => 'Test Webhook',
    'test_webhook_desc' => 'Send a test notification to verify your webhook configuration is working correctly.',
    
    'reserves_tracking_settings' => 'Reserves Tracking Settings',
    'reserves_tracking_desc' => 'Configure which corporate hangars are included in fuel reserves calculations for both Upwell Structures and POSes.',
    
    'hangar_exclusion_title' => 'Hangar Exclusion',
    'hangar_exclusion_desc' => 'Select which corporate hangars (1-7) should be EXCLUDED from fuel reserves tracking. This is useful for excluding hangars used for:',
    'hangar_exclusion_uses' => '<ul>
        <li><strong>Market Trading:</strong> Hangars containing fuel destined for market sales</li>
        <li><strong>Logistics Staging:</strong> Fuel being held for other operations</li>
        <li><strong>Personal Storage:</strong> Hangars not intended for structure refueling</li>
        <li><strong>Contract Fulfillment:</strong> Fuel blocks reserved for external contracts</li>
    </ul>',
    'hangar_exclusion_note' => '<strong>Note:</strong> Checked hangars are tracked, unchecked hangars are excluded. Fuel in excluded hangars will not appear in reserves reports, logistics calculations, or the reserves page for ANY asset type (both Upwell Structures and POSes).',
    
    'settings_tips' => 'Configuration Tips',
    'settings_tips_list' => '<ul>
        <li><strong>Start Conservative:</strong> Begin with longer intervals (6+ hours) to avoid notification fatigue</li>
        <li><strong>Adjust Based on Activity:</strong> Active POS locations may need more frequent checks</li>
        <li><strong>Test First:</strong> Always test your webhook before enabling production notifications</li>
        <li><strong>Role Mentions:</strong> Use role mentions sparingly for truly critical alerts only</li>
        <li><strong>Monitor Cooldowns:</strong> If you\'re not receiving notifications, check if cooldown periods are too long</li>
        <li><strong>Hangar Exclusions:</strong> Regularly review excluded hangars to ensure they match your current operations</li>
    </ul>',
    
    'upwell_notifications_note' => 'Note on Upwell Structures',
    'upwell_notifications_desc' => 'Webhook notifications are currently available for POSes only. Upwell structure notifications (Citadels, Refineries, Engineering Complexes) are planned for a future release. Currently, Upwell structures use the dashboard critical alerts widget and in-app alerts page.',

    // Pages Guide
    'pages_intro' => 'Structure Manager consists of several pages, each designed for specific aspects of fuel management.',

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

    'detail_page_title' => 'Structure Detail Page',
    'detail_page_desc' => '<ul>
        <li><strong>Comprehensive fuel dashboard:</strong> Complete overview of one structure</li>
        <li><strong>Consumption breakdown:</strong> Hourly, daily, weekly, monthly rates</li>
        <li><strong>Service tracking:</strong> Lists active services and their fuel impact</li>
        <li><strong>Historical charts:</strong> Visual graphs of fuel consumption over time</li>
        <li><strong>Refuel event log:</strong> Timeline of when fuel was added</li>
        <li><strong>Anomaly detection:</strong> Alerts for unusual consumption patterns</li>
        <li><strong>Reserve history:</strong> Staged fuel movements for this structure</li>
        <li><strong>Metenox dual-display:</strong> Separate charts and stats for fuel blocks and gas</li>
        <li><strong>Control Tower dual-display:</strong> Seperate charts and stats for fuel blocks and charters (if required), seperate status for Strontium Clathrates</li>
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

    // FAQ
    'frequently_asked' => 'Frequently Asked Questions',
    
    'faq_q1' => 'Q1: Why are my fuel consumption numbers different from what I calculated?',
    'faq_a1' => 'Make sure you\'re accounting for all online services and using correct fuel mechanics. Remember: (1) Only online services consume fuel, (2) Multi-service modules count as one module, (3) Moon drills get NO bonuses, (4) Refinery bonuses ONLY apply to reprocessing and reactions. Check the Fuel Mechanics section for detailed examples.',

    'faq_q2' => 'Q2: My structures aren\'t showing up in the dashboard. What\'s wrong?',
    'faq_a2' => 'First, verify that SeAT has synced your corporation\'s structure data. Check Corporation > Structures in SeAT to confirm structures are visible there. If structures exist in SeAT but not in Structure Manager, wait for the next hourly tracking cycle or manually run <code>php artisan structure-manager:track-fuel</code>.',

    'faq_q3' => 'Q3: Can I track fuel for multiple corporations?',
    'faq_a3' => 'Yes! Structure Manager automatically tracks all corporations that your SeAT installation manages. The dashboard includes filters to view specific corporations, and all pages support multi-corporation data.',

    'faq_q4' => 'Q4: How often does the plugin check fuel levels?',
    'faq_a4' => 'Upwell structures: Fuel levels tracked hourly, consumption analysis runs every 30 minutes. POSes: Tracked every 10 minutes for real-time monitoring, notifications checked every 10 minutes. Reserve tracking occurs hourly for all structure types. These schedules are automatic and require no configuration.',

    'faq_q5' => 'Q5: What happens if I refuel a structure?',
    'faq_a5' => 'The plugin automatically detects refuel events by analyzing fuel bay history. Significant increases in fuel levels are logged as refuel events, which appear in the structure detail page and can help track refueling operations.',

    'faq_q6' => 'Q6: Does the plugin work with offline structures?',
    'faq_a6' => 'The plugin tracks all structures, but offline structures don\'t consume fuel so they won\'t show fuel consumption data. Once a structure comes online and services activate, fuel tracking begins automatically.',

    'faq_q7' => 'Q7: Can I see historical fuel data?',
    'faq_a7' => 'Yes! The plugin retains 6 months of fuel bay history for Upwell structures and 90 days for POSes (updated more frequently). Reserve history is retained for 3 months. Visit any structure\'s detail page to see consumption charts, refuel events, and historical trends.',

    'faq_q8' => 'Q8: How does reserve tracking work?',
    'faq_a8' => 'The plugin scans all structure hangars for fuel blocks (Type ID: 4312) and magmatic gas (Type ID: 58903) in CorpSAG divisions. It tracks quantities and locations, identifying which structures have staged fuel ready for use.',

    'faq_q9' => 'Q9: What is the "limiting factor" on Metenox drills?',
    'faq_a9' => 'The limiting factor is whichever resource (fuel blocks or magmatic gas) will run out first. Since both are required for operation, the plugin highlights which resource needs priority hauling with a purple "LIMITING" badge.',

    'faq_q10' => 'Q10: Can I export logistics data?',
    'faq_a10' => 'Yes! The Logistics Report page includes a CSV export button. This exports fuel requirements by system, perfect for sharing with your logistics team or importing into other tools.',

    'faq_q11' => 'Q11: How accurate are fuel consumption calculations?',
    'faq_a11' => 'Very accurate. The plugin uses official EVE Online fuel mechanics and, when possible, calculates consumption from actual fuel bay data rather than service counts. It correctly handles multi-service modules, refinery bonuses, moon drills, and Metenox dual-fuel requirements.',

    'faq_q12' => 'Q12: Does the plugin send Discord notifications?',
    'faq_a12' => 'Yes! Discord/Slack webhook notifications are available for POS fuel alerts. Configure webhook URL and thresholds in Settings. Notifications use status-based alerting (good→warning→critical transitions) with optional critical stage reminders. Final alerts sent 1 hour before POS goes offline. Avatar uses your webhook configuration. Rich embeds show tower location, type, and resource levels. Upwell structure notifications are planned for a future release.',

    'faq_q13' => 'Q13: How does POS charter tracking work?',
    'faq_a13' => 'Starbase charters are automatically tracked for POSes in high-security space. The plugin detects system security level and monitors charter consumption (1/hour) alongside fuel blocks. POSes in low-sec, null-sec (both sovereign and NPC), or wormhole space don\'t require charters and won\'t show charter tracking.',

    'faq_q14' => 'Q14: Why are my POS fuel and strontium alerts separate?',
    'faq_a14' => 'Fuel blocks and strontium serve different purposes and have different urgency levels. Fuel is for daily operations (critical at 7 days), while strontium is defensive (critical at 6 hours). Separate status tracking with optional different reminder intervals (6 hours for fuel, 2 hours for strontium during critical stage) ensure appropriate notification frequency for each resource type.',

    'faq_q15' => 'Q15: What happens if a POS runs low on multiple resources?',
    'faq_a15' => 'The plugin identifies the "limiting factor" - whichever resource will run out first. This appears with a [LIMITING FACTOR] badge in alerts and on the POS detail page. For example, if fuel lasts 20 days but charters only last 4 days, charters are marked as limiting.',

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
    'issue3_desc' => 'If fuel reserves aren\'t showing:',
    'issue3_solutions' => '<ul>
        <li><strong>Check hangar divisions:</strong> Reserves must be in CorpSAG hangar divisions (not personal hangars).</li>
        <li><strong>Verify item types:</strong> Plugin tracks Type ID 4312 (Fuel Blocks) and 58903 (Magmatic Gas) only.</li>
        <li><strong>Asset sync required:</strong> SeAT must have synced corporation asset data.</li>
        <li><strong>Run reserve tracking:</strong> Execute <code>php artisan structure-manager:track-reserves</code> manually.</li>
        <li><strong>Wait for next cycle:</strong> Reserve tracking occurs hourly.</li>
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
    </ul>',

    'need_help' => 'Need More Help?',
    'support_message' => 'If you encounter issues not covered here, please open an issue on the GitHub repository with details about your problem, your SeAT version, and any relevant error messages from the logs.',
];
