<?php

namespace StructureManager;

use Seat\Services\AbstractSeatPlugin;
use StructureManager\Console\Commands\TrackFuelCommand;
use StructureManager\Console\Commands\CleanupHistoryCommand;
use StructureManager\Console\Commands\CleanupPhantomWithdrawalsCommand;
use StructureManager\Console\Commands\AnalyzeConsumptionCommand;
use StructureManager\Console\Commands\CreateTestMetenoxCommand;
use StructureManager\Console\Commands\TrackPosesFuelCommand;
use StructureManager\Console\Commands\AnalyzePosConsumptionCommand;
use StructureManager\Console\Commands\NotifyPosFuelCommand;
use StructureManager\Console\Commands\CreateTestPoses;
use StructureManager\Console\Commands\SimulateFastConsumption;
use StructureManager\Console\Commands\NotifyUpwellFuelCommand;
use StructureManager\Console\Commands\ProcessStructureNotificationsCommand;
use StructureManager\Console\Commands\TrackStructurePresenceCommand;
use StructureManager\Console\Commands\PublishTimerScheduleEventsCommand;
use StructureManager\Console\Commands\PruneStructureBoardTimersCommand;
use StructureManager\Console\Commands\CleanupTestDataCommand;
use StructureManager\Console\Commands\CreateTestUpwellStructuresCommand;
use StructureManager\Console\Commands\InjectTestNotificationCommand;
use StructureManager\Console\Commands\BackfillBoardTimersCommand;
use StructureManager\Database\Seeders\ScheduleSeeder;
use StructureManager\Integrations\ManagerCoreIntegration;
use StructureManager\Models\Timer;
use StructureManager\Observers\TimerObserver;

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

        // Register commands.
        //
        // Defense-in-depth registration: TWO independent paths so commands
        // are reachable from both `php artisan` (CLI) and `Artisan::call(...)`
        // invoked from a web controller (e.g. the diagnostic page's
        // "Run Upwell notification check" button).
        //
        //   Path 1: `$this->commands()` — Laravel's standard registration via
        //           `Artisan::starting()` callback. Fires when the console
        //           Application boots.
        //
        //   Path 2: `app->resolving(Kernel)` — registers commands directly
        //           on the console Kernel as it's being resolved by the
        //           container. Catches the case where Path 1's static
        //           `Artisan::starting` callbacks don't fire because the
        //           Application was already constructed earlier in the
        //           request lifecycle.
        //
        // Removing the `runningInConsole()` guard alone is necessary but not
        // sufficient on some Laravel versions / cache configurations — Path 2
        // is the bulletproof fallback.
        $smCommands = [
            TrackFuelCommand::class,
            CleanupHistoryCommand::class,
            AnalyzeConsumptionCommand::class,
            CreateTestMetenoxCommand::class,
            TrackPosesFuelCommand::class,
            AnalyzePosConsumptionCommand::class,
            NotifyPosFuelCommand::class,
            CreateTestPoses::class,
            SimulateFastConsumption::class,
            NotifyUpwellFuelCommand::class,
            ProcessStructureNotificationsCommand::class,
            TrackStructurePresenceCommand::class,
            PublishTimerScheduleEventsCommand::class,
            PruneStructureBoardTimersCommand::class,
            CreateTestUpwellStructuresCommand::class,
            InjectTestNotificationCommand::class,
            CleanupTestDataCommand::class,
            BackfillBoardTimersCommand::class,
            CleanupPhantomWithdrawalsCommand::class,
        ];

        // Path 1: standard registration (runs during console Application bootstrap)
        $this->commands($smCommands);

        // Path 2: resolve-time registration on the console Kernel. Triggered
        // whenever `Artisan::call(...)` is invoked from web — even if Path 1's
        // bootstrap callbacks already fired and got skipped.
        $this->app->resolving(\Illuminate\Contracts\Console\Kernel::class, function ($kernel) use ($smCommands) {
            foreach ($smCommands as $cmd) {
                try {
                    $kernel->registerCommand($this->app->make($cmd));
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning(
                        'StructureManager: failed to register command ' . $cmd . ': ' . $e->getMessage()
                    );
                }
            }
        });

        // Manager Core integration: register handlers DIRECTLY (not via
        // app()->booted()). Reason: in Horizon worker contexts, booted
        // callbacks can fire AFTER the worker has already begun processing
        // a job — which means MC's PollEsiNotifications/SweepSeatNotifications
        // see an empty notification registry and exit in 0.00s. Verified in
        // the 2026-05-11 debug session: log entries showed the poll job
        // starting BEFORE the SM "Registered 23 notification types" line in
        // the same second.
        //
        // Direct call from boot() runs as part of Laravel's provider boot
        // phase — guaranteed to complete before any job handle() runs in
        // the same process, because Laravel can't process queue jobs until
        // every provider has booted.
        //
        // The `bound('cache')` guard handles the only context where this
        // was unsafe: composer's `vendor:publish` step on plugin install,
        // where the artisan bootstrap can hit our boot before the
        // CacheServiceProvider has fully registered (chain that fails:
        // economicsPricingMode() → StructureManagerSettings::get() →
        // Cache::remember()). When Cache is not yet bound, skip; the next
        // process boot will complete registration normally.
        //
        // Each integration is wrapped in try/catch so a failure in any
        // one never takes down the rest of the app boot. Failures log at
        // warning level so admins can see them.
        if ($this->app->bound('cache')) {
            try {
                ManagerCoreIntegration::registerStructureEventHandler();
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning(
                    '[Structure Manager] ESI handler registration failed at boot: ' . $e->getMessage()
                );
            }

            try {
                if (ManagerCoreIntegration::isEconomicsEnabled()) {
                    ManagerCoreIntegration::registerPricingPreference();
                    ManagerCoreIntegration::subscribePricingTypes();
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning(
                    '[Structure Manager] Economics integration boot failed: ' . $e->getMessage()
                );
            }

            // Pre-timer reminder subscriber — subscribes our handler to MC's
            // scheduled timer.upcoming_24h/6h/1h events so reminders fire
            // 24h/6h/1h before a structure timer expires. Independent of
            // fast-poll detection mode (operators can use SeAT native + still
            // want reminders) so we don't gate on isFastPollEnabled().
            // No-op if MC isn't installed (the integration method checks).
            try {
                ManagerCoreIntegration::registerPreTimerReminderSubscriber();
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning(
                    '[Structure Manager] Pre-timer reminder subscriber registration failed: ' . $e->getMessage()
                );
            }
        }

        // Family B (cross-plugin timer.* events): observer fires
        // structure_manager.timer.created / .updated / .dismissed on Timer
        // row transitions. No-op when MC is absent (publisher checks).
        Timer::observe(TimerObserver::class);

        // Add publications
        $this->add_publications();
    }

    /**
     * Add content which must be published.
     */
    private function add_publications()
    {
        // Publish config
        $this->publishes([
            __DIR__ . '/Config/structure-manager.config.php' => config_path('structure-manager.php'),
        ], ['config', 'seat']);
        
        // Publish assets
        $this->publishes([
            __DIR__ . '/resources/assets' => public_path('vendor/structure-manager'),
        ], ['public', 'seat']);
    }

    /**
     * Register database seeders
     */
    private function add_database_seeders()
    {
        $this->registerDatabaseSeeders([
            ScheduleSeeder::class
        ]);
    }

    public function register()
    {
        // Register sidebar configuration
        $this->mergeConfigFrom(__DIR__ . '/Config/Menu/package.sidebar.php', 'package.sidebar');
        
        // Register permissions
        $this->registerPermissions(__DIR__ . '/Config/Permissions/structure-manager.permissions.php', 'structure-manager');
        
        // Register config
        $this->mergeConfigFrom(__DIR__.'/Config/structure-manager.config.php', 'structure-manager');

        // Add database seeders
        $this->add_database_seeders();
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
