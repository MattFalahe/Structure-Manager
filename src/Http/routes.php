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

    // About page - View
    Route::get('/about', [
        'as' => 'structure-manager.about',
        'uses' => 'StructureManagerController@about',
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
    
});
