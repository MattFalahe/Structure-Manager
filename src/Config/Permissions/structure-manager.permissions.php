<?php

return [
    'view' => [
        'label' => 'structure-manager::permissions.view_label',
        'description' => 'structure-manager::permissions.view_description',
    ],
    'admin' => [
        'label' => 'structure-manager::permissions.admin_label',
        'description' => 'structure-manager::permissions.admin_description',
    ],
    // Structure Board — v2
    'command-board.view' => [
        'label' => 'structure-manager::permissions.cb_view_label',
        'description' => 'structure-manager::permissions.cb_view_description',
    ],
    'command-board.view-sensitive' => [
        'label' => 'structure-manager::permissions.cb_view_sensitive_label',
        'description' => 'structure-manager::permissions.cb_view_sensitive_description',
    ],
    'command-board.create' => [
        'label' => 'structure-manager::permissions.cb_create_label',
        'description' => 'structure-manager::permissions.cb_create_description',
    ],
    'command-board.admin' => [
        'label' => 'structure-manager::permissions.cb_admin_label',
        'description' => 'structure-manager::permissions.cb_admin_description',
    ],

    // Fuel Economics page (Phase A). Requires Manager Core for pricing.
    // Page is hidden in the sidebar when MC is absent (the controller
    // also returns a "MC required" notice in that case).
    'economics' => [
        'label' => 'structure-manager::permissions.economics_label',
        'description' => 'structure-manager::permissions.economics_description',
    ],
];
