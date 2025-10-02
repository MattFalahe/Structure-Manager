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
});
