<?php

return [
    'name' => 'Structure Manager',
    'version' => '1.0.7',
    'author' => 'Matt Falahe',
    'description' => 'Monitor and track corporation structure fuel levels and consumption',
    
    // Permission definitions
    'permissions' => [
        'structure-manager.view' => 'View Structure Manager',
        'structure-manager.admin' => 'Administer Structure Manager',
    ],
    
    // Menu configuration
    'menu' => [
        'main' => [
            'name' => 'Structure Manager',
            'icon' => 'fas fa-industry',
            'route' => 'structure-manager.index',
            'permission' => 'structure-manager.view',
        ],
    ],
    
    // Fuel warning thresholds (in days)
    'fuel_thresholds' => [
        'critical' => 7,    // Red alert
        'warning' => 14,    // Yellow warning
        'normal' => 30,     // Green normal
    ],
];
