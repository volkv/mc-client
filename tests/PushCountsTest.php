<?php

namespace Volkv\McClient\Tests;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Volkv\McClient\Jobs\PushCounts;

class PushCountsTest extends TestCase
{
    use RefreshDatabase;

    private const MARKER = 'mc-client:business-backfilled';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-30 05:50', 'Europe/Moscow'));
        foreach ([400, 120, 60, 13, 12, 3, 0] as $daysAgo) {
            DB::table('mc_counts')->insert([
                'date' => Carbon::now('Europe/Moscow')->subDays($daysAgo)->format('Y-m-d'),
                'event_name' => 'signups',
                'count' => $daysAgo + 1,
            ]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_first_push_ships_full_history_and_sets_the_marker(): void
    {
        Http::fake(['mc.test/*' => Http::response(['ok' => true])]);

        (new PushCounts)->handle();

        $this->assertSame(7, $this->pushedDayCount());
        $this->assertNotNull(Cache::get(self::MARKER));
    }

    public function test_after_a_daily_push_only_the_tail_is_resent(): void
    {
        Http::fake(['mc.test/*' => Http::response(['ok' => true])]);
        Cache::forever(self::MARKER, Carbon::now()->subDay()->toIso8601String());

        (new PushCounts)->handle();

        $this->assertSame(['2026-06-27', '2026-06-30'], $this->pushedDates());
    }

    public function test_after_an_outage_every_unsent_day_is_resent(): void
    {
        Http::fake(['mc.test/*' => Http::response(['ok' => true])]);
        Cache::forever(self::MARKER, Carbon::now()->subDays(10)->toIso8601String());

        (new PushCounts)->handle();

        // last push 10 days ago + 3 tail days → from 13 days ago
        $this->assertSame(['2026-06-17', '2026-06-18', '2026-06-27', '2026-06-30'], $this->pushedDates());
    }

    public function test_catch_up_is_capped_and_the_gap_is_logged(): void
    {
        Http::fake(['mc.test/*' => Http::response(['ok' => true])]);
        Cache::forever(self::MARKER, Carbon::now()->subDays(200)->toIso8601String());

        (new PushCounts)->handle();

        $this->assertSame(['2026-05-01', '2026-06-17', '2026-06-18', '2026-06-27', '2026-06-30'], $this->pushedDates());
        $this->assertStringContainsString('exceeds the catch-up ceiling', $this->loggedText());
    }

    public function test_a_failed_push_keeps_the_marker_so_the_next_run_catches_up(): void
    {
        $marker = Carbon::now()->subDays(10)->toIso8601String();
        Cache::forever(self::MARKER, $marker);
        Http::fake(['mc.test/*' => Http::response('down', 503)]);

        (new PushCounts)->handle();

        $this->assertSame($marker, Cache::get(self::MARKER));
        $this->assertStringContainsString('business push incomplete', $this->loggedText());
    }

    private function pushedDates(): array
    {
        $dates = [];
        Http::assertSent(function (Request $r) use (&$dates) {
            foreach ($r['counts'] as $row) {
                $dates[] = $row['date'];
            }

            return true;
        });
        sort($dates);

        return $dates;
    }

    private function pushedDayCount(): int
    {
        return count($this->pushedDates());
    }
}
