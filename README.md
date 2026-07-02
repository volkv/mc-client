# mc-client — Laravel client for a Mission Control hub

Ships three things from a Laravel app to a central [Mission Control](https://github.com/volkv)-style hub over a simple ingest API:

1. **Business counters** — `Mc::count('Premium Conversions')` stores cheap local daily counts and pushes them to the hub once a day (`/api/ingest/business`). Existing [volkv/loggio](https://github.com/volkv/loggio) counters are bridged automatically while you migrate.
2. **App errors** — `Mc::reportError($e)` sends deduplicated, throttled error reports (`/api/ingest/error`).
3. **Worker heartbeats** — `Mc::beat('queue:default', 300)` is a dead-man-switch for crons and workers (`/api/ingest/heartbeat`).

Every call is **fail-safe**: missing config → silent no-op; network/HTTP errors are logged, never thrown.

## Install

```bash
composer require volkv/mc-client "*"
php artisan migrate
```

`.env`:

```dotenv
MC_URL=https://mc.example.com
MC_TOKEN=<system-token>
MC_SLUG=<project-slug-in-the-hub>
```

Optionally publish the config:

```bash
php artisan vendor:publish --provider="Volkv\McClient\McClientServiceProvider"
```

## Counters

```php
use Volkv\McClient\Mc;

Mc::count('API Calls');                  // +1 today
Mc::count('Imported tracks', 25);        // +25 today
Mc::count('Gemini Cost USD', 0.0123);    // fractional counters (cost etc.), v0.2+
Mc::setCount('Daily users', 1234);       // absolute value for today
```

Schedule the daily push (Kernel / `routes/console.php`):

```php
use Volkv\McClient\Jobs\PushCounts;

$schedule->job(new PushCounts)->environments(['production'])->dailyAt('05:50');
```

The **first** push ships the full history (including the legacy `loggio` table when present — sums per event+date); later runs re-send only a trailing window. Event names are normalized by the hub (`Premium Conversions` → `business.premium_conversions`).

### Migrating from loggio

`Loggio::increment(...)` → `Mc::count(...)`, `Loggio::setCount(...)` → `Mc::setCount(...)`. While both tables exist the push merges them, so you can migrate call sites gradually; once done — `composer remove volkv/loggio` and drop the `loggio` table.

## Errors

Laravel 11/12 (`bootstrap/app.php`):

```php
->withExceptions(function (Exceptions $exceptions) {
    $exceptions->report(fn (Throwable $e) => \Volkv\McClient\Mc::reportError($e));
})
```

Laravel 10 (`app/Exceptions/Handler.php`):

```php
public function register(): void
{
    $this->reportable(fn (Throwable $e) => \Volkv\McClient\Mc::reportError($e));
}
```

Fingerprint = exception class + file:line; repeats are throttled client-side (60s) and deduplicated hub-side.

## Heartbeats

```php
$schedule->call(fn () => Mc::beat('scheduler', 300))->everyFiveMinutes();
// or at the end of a worker/cron cycle:
Mc::beat('queue:default', 600);
```

The hub alerts when a heartbeat goes silent for 2× its interval.

## License

MIT
