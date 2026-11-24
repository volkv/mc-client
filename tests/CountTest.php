<?php

namespace Volkv\McClient\Tests;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Volkv\McClient\Mc;

class CountTest extends TestCase
{
    use RefreshDatabase;

    public function test_count_creates_then_increments_todays_row(): void
    {
        Mc::count('signups');
        Mc::count('signups', 4);
        Mc::count('cost_usd', 0.0123);
        Mc::count('cost_usd', 0.5);

        $this->assertSame(5.0, $this->value('signups'));
        $this->assertSame(0.5123, $this->value('cost_usd'));
        $this->assertSame(2, DB::table('mc_counts')->count());
    }

    public function test_zero_add_creates_the_row_without_a_lost_write_warning(): void
    {
        Mc::count('visits', 0);

        $this->assertSame(0.0, $this->value('visits'));
        $this->assertStringNotContainsString('counter write', $this->loggedText());
    }

    public function test_set_count_upserts_and_overrides(): void
    {
        Mc::setCount('daily_users', 10);
        Mc::setCount('daily_users', 7);
        Mc::count('daily_users', 2);

        $this->assertSame(9.0, $this->value('daily_users'));
        $this->assertSame(1, DB::table('mc_counts')->count());
    }

    public function test_set_count_for_a_past_date_does_not_mutate_the_callers_date(): void
    {
        $date = Carbon::parse('2026-01-10 23:30', 'UTC'); // already the 11th in Europe/Moscow

        Mc::setCount('daily_users', 3, $date);

        $this->assertSame('UTC', $date->timezoneName);
        $this->assertSame(3.0, (float) DB::table('mc_counts')->where('date', '2026-01-11')->value('count'));
    }

    public function test_count_never_throws_and_logs_when_the_table_is_missing(): void
    {
        Schema::drop('mc_counts');

        Mc::count('signups');
        Mc::setCount('daily_users', 1);

        $this->assertStringContainsString('mc-client counter write failed', $this->loggedText());
    }

    private function value(string $event): ?float
    {
        $value = DB::table('mc_counts')->where('event_name', $event)->value('count');

        return $value === null ? null : (float) $value;
    }
}
