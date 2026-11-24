<?php

namespace Volkv\McClient\Tests;

use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;
use Volkv\McClient\McClientServiceProvider;

abstract class TestCase extends Orchestra
{
    protected string $logFile;

    protected function getPackageProviders($app): array
    {
        return [McClientServiceProvider::class];
    }

    /** DB_CONNECTION / DB_HOST / … env vars switch the suite to pgsql or mysql; default is in-memory sqlite. */
    protected function defineEnvironment($app): void
    {
        $this->logFile = sys_get_temp_dir() . '/mc-client-test-' . getmypid() . '.log';
        @unlink($this->logFile);

        $app['config']->set('logging.default', 'mc_test');
        $app['config']->set('logging.channels.mc_test', ['driver' => 'single', 'path' => $this->logFile]);

        $app['config']->set('mc-client.production_only', false);
        $app['config']->set('mc-client.url', 'https://mc.test');
        $app['config']->set('mc-client.token', 'token');
        $app['config']->set('mc-client.slug', 'project');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    protected function loggedText(): string
    {
        return is_file($this->logFile) ? (string) file_get_contents($this->logFile) : '';
    }
}
