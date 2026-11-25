<?php

namespace Volkv\McClient;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;
use Volkv\McClient\Models\McCount;

/**
 * Fail-safe client for the Mission Control hub. Every method is a silent no-op
 * without config and NEVER throws — reporting must not break the host app.
 */
class Mc
{
    // ---- counters (daily business metrics; loggio's proven mechanics) ----

    public static function count(string $eventName, int|float $add = 1): void
    {
        if (!self::shouldRun()) {
            return;
        }

        // Two statements, each atomic in the database, instead of read-modify-write in PHP:
        // two requests that both read n and both wrote n+1 lost one increment, and two first
        // events of a day raced on the unique key (codex audit 15.11, MC-01). The row is made
        // to exist, then incremented in place — the database serialises the increments.
        try {
            $key = ['date' => self::day(), 'event_name' => $eventName];

            // One transaction: a failed increment must not leave a fresh row at 0 behind,
            // which PushCounts would ship as the day's measurement.
            DB::transaction(function () use ($key, $add) {
                DB::table((new McCount)->getTable())->insertOrIgnore($key + ['count' => 0]);
                McCount::query()->where($key)->increment('count', $add);
            });
        } catch (Throwable $e) {
            self::warn('mc-client counter write failed', ['event' => $eventName, 'error' => $e->getMessage()]);
        }
    }

    public static function setCount(string $eventName, int|float $count, ?Carbon $date = null): void
    {
        if (!self::shouldRun()) {
            return;
        }

        try {
            $record = self::record($eventName, $date);
            $record->count = $count;
            $record->saveQuietly();
        } catch (Throwable $e) {
            self::warn('mc-client counter write failed', ['event' => $eventName, 'error' => $e->getMessage()]);
        }
    }

    // ---- heartbeats (dead-man-switch for workers/crons) ----

    public static function beat(string $name, int $intervalSeconds = 3600): void
    {
        self::post('/api/ingest/heartbeat', [
            'slug' => config('mc-client.slug'),
            'name' => $name,
            'interval_seconds' => $intervalSeconds,
        ]);
    }

    // ---- app errors (deduped by the hub per fingerprint; throttled here) ----

    public static function reportError(Throwable $e, array $context = []): void
    {
        if (!self::enabled()) {
            return;
        }

        $fingerprint = get_class($e) . ':' . self::relativePath($e->getFile()) . ':' . $e->getLine();

        // Throttle repeats of the same fingerprint client-side. The cache is the first thing
        // to fail in an outage — exactly when errors are reported — and an exception from here
        // escaped into the host's exception handler and cut its other reporters off (MC-02).
        // Without the cache the report still goes out: the hub dedupes by fingerprint anyway.
        $throttle = (int) config('mc-client.error_throttle_seconds', 60);
        if ($throttle > 0) {
            try {
                if (!Cache::add('mc-client:err:' . md5($fingerprint), 1, $throttle)) {
                    return;
                }
            } catch (Throwable $cacheError) {
                self::warn('mc-client error throttle unavailable', ['error' => $cacheError->getMessage()]);
            }
        }

        self::post('/api/ingest/error', [
            'slug' => config('mc-client.slug'),
            'fingerprint' => Str::limit($fingerprint, 480, ''),
            'message' => Str::limit($e->getMessage() ?: get_class($e), 1900),
            'context' => array_merge([
                'file' => self::relativePath($e->getFile()),
                'line' => $e->getLine(),
                'url' => self::currentUrl(),
            ], $context),
        ]);
    }

    // ---- plumbing ----

    /** Counters run by environment policy alone; HTTP additionally needs full config. */
    public static function shouldRun(): bool
    {
        return !(config('mc-client.production_only', true) && !app()->isProduction());
    }

    public static function enabled(): bool
    {
        return self::shouldRun()
            && config('mc-client.url')
            && config('mc-client.token')
            && config('mc-client.slug');
    }

    /** Fire-and-forget POST to the hub; logs failures, never throws. */
    public static function post(string $path, array $payload): bool
    {
        if (!self::enabled()) {
            return false;
        }

        try {
            $response = Http::timeout((int) config('mc-client.http_timeout', 3))
                ->withToken(config('mc-client.token'))
                ->post(rtrim((string) config('mc-client.url'), '/') . $path, $payload);

            if ($response->failed()) {
                self::warn('mc-client request failed', ['path' => $path, 'status' => $response->status()]);
                return false;
            }

            return true;
        } catch (Throwable $e) {
            self::warn('mc-client request failed', ['path' => $path, 'error' => $e->getMessage()]);
            return false;
        }
    }

    private static function record(string $event_name, ?Carbon $date = null): McCount
    {
        $date = self::day($date);

        return McCount::firstOrNew(compact('date', 'event_name'));
    }

    /** A log line that cannot itself throw: the logger may be what is down. */
    private static function warn(string $message, array $context): void
    {
        try {
            Log::warning($message, $context);
        } catch (Throwable) {
            // Nothing left to report to; the contract is that the host never sees this.
        }
    }

    private static function day(?Carbon $date = null): string
    {
        return ($date ?? Carbon::now())
            ->timezone(config('mc-client.timezone', 'Europe/Moscow'))
            ->format('Y-m-d');
    }

    private static function relativePath(string $file): string
    {
        return Str::after($file, base_path() . DIRECTORY_SEPARATOR);
    }

    private static function currentUrl(): ?string
    {
        try {
            return app()->runningInConsole() ? null : request()->fullUrl();
        } catch (Throwable) {
            return null;
        }
    }
}
