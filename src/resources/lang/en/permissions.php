<?php

return [
    // Base access
    'view_label' => 'View Structure Manager',
    'view_description' => 'View Upwell structures and POS (Control Tower) fuel status for corporations your characters belong to. Includes dashboard, critical alerts, logistics report with CSV export, fuel reserves, refuel history, and per-structure / per-POS detail pages. Also grants access to the Help page. Read-only.',

    // Admin tier
    'admin_label' => 'Admin',
    'admin_description' => 'All View permissions plus: manage thresholds and notification intervals, configure multiple Discord/Slack webhooks with corporation filters and role mentions, hangar exclusion settings, reset settings to defaults, trigger manual fuel tracking, and access the diagnostics page (type ID verification, schedule health, ESI coverage, test-data generation). Admin also sees data across all corporations in the SeAT install (not just the user\'s own).',

    // Structure Board (v2)
    'cb_view_label' => 'View Structure Board',
    'cb_view_description' => 'View the Structure Board — auto-generated fuel warnings, reinforcement timers, and lifecycle events for structures owned by corporations the user has a character in. Anchor/unanchor timers (opsec-sensitive by default) remain hidden unless the user also has the "View Sensitive Timers" permission or matches the per-timer role gate.',

    'cb_view_sensitive_label' => 'View Sensitive Timers',
    'cb_view_sensitive_description' => 'Allows viewing opsec-sensitive timers such as anchor/unanchor events that would otherwise be gated to a specific SeAT role (typically Directors). This permission does NOT bypass corporation scope — the user still only sees timers for their own corps.',

    'cb_create_label' => 'Create Board Timers',
    'cb_create_description' => 'Create manual-entry timers for hostile/defense operations. Admin picks visibility scope (own corp, all user corps, specific corp, or global broadcast) and optional role gate at creation time.',

    'cb_admin_label' => 'Administer Board',
    'cb_admin_description' => 'Manage Structure Board defaults (default opsec role for anchor/unanchor timers, retention settings, default view window), bulk-dismiss elapsed timers, edit or delete any timer regardless of creator, and bypass the corporation visibility filter to see events for every corp in the SeAT instance.',

    // Fuel Economics
    'economics_label' => 'View Fuel Economics',
    'economics_description' => 'View the Fuel Economics page (under Logistics Report). Shows weekly / monthly / quarterly / yearly fuel ISK cost across the user\'s corporations, with breakdowns per system, per structure, and per fuel type. Requires Manager Core to be installed (uses MC pricing). Page is hidden when MC is absent. Users without admin see only their own corporations; admins see all corps in the SeAT instance.',
];
