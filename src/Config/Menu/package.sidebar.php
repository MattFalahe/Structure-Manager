<?php

return [
    'structure-manager' => [
        'name'          => 'Structure Manager',
        'label'         => 'structure-manager::menu.main_level',
        'plural'        => true,
        'icon'          => 'fas fa-industry',
        'route_segment' => 'structure-manager',
        'permission'    => 'structure-manager.view',
        'entries'       => [
            [
                'name'  => 'Upwell Structures',
                'label' => 'structure-manager::menu.fuel_status',
                'icon'  => 'fas fa-gas-pump',
                'route' => 'structure-manager.index',
                'permission' => 'structure-manager.view',
            ],
            [
                'name'  => 'Control Towers (POS)',
                'label' => 'structure-manager::menu.control_towers',
                'icon'  => 'fas fa-broadcast-tower',
                'route' => 'structure-manager.pos.index',
                'permission' => 'structure-manager.view',
            ],
            [
                'name'  => 'Fuel Reserves',
                'label' => 'structure-manager::menu.fuel_reserves',
                'icon'  => 'fas fa-warehouse',
                'route' => 'structure-manager.reserves',
                'permission' => 'structure-manager.view',
            ],
            [
                'name'  => 'Logistics Report',
                'label' => 'structure-manager::menu.logistics_report',
                'icon'  => 'fas fa-truck',
                'route' => 'structure-manager.logistics-report',
                'permission' => 'structure-manager.view',
            ],
            // Fuel Economics: shown only when Manager Core's pricing
            // infrastructure is installed (class_exists is safe during
            // ServiceProvider register() — Cache is not).
            //
            // The previous version of this gate also called
            // ManagerCoreIntegration::isEconomicsEnabled() which reads the
            // operator's mode setting through StructureManagerSettings::get
            // → Cache::remember. The sidebar config file is loaded by
            // mergeConfigFrom() in register() phase, which runs BEFORE
            // Laravel's CacheServiceProvider has registered the 'cache'
            // binding under the artisan kernel bootstrap (specifically
            // composer update's post-publish vendor:publish step). That
            // crashed composer update with 'Class \"cache\" does not exist'.
            //
            // The mode check now lives on the EconomicsController side:
            // when mode=disabled the controller renders a 'currently
            // disabled' notice instead of the page. Sidebar entry stays
            // visible so admins can find their way back to the Settings
            // tab to flip the switch.
            ...(class_exists('\\ManagerCore\\Services\\PricingService') ? [
                [
                    'name'  => 'Fuel Economics',
                    'label' => 'structure-manager::menu.economics',
                    'icon'  => 'fas fa-coins',
                    'route' => 'structure-manager.economics.index',
                    'permission' => 'structure-manager.economics',
                ],
            ] : []),
            [
                'name'  => 'Critical Alerts',
                'label' => 'structure-manager::menu.critical_alerts',
                'icon'  => 'fas fa-exclamation-triangle',
                'route' => 'structure-manager.critical-alerts',
                'permission' => 'structure-manager.view',
            ],
            [
                'name'  => 'Structure Board',
                'label' => 'structure-manager::menu.command_board',
                'icon'  => 'fas fa-chess',
                'route' => 'structure-manager.command-board.index',
                'permission' => 'structure-manager.command-board.view',
            ],
            // Notifications is no longer a top-level sidebar entry — it lives
            // inside Settings as a nav-pill (Settings > Notifications), matching
            // Mining Manager's pattern. The /notifications route still exists
            // and redirects to /settings#notifications for backward compat with
            // any external links / bookmarks.
            [
                'name'  => 'Settings',
                'label' => 'structure-manager::menu.settings',
                'icon'  => 'fas fa-cog',
                'route' => 'structure-manager.settings',
                'permission' => 'structure-manager.admin',
            ],
            // Diagnostics is intentionally NOT in the sidebar. It's a
            // troubleshooting / dev-tools page, not part of daily ops.
            // The route still exists at /structure-manager/diagnostic
            // (admin-gated via 'can:structure-manager.admin' middleware) so
            // admins can access it by typing the URL directly when they're
            // actively debugging. This matches Mining Manager's pattern —
            // the diagnostic page lives at a known URL but isn't surfaced
            // in the sidebar nav.
            [
                'name'  => 'Help & Documentation',
                'label' => 'structure-manager::menu.help',
                'icon'  => 'fas fa-question-circle',
                'route' => 'structure-manager.help',
                'permission' => 'structure-manager.view',
            ],
        ]
    ]
];
