<?php

return [
    // Base access
    'view_label' => 'View Structure Manager',
    'view_description' => 'View Upwell structures and POS (Control Tower) fuel status for corporations your characters belong to. Includes dashboard, critical alerts, logistics report with CSV export, fuel reserves, refuel history, and per-structure / per-POS detail pages. Also grants access to the Help page. Read-only.',

    // Admin tier
    'admin_label' => 'Admin',
    'admin_description' => 'All View permissions plus: manage thresholds and notification intervals, configure multiple Discord/Slack webhooks with corporation filters and role mentions, hangar exclusion settings, reset settings to defaults, trigger manual fuel tracking, and access the diagnostics page (type ID verification, schedule health, ESI coverage, test-data generation). Admin also sees data across all corporations in the SeAT install (not just the user\'s own).',
];
