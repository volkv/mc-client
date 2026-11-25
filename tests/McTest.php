<?php

namespace Volkv\McClient\Tests;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use RuntimeException;
use Volkv\McClient\McClientServiceProvider;
use Volkv\McClient\Mc;
use Volkv\McClient\Models\McCount;

class McTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [McClientServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('mc-client.production_only', false);
        $app['config']->set('mc-client.url', 'https://mc.test');
        $app['config']->set('mc-client.token', 't');
        $app['config']->set('mc-client.slug', 'demo');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    public function test_increments_add_up_and_the_first_one_creates_the_row(): void
    {
        Mc::count('Copies');
        Mc::count('Copies');
        Mc::count('Copies', 2.5);

        $this->assertSame(4.5, McCount::where('event_name', 'Copies')->sole()->count);
    }

    /**
     * The lost update of MC-01, interleaved for real: another request's increment lands right
     * after the first SQL statement count() runs — between the old code's read and its write.
     */
    public function test_an_increment_landing_in_the_middle_of_count_is_not_lost(): void
    {
        Mc::count('Generations');

        $injected = false;
        DB::listen(function ($query) use (&$injected): void {
            if (!$injected && str_contains($query->sql, 'mc_counts')) {
                $injected = true;
                DB::table('mc_counts')->where('event_name', 'Generations')->increment('count');
            }
        });

        Mc::count('Generations');

        $this->assertTrue($injected);
        $this->assertSame(3.0, McCount::where('event_name', 'Generations')->sole()->count);
    }

    public function test_set_count_still_replaces_the_value(): void
    {
        Mc::count('Sessions', 7);
        Mc::setCount('Sessions', 3);

        $this->assertSame(3.0, McCount::where('event_name', 'Sessions')->sole()->count);
    }

    public function test_a_cache_outage_does_not_escape_the_reporter_and_the_report_still_goes_out(): void
    {
        Http::fake();
        Cache::shouldReceive('add')->andThrow(new RuntimeException('redis is down'));

        Mc::reportError(new RuntimeException('the original failure'));

        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/ingest/error')
            && $request['message'] === 'the original failure');
    }

    public function test_a_repeated_error_is_still_throttled_while_the_cache_works(): void
    {
        Http::fake();
        $error = new RuntimeException('same');

        Mc::reportError($error);
        Mc::reportError($error);

        Http::assertSentCount(1);
    }
}
