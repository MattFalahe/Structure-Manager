<?php

namespace StructureManager;

use Seat\Services\AbstractSeatPlugin;
use StructureManager\Console\Commands\TrackFuelCommand;
use StructureManager\Console\Commands\CleanupHistoryCommand;
use StructureManager\Console\Commands\AnalyzeConsumptionCommand;

class StructureManagerServiceProvider extends AbstractSeatPlugin
{
    public function boot()
    {
        // Check if routes are cached before loading
        if (!$this->app->routesAreCached()) {
            include __DIR__ . '/Http/routes.php';
        }
        
        $this->loadTranslationsFrom(__DIR__ . '/resources/lang/', 'structure-manager');
        $this->loadViewsFrom(__DIR__ . '/resources/views/', 'structure-manager');
        
        $this->loadMigrationsFrom(__DIR__ . '/Database/migrations/');

        // Register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                TrackFuelCommand::class,
                CleanupHistoryCommand::class,
                AnalyzeConsumptionCommand::class,
                SetupPermissionsCommand::class,
            ]);
        }

        // Add publications
        $this->add_publications();
        
        // Add database seeders
        $this->add_database_seeders();
    }

    /**
     * Add content which must be published.
     */
    private function add_publications()
    {
        $this->publishes([
            __DIR__ . '/Config/structure-manager.config.php' => config_path('structure-manager.php'),
        ], ['config', 'seat']);
    }

    /**
     * Register database seeders
     */
    private function add_database_seeders()
    {
        $this->publishes([
            __DIR__ . '/Database/seeders/' => database_path('seeders/'),
        ], ['seeders', 'seat']);
    }

    public function register()
    {
        // Register sidebar configuration
        $this->mergeConfigFrom(__DIR__ . '/Config/Menu/package.sidebar.php', 'package.sidebar');
        
        // Register permissions
        $this->registerPermissions(__DIR__ . '/Config/Permissions/structure-manager.permissions.php', 'structure-manager');
        
        // Register config
        $this->mergeConfigFrom(__DIR__.'/Config/structure-manager.config.php', 'structure-manager');
    }

    public function getName(): string
    {
        return 'Structure Manager';
    }

    public function getPackageRepositoryUrl(): string
    {
        return 'https://github.com/MattFalahe/structure-manager';
    }

    public function getPackagistPackageName(): string
    {
        return 'structure-manager';
    }

    public function getPackagistVendorName(): string
    {
        return 'mattfalahe';
    }
}
