<?php

namespace Volkv\McClient;

use Illuminate\Support\ServiceProvider;

class McClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/mc-client.php', 'mc-client');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        $this->publishes([__DIR__ . '/../config/mc-client.php' => config_path('mc-client.php')]);
    }
}
