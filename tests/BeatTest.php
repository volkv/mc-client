<?php

namespace Volkv\McClient\Tests;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Volkv\McClient\Mc;

class BeatTest extends TestCase
{
    public function test_retries_once_after_a_connection_timeout(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            if (++$calls === 1) {
                throw new ConnectionException('cURL error 28: Connection timed out');
            }

            return Http::response(['ok' => true]);
        });

        Mc::beat('scheduler', 300);

        $this->assertSame(2, $calls);
        $this->assertStringNotContainsString('mc-client request failed', $this->loggedText());
    }

    public function test_retries_once_after_a_server_error_then_logs(): void
    {
        Http::fake(['mc.test/*' => Http::response('down', 502)]);

        Mc::beat('scheduler', 300);

        Http::assertSentCount(2);
        $log = $this->loggedText();
        $this->assertStringContainsString('mc-client request failed', $log);
        $this->assertStringContainsString('"attempts":2', $log);
    }

    public function test_client_errors_are_not_retried(): void
    {
        Http::fake(['mc.test/*' => Http::response('bad token', 401)]);

        Mc::beat('scheduler', 300);

        Http::assertSentCount(1);
        $this->assertStringContainsString('"status":401', $this->loggedText());
    }

    public function test_post_without_attempts_does_not_retry(): void
    {
        Http::fake(['mc.test/*' => Http::failedConnection()]);

        $this->assertFalse(Mc::post('/api/ingest/anything', []));

        Http::assertSentCount(1);
        $this->assertStringContainsString('"attempts":1', $this->loggedText());
    }
}
