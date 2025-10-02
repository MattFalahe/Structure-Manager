<?php

namespace StructureManager;

use Illuminate\Support\ServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

class StructureManagerServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot()
    {
        // Load routes
        $this->loadRoutesFrom(__DIR__ . '/Http/routes.php');
        
        // Load views
        $this->loadViewsFrom(__DIR__ . '/resources/views', 'structure-manager');
        
        // Load translations
        $this->loadTranslationsFrom(__DIR__ . '/resources/lang', 'structure-manager');
        
        // Load migrations
        $this->loadMigrationsFrom(__DIR__ . '/Database/migrations');
        
        // Publish assets
        $this->publishes([
            __DIR__ . '/Config/structure-manager.config.php' => config_path('structure-manager.config.php'),
        ], 'config');
        
        // Register scheduled tasks
        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);
            
            // Track fuel consumption every hour
            $schedule->call(function () {
                app(\StructureManager\Jobs\TrackFuelConsumption::class)->handle();
            })->hourly();
        });
        
        // Register permissions
        $this->registerPermissions();
    }

    /**
     * Register the application services.
     */
    public function register()
    {
        // Merge config
        $this->mergeConfigFrom(
            __DIR__ . '/Config/structure-manager.config.php', 'structure-manager'
        );
    }
    
    /**
     * Register plugin permissions.
     */
    private function registerPermissions()
    {
        // This would integrate with Seat's permission system
        // Implementation depends on Seat version
    }
}
