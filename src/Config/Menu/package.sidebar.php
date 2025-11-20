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
            [
                'name'  => 'Critical Alerts',
                'label' => 'structure-manager::menu.critical_alerts',
                'icon'  => 'fas fa-exclamation-triangle',
                'route' => 'structure-manager.critical-alerts',
                'permission' => 'structure-manager.view',
            ],
            [
                'name'  => 'Settings',
                'label' => 'structure-manager::menu.settings',
                'icon'  => 'fas fa-cog',
                'route' => 'structure-manager.settings',
                'permission' => 'structure-manager.admin',
            ],
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
