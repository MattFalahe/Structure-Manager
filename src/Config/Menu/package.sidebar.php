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
                'name'  => 'Fuel Status',
                'label' => 'structure-manager::menu.fuel_status',
                'icon'  => 'fas fa-gas-pump',
                'route' => 'structure-manager.index',
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
                'name'  => 'About',
                'label' => 'structure-manager::menu.about',
                'icon'  => 'fas fa-info-circle',
                'route' => 'structure-manager.about',
                'permission' => 'structure-manager.view',
            ],
        ]
    ]
];
