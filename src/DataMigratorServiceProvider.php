<?php

namespace Carlin\DataMigrator;

use Illuminate\Support\ServiceProvider;
use Carlin\DataMigrator\Console\Commands\MigrateDataCommand;

class DataMigratorServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/Config/data_migration.php' => config_path('data_migration.php'),
            ], 'config');

            // Registering package commands.
            $this->commands([
                MigrateDataCommand::class,
            ]);
        }
    }

    /**
     * Register the application services.
     */
    public function register()
    {
        // Automatically apply the package configuration
        $this->mergeConfigFrom(__DIR__.'/Config/data_migration.php', 'data_migration');

        // Register the main class to use with the facade
        $this->app->singleton('data-migrator', function () {
            return new DataMigrator;
        });
    }
}
