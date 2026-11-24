<?php

namespace Volkv\McClient\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use ReflectionProperty;
use RuntimeException;
use Volkv\McClient\Mc;

class ReportErrorTest extends TestCase
{
    public function test_reports_once_per_fingerprint_within_the_throttle(): void
    {
        Http::fake(['mc.test/*' => Http::response(['ok' => true])]);

        for ($i = 0; $i < 3; $i++) {
            Mc::reportError(new RuntimeException('same place'));
        }

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://mc.test/api/ingest/error'
            && $r['message'] === 'same place'
            && $r['slug'] === 'project');
    }

    public function test_cache_outage_neither_throws_nor_loses_the_error(): void
    {
        Http::fake(['mc.test/*' => Http::response(['ok' => true])]);
        Cache::shouldReceive('add')->andThrow(new RuntimeException('redis is down'));

        Mc::reportError(new RuntimeException('original failure A'));

        Http::assertSentCount(1);
        $log = $this->loggedText();
        $this->assertStringContainsString('mc-client error throttle unavailable', $log);
        $this->assertStringContainsString('original failure A', $log);
        $this->assertStringContainsString('redis is down', $log);
    }

    public function test_cache_outage_falls_back_to_a_per_process_throttle(): void
    {
        Http::fake(['mc.test/*' => Http::response(['ok' => true])]);
        Cache::shouldReceive('add')->andThrow(new RuntimeException('redis is down'));

        for ($i = 0; $i < 3; $i++) {
            Mc::reportError(new RuntimeException('repeating during outage'));
        }

        Http::assertSentCount(1);
    }

    public function test_cache_and_hub_both_down_keeps_the_original_error_in_the_log(): void
    {
        Http::fake(['mc.test/*' => Http::failedConnection('connection timed out')]);
        Cache::shouldReceive('add')->andThrow(new RuntimeException('redis is down'));

        Mc::reportError(new RuntimeException('original failure B'));

        $log = $this->loggedText();
        $this->assertStringContainsString('mc-client error report not delivered', $log);
        $this->assertStringContainsString('original failure B', $log);
        $this->assertStringContainsString('connection timed out', $log);
    }

    public function test_hub_down_logs_the_original_error(): void
    {
        Http::fake(['mc.test/*' => Http::response('nope', 503)]);

        Mc::reportError(new RuntimeException('original failure C'));

        $log = $this->loggedText();
        $this->assertStringContainsString('"status":503', $log);
        $this->assertStringContainsString('original failure C', $log);
    }

    public function test_never_throws_even_when_the_log_channel_is_broken(): void
    {
        $errorLog = sys_get_temp_dir() . '/mc-client-error-log-' . getmypid() . '.log';
        @unlink($errorLog);
        $previous = ini_set('error_log', $errorLog);

        try {
            Http::fake(['mc.test/*' => Http::failedConnection('connection timed out')]);
            Cache::shouldReceive('add')->andThrow(new RuntimeException('redis is down'));
            Log::shouldReceive('warning')->andThrow(new RuntimeException('log disk full'));

            Mc::reportError(new RuntimeException('original failure D'));

            $fallback = (string) file_get_contents($errorLog);
            $this->assertStringContainsString('original failure D', $fallback);
            $this->assertStringContainsString('log disk full', $fallback);
        } finally {
            ini_set('error_log', (string) $previous);
            @unlink($errorLog);
        }
    }

    public function test_laravel_still_logs_the_original_error_when_the_cache_is_down(): void
    {
        Http::fake(['mc.test/*' => Http::response(['ok' => true])]);
        Cache::shouldReceive('add')->andThrow(new RuntimeException('redis is down'));
        $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class)
            ->reportable(fn (\Throwable $e) => Mc::reportError($e));

        report(new RuntimeException('original failure E'));

        // Laravel's own log entry is written only if the reportable callback did not throw.
        $this->assertStringContainsString('.ERROR: original failure E', $this->loggedText());
    }

    public function test_http_requests_send_the_report_after_the_response(): void
    {
        Http::fake(['mc.test/*' => Http::response(['ok' => true])]);
        $console = new ReflectionProperty($this->app, 'isRunningInConsole');
        $console->setValue($this->app, false);

        try {
            Mc::reportError(new RuntimeException('during request'));

            Http::assertNothingSent();

            $this->app->terminate();

            Http::assertSentCount(1);
            Http::assertSent(fn (Request $r) => $r['message'] === 'during request');
        } finally {
            $console->setValue($this->app, null);
        }
    }

    public function test_disabled_without_config(): void
    {
        Http::fake();
        config(['mc-client.token' => null]);

        Mc::reportError(new RuntimeException('not configured'));

        Http::assertNothingSent();
    }
}
