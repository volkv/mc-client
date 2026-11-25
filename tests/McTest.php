<?php

namespace Volkv\McClient\Tests;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use RuntimeException;
use Volkv\McClient\Jobs\PushCounts;
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

    public function test_a_cache_and_a_logger_down_together_still_do_not_escape(): void
    {
        Http::fake();
        Cache::shouldReceive('add')->andThrow(new RuntimeException('redis is down'));
        \Illuminate\Support\Facades\Log::shouldReceive('warning')->andThrow(new RuntimeException('log disk full'));

        Mc::reportError(new RuntimeException('the original failure'));

        Http::assertSentCount(1);
    }

    public function test_a_failed_increment_leaves_no_zero_row(): void
    {
        // The increment's UPDATE fails once; the row the INSERT made must go with it.
        $failed = false;
        DB::listen(function ($query) use (&$failed): void {
            if (!$failed && str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'mc_counts')) {
                $failed = true;
                throw new RuntimeException('update failed');
            }
        });

        Mc::count('Copies');

        $this->assertSame(0, McCount::count());
    }

    public function test_a_repeated_error_is_still_throttled_while_the_cache_works(): void
    {
        Http::fake();
        $error = new RuntimeException('same');

        Mc::reportError($error);
        Mc::reportError($error);

        Http::assertSentCount(1);
    }

    /** @return array{0: \Closure, 1: \Closure} a hub switch and the dates of each accepted request */
    private function fakeHub(bool &$up, array &$sent, ?\Closure $rejects = null): void
    {
        Http::fake(function ($request) use (&$up, &$sent, $rejects) {
            if (! $up || ($rejects && $rejects($request))) {
                return Http::response('no', $up ? 422 : 503);
            }
            $sent[] = array_column($request['counts'], 'date');

            return Http::response(['ok' => true]);
        });
    }

    private function dailyRow(string $day, int $count = 1): void
    {
        $this->travelTo($day.' 12:00:00 Europe/Moscow');
        Mc::setCount('Copies', $count, now());
    }

    /**
     * A hub outage longer than the trailing window: the days between the last
     * complete push and the window were never sent again once the hub was back.
     */
    public function test_after_an_outage_the_push_reaches_back_to_the_last_complete_push(): void
    {
        $up = true;
        $sent = [];
        $this->fakeHub($up, $sent);

        $this->dailyRow('2026-11-01');
        (new PushCounts)->handle();                       // backfill; marker = 01.11

        $up = false;
        foreach (['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06'] as $day) {
            $this->dailyRow($day);
            (new PushCounts)->handle();                   // fails; marker stays 01.11
        }

        $up = true;
        $sent = [];
        $this->travelTo('2026-11-10 12:00:00 Europe/Moscow');
        (new PushCounts)->handle();
        $this->assertSame(['2026-11-01', '2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06'], array_merge(...$sent));

        // Caught up: the next push is the ordinary trailing window again.
        foreach (['2026-11-07', '2026-11-08', '2026-11-11'] as $day) {
            $this->dailyRow($day);
        }
        $sent = [];
        (new PushCounts)->handle();                       // 11.11: tail starts 08.11
        $this->assertSame(['2026-11-08', '2026-11-11'], array_merge(...$sent));
    }

    /** A chunk the hub rejects for good must not grow every later push forever. */
    public function test_the_catch_up_is_capped(): void
    {
        config(['mc-client.push_max_catchup_days' => 5]);
        $up = true;
        $sent = [];
        $this->fakeHub($up, $sent, fn ($request) => in_array('2026-11-02', array_column($request['counts'], 'date'), true));

        $this->dailyRow('2026-11-01');
        (new PushCounts)->handle();                       // marker = 01.11
        $this->dailyRow('2026-11-02');                    // the poisoned day

        $widest = 0;
        foreach (range(3, 20) as $d) {
            $this->dailyRow(sprintf('2026-11-%02d', $d));
            $sent = [];
            (new PushCounts)->handle();
            $widest = max($widest, count(array_unique(array_merge([], ...$sent))));
        }

        // The window never grew past the cap (5 days + today), the poisoned day
        // fell out of it, and the push completes again with the ordinary tail.
        $this->assertLessThanOrEqual(6, $widest);
        $this->assertSame(['2026-11-17', '2026-11-18', '2026-11-19', '2026-11-20'], array_merge(...$sent));
        $this->assertSame('2026-11-20', Cache::get('mc-client:business-backfilled'));
    }

    public function test_an_unreadable_marker_resends_the_tail_and_does_not_throw(): void
    {
        $up = true;
        $sent = [];
        $this->fakeHub($up, $sent);
        $this->dailyRow('2026-11-01');
        $this->dailyRow('2026-11-10');
        Cache::forever('mc-client:business-backfilled', 'not-a-date');

        (new PushCounts)->handle();

        $this->assertSame(['2026-11-10'], array_merge(...$sent));
    }

    /** 0.2.1 wrote the marker as an ISO time; it is read as its counter day. */
    public function test_an_iso_marker_from_the_previous_version_is_read_as_a_counter_day(): void
    {
        $up = true;
        $sent = [];
        $this->fakeHub($up, $sent);
        foreach (['2026-11-01', '2026-11-02', '2026-11-10'] as $day) {
            $this->dailyRow($day);
        }
        // 01.11 23:30 in Moscow, written by an app running in UTC.
        Cache::forever('mc-client:business-backfilled', '2026-11-01T20:30:00+00:00');

        (new PushCounts)->handle();

        $this->assertSame(['2026-11-01', '2026-11-02', '2026-11-10'], array_merge(...$sent));
    }

    /**
     * The marker is the counter day the push read, whatever the app's zone: an
     * app east of the counters' zone used to stamp tomorrow while today's rows
     * could still grow.
     */
    public function test_the_marker_is_the_counter_day_of_the_read_not_the_app_day(): void
    {
        config(['app.timezone' => 'Asia/Tokyo']);
        date_default_timezone_set('Asia/Tokyo');
        $up = true;
        $sent = [];
        $this->fakeHub($up, $sent);

        $this->travelTo('2026-11-01 20:30:00 Europe/Moscow');   // 02.11 02:30 in Tokyo
        Mc::count('Copies');
        (new PushCounts)->handle();

        $this->assertSame('2026-11-01', Cache::get('mc-client:business-backfilled'));
        date_default_timezone_set('UTC');
    }

    /** One chunk refused: the marker stays, and the next run sends every chunk again. */
    public function test_a_partly_failed_push_keeps_the_marker(): void
    {
        $this->travelTo('2026-11-10 12:00:00 Europe/Moscow');
        $rows = [];
        foreach (range(1, 10001) as $i) {
            $rows[] = ['date' => '2026-11-10', 'event_name' => 'E'.$i, 'count' => 1];
        }
        foreach (array_chunk($rows, 1000) as $part) {
            DB::table('mc_counts')->insert($part);
        }
        Cache::forever('mc-client:business-backfilled', '2026-11-09');

        $calls = 0;
        Http::fake(function () use (&$calls) {
            return ++$calls === 2 ? Http::response('no', 503) : Http::response(['ok' => true]);
        });
        (new PushCounts)->handle();

        $this->assertSame(3, $calls);
        $this->assertSame('2026-11-09', Cache::get('mc-client:business-backfilled'));

        (new PushCounts)->handle();
        $this->assertSame(6, $calls);
        $this->assertSame('2026-11-10', Cache::get('mc-client:business-backfilled'));
    }
}
