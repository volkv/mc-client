<?php

namespace Volkv\McClient;

use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;
use Volkv\McClient\Models\McCount;

/**
 * Fail-safe client for the Mission Control hub. Every method is a no-op without
 * config and NEVER throws — reporting must not break the host app. Every failure
 * is written to the host log (PHP error_log when the log itself is broken).
 */
class Mc
{
    /** Error reports queued in an HTTP request until the response is sent. */
    private static array $pendingErrors = [];

    private static bool $shutdownFlushRegistered = false;

    /** Per-process throttle used only while the cache store is unavailable. */
    private static array $localThrottle = [];

    // ---- counters (daily business metrics) ----

    /** Atomic `count = count + $add` for today's row; safe under concurrent writers. */
    public static function count(string $eventName, int|float $add = 1): void
    {
        try {
            if (!self::shouldRun()) {
                return;
            }

            $key = ['date' => self::day(), 'event_name' => $eventName];

            McCount::query()->insertOrIgnore($key + ['count' => 0]);

            // MySQL reports 0 affected rows for a no-op update, so a zero add is not checked.
            if ($add == 0) {
                return;
            }

            if (McCount::query()->where($key)->increment('count', $add) === 0) {
                self::warn('mc-client counter write lost: no row to increment', ['event' => $eventName, 'add' => $add]);
            }
        } catch (Throwable $e) {
            self::warn('mc-client counter write failed', ['event' => $eventName, 'error' => $e->getMessage()]);
        }
    }

    public static function setCount(string $eventName, int|float $count, ?Carbon $date = null): void
    {
        try {
            if (!self::shouldRun()) {
                return;
            }

            McCount::query()->upsert(
                [['date' => self::day($date), 'event_name' => $eventName, 'count' => $count]],
                ['date', 'event_name'],
                ['count'],
            );
        } catch (Throwable $e) {
            self::warn('mc-client counter write failed', ['event' => $eventName, 'error' => $e->getMessage()]);
        }
    }

    // ---- heartbeats (dead-man-switch for workers/crons) ----

    public static function beat(string $name, int $intervalSeconds = 3600): void
    {
        try {
            // One retry: a lost beat looks like a dead worker on the hub, a duplicate is harmless.
            self::post('/api/ingest/heartbeat', [
                'slug' => config('mc-client.slug'),
                'name' => $name,
                'interval_seconds' => $intervalSeconds,
            ], attempts: 2);
        } catch (Throwable $e) {
            self::warn('mc-client heartbeat failed', ['name' => $name, 'error' => $e->getMessage()]);
        }
    }

    // ---- app errors (deduped by the hub per fingerprint; throttled here) ----

    /**
     * Sends the error to the hub — after the response in HTTP requests, immediately in
     * console/queue processes. Whenever the report cannot be throttled or delivered,
     * the original error is written to the host log instead.
     */
    public static function reportError(Throwable $e, array $context = []): void
    {
        try {
            if (!self::enabled()) {
                return;
            }

            $fingerprint = get_class($e) . ':' . self::relativePath($e->getFile()) . ':' . $e->getLine();

            if (!self::claimErrorSlot($fingerprint, $e)) {
                return;
            }

            $payload = [
                'slug' => config('mc-client.slug'),
                'fingerprint' => Str::limit($fingerprint, 480, ''),
                'message' => Str::limit($e->getMessage() ?: get_class($e), 1900),
                'context' => array_merge([
                    'file' => self::relativePath($e->getFile()),
                    'line' => $e->getLine(),
                    'url' => self::currentUrl(),
                ], $context),
            ];

            if (app()->runningInConsole()) {
                self::sendError($payload, self::describe($e));
            } else {
                self::deferError($payload, self::describe($e));
            }
        } catch (Throwable $failure) {
            self::warn('mc-client error report failed', self::describe($e) + ['error' => $failure->getMessage()]);
        }
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

    /**
     * POST to the hub; logs failures, never throws. Connection failures and 5xx are
     * retried up to $attempts in total; 4xx is final.
     */
    public static function post(string $path, array $payload, int $attempts = 1): bool
    {
        try {
            if (!self::enabled()) {
                return false;
            }

            $url = rtrim((string) config('mc-client.url'), '/') . $path;

            for ($attempt = 1; ; $attempt++) {
                try {
                    $response = Http::connectTimeout((int) config('mc-client.connect_timeout', 2))
                        ->timeout((int) config('mc-client.http_timeout', 3))
                        ->withToken(config('mc-client.token'))
                        ->post($url, $payload);

                    if (!$response->failed()) {
                        return true;
                    }

                    $failure = ['status' => $response->status()];
                    $retryable = $response->serverError();
                } catch (ConnectionException $e) {
                    $failure = ['error' => $e->getMessage()];
                    $retryable = true;
                }

                if (!$retryable || $attempt >= $attempts) {
                    self::warn('mc-client request failed', ['path' => $path, 'attempts' => $attempt] + $failure);

                    return false;
                }
            }
        } catch (Throwable $e) {
            self::warn('mc-client request failed', ['path' => $path, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /** Throttles repeats of a fingerprint; falls back to a per-process throttle when the cache is down. */
    private static function claimErrorSlot(string $fingerprint, Throwable $e): bool
    {
        $throttle = (int) config('mc-client.error_throttle_seconds', 60);
        if ($throttle <= 0) {
            return true;
        }

        $key = 'mc-client:err:' . md5($fingerprint);

        try {
            return Cache::add($key, 1, $throttle);
        } catch (Throwable $cacheFailure) {
            self::warn('mc-client error throttle unavailable, using per-process throttle', self::describe($e) + [
                'error' => $cacheFailure->getMessage(),
            ]);

            $now = time();
            if ((self::$localThrottle[$key] ?? 0) > $now) {
                return false;
            }
            self::$localThrottle[$key] = $now + $throttle;

            return true;
        }
    }

    private static function sendError(array $payload, array $origin): void
    {
        if (!self::post('/api/ingest/error', $payload)) {
            self::warn('mc-client error report not delivered', $origin);
        }
    }

    /**
     * Queues the report until the response is sent (terminating callbacks). The shutdown
     * function covers requests that never reach terminate(), e.g. fatal errors.
     */
    private static function deferError(array $payload, array $origin): void
    {
        if (self::$pendingErrors === []) {
            app()->terminating(static fn () => self::flushErrors());
        }

        if (!self::$shutdownFlushRegistered) {
            register_shutdown_function(static fn () => self::flushErrors());
            self::$shutdownFlushRegistered = true;
        }

        self::$pendingErrors[] = [$payload, $origin];
    }

    private static function flushErrors(): void
    {
        while (($pending = array_shift(self::$pendingErrors)) !== null) {
            self::sendError(...$pending);
        }
    }

    /** Writes to the host log; falls back to PHP's error_log when the log channel itself throws. */
    private static function warn(string $message, array $context): void
    {
        try {
            Log::warning($message, $context);
        } catch (Throwable $logFailure) {
            error_log($message . ' ' . json_encode($context, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_UNICODE)
                . ' (log channel failed: ' . $logFailure->getMessage() . ')');
        }
    }

    /** The original error, as written to the log when the hub cannot receive it. */
    private static function describe(Throwable $e): array
    {
        return [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ];
    }

    private static function day(?Carbon $date = null): string
    {
        return ($date?->copy() ?? Carbon::now())
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
