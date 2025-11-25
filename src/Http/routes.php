<?php
use Illuminate\Support\Facades\Route;

Route::group([
    'namespace' => '\StructureManager\Http\Controllers',
    'middleware' => ['web', 'auth', 'locale'],
    'prefix' => 'structure-manager',
], function () {
    
    Route::get('/', [
        'as' => 'structure-manager.index',
        'uses' => 'StructureManagerController@index',
        'middleware' => 'can:structure-manager.view',
    ]);
    
    Route::get('/data', [
        'as' => 'structure-manager.data',
        'uses' => 'StructureManagerController@getStructuresData',
        'middleware' => 'can:structure-manager.view',
    ]);
    
    Route::get('/structure/{id}', [
        'as' => 'structure-manager.detail',
        'uses' => 'StructureManagerController@structureDetail',
        'middleware' => 'can:structure-manager.view',
    ]);
    
    Route::get('/fuel-history/{id}', [
        'as' => 'structure-manager.fuel-history',
        'uses' => 'StructureManagerController@getFuelHistory',
        'middleware' => 'can:structure-manager.view',
    ]);
    
    Route::post('/track-fuel', [
        'as' => 'structure-manager.track-fuel',
        'uses' => 'StructureManagerController@trackFuel',
        'middleware' => 'can:structure-manager.admin',
    ]);

    // Critical Alerts - View
    Route::get('/critical-alerts', [
        'as' => 'structure-manager.critical-alerts',
        'uses' => 'FuelAlertController@criticalAlertsView',
        'middleware' => 'can:structure-manager.view',
    ]);
    
    // Critical Alerts - JSON Data
    Route::get('/critical-alerts-data', [
        'as' => 'structure-manager.critical-alerts-data',
        'uses' => 'FuelAlertController@getCriticalAlerts',
        'middleware' => 'can:structure-manager.view',
    ]);
    
    // Logistics Report - View
    Route::get('/logistics-report', [
        'as' => 'structure-manager.logistics-report',
        'uses' => 'FuelAlertController@logisticsReportView',
        'middleware' => 'can:structure-manager.view',
    ]);
    
    // Logistics Report - JSON Data
    Route::get('/logistics-data', [
        'as' => 'structure-manager.logistics-data',
        'uses' => 'FuelAlertController@getLogisticsReport',
        'middleware' => 'can:structure-manager.view',
    ]);

    Route::get('/fuel-analysis/{id}', [
        'as' => 'structure-manager.fuel-analysis',
        'uses' => 'StructureManagerController@getFuelAnalysis',
        'middleware' => 'can:structure-manager.view',
    ]);

    // Help & Documentation - View
    Route::get('/help', [
        'as' => 'structure-manager.help',
        'uses' => 'StructureManagerController@help',
        'middleware' => 'can:structure-manager.view',
    ]);

    // Fuel Reserves Management - View
    Route::get('/reserves', [
        'as' => 'structure-manager.reserves',
        'uses' => 'FuelReserveController@index',
        'middleware' => 'can:structure-manager.view',
    ]);
    
    // Fuel Reserves - JSON Data
    Route::get('/reserves-data', [
        'as' => 'structure-manager.reserves-data',
        'uses' => 'FuelReserveController@getReservesData',
        'middleware' => 'can:structure-manager.view',
    ]);
    
    // Refuel Events History
    Route::get('/refuel-history/{days?}', [
        'as' => 'structure-manager.refuel-history',
        'uses' => 'FuelReserveController@getRefuelHistory',
        'middleware' => 'can:structure-manager.view',
    ])->where('days', '[0-9]+');
    
    // Structure Reserve History
    Route::get('/structure-reserves/{id}', [
        'as' => 'structure-manager.structure-reserves',
        'uses' => 'FuelReserveController@getStructureReserveHistory',
        'middleware' => 'can:structure-manager.view',
    ]);
    
    // ============================================
    // POS (Player Owned Starbase) Management
    // ============================================
    
    // POS List View
    Route::get('/pos', [
        'as' => 'structure-manager.pos.index',
        'uses' => 'PosManagerController@index',
        'middleware' => 'can:structure-manager.view',
    ]);
    
    // POS Data - JSON
    Route::get('/pos/data', [
        'as' => 'structure-manager.pos.data',
        'uses' => 'PosManagerController@getPosesData',
        'middleware' => 'can:structure-manager.view',
    ]);
    
    // POS Detail View
    Route::get('/pos/{id}', [
        'as' => 'structure-manager.pos.detail',
        'uses' => 'PosManagerController@show',
        'middleware' => 'can:structure-manager.view',
    ]);
    
    // POS Critical Alerts - JSON
    Route::get('/pos/alerts/critical', [
        'as' => 'structure-manager.pos.alerts',
        'uses' => 'PosManagerController@getCriticalAlerts',
        'middleware' => 'can:structure-manager.view',
    ]);
    
    // ============================================
    // Settings
    // ============================================
    
    // Settings Page
    Route::get('/settings', [
        'as' => 'structure-manager.settings',
        'uses' => 'SettingsController@index',
        'middleware' => 'can:structure-manager.admin',
    ]);
    
    // Update Settings
    Route::post('/settings', [
        'as' => 'structure-manager.settings.update',
        'uses' => 'SettingsController@update',
        'middleware' => 'can:structure-manager.admin',
    ]);
    
    // Test Webhook (legacy route - supports both old single webhook and new specific webhook testing)
    Route::post('/settings/test-webhook', [
        'as' => 'structure-manager.settings.test-webhook',
        'uses' => 'SettingsController@testWebhook',
        'middleware' => 'can:structure-manager.admin',
    ]);
    
    // Reset Settings
    Route::get('/settings/reset', [
        'as' => 'structure-manager.settings.reset',
        'uses' => 'SettingsController@reset',
        'middleware' => 'can:structure-manager.admin',
    ]);
    
    // ============================================
    // Webhook Management (NEW)
    // ============================================
    
    // Add Webhook
    Route::post('/webhook/add', [
        'as' => 'structure-manager.webhook.add',
        'uses' => 'SettingsController@addWebhook',
        'middleware' => 'can:structure-manager.admin',
    ]);
    
    // Get Webhook (for editing)
    Route::get('/webhook/{id}', [
        'as' => 'structure-manager.webhook.get',
        'uses' => 'SettingsController@getWebhook',
        'middleware' => 'can:structure-manager.admin',
    ]);
    
    // Update Webhook
    Route::put('/webhook/{id}', [
        'as' => 'structure-manager.webhook.update',
        'uses' => 'SettingsController@updateWebhook',
        'middleware' => 'can:structure-manager.admin',
    ]);
    
    // Delete Webhook
    Route::delete('/webhook/{id}', [
        'as' => 'structure-manager.webhook.delete',
        'uses' => 'SettingsController@deleteWebhook',
        'middleware' => 'can:structure-manager.admin',
    ]);
    
    // Test Specific Webhook
    Route::post('/webhook/{id}/test', [
        'as' => 'structure-manager.webhook.test',
        'uses' => 'SettingsController@testWebhook',
        'middleware' => 'can:structure-manager.admin',
    ]);
    
});
