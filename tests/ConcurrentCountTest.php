<?php

namespace Volkv\McClient\Tests;

use Illuminate\Support\Facades\DB;
use Volkv\McClient\Mc;

/**
 * Real concurrent writers: forked processes hammer the same counter row.
 * A read-modify-write increment loses updates here; the atomic one must not.
 * In-memory sqlite cannot be shared across processes, so sqlite runs on a file.
 */
class ConcurrentCountTest extends TestCase
{
    private const WORKERS = 6;
    private const INCREMENTS = 40;

    private ?string $sqliteFile = null;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $default = $app['config']->get('database.default');
        if ($app['config']->get("database.connections.{$default}.driver") === 'sqlite') {
            $this->sqliteFile = sys_get_temp_dir() . '/mc-client-concurrency-' . getmypid() . '.sqlite';
            @unlink($this->sqliteFile);
            touch($this->sqliteFile);
            $app['config']->set('database.connections.mc_concurrency', [
                'driver' => 'sqlite',
                'database' => $this->sqliteFile,
                'prefix' => '',
                'foreign_key_constraints' => false,
                'busy_timeout' => 20000,
                'journal_mode' => 'wal',
            ]);
            $app['config']->set('database.default', 'mc_concurrency');
        }
    }

    protected function setUp(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl and posix are required for the concurrency test');
        }

        parent::setUp();

        $this->artisan('migrate:fresh');
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if ($this->sqliteFile !== null) {
            foreach (['', '-wal', '-shm'] as $suffix) {
                @unlink($this->sqliteFile . $suffix);
            }
        }
    }

    public function test_concurrent_increments_are_not_lost(): void
    {
        DB::disconnect();

        $children = [];
        for ($i = 0; $i < self::WORKERS; $i++) {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid, 'fork failed');

            if ($pid === 0) {
                DB::purge();
                for ($n = 0; $n < self::INCREMENTS; $n++) {
                    Mc::count('concurrent');
                    Mc::count('concurrent_fraction', 0.25);
                }
                // Skip PHPUnit's and Laravel's shutdown handlers in the child.
                posix_kill(posix_getpid(), SIGKILL);
            }

            $children[] = $pid;
        }

        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }

        DB::purge();
        $total = self::WORKERS * self::INCREMENTS;

        $this->assertSame((float) $total, (float) DB::table('mc_counts')->where('event_name', 'concurrent')->value('count'));
        $this->assertSame($total * 0.25, (float) DB::table('mc_counts')->where('event_name', 'concurrent_fraction')->value('count'));
        $this->assertSame(2, DB::table('mc_counts')->count());
        $this->assertStringNotContainsString('counter write', $this->loggedText());
    }
}
