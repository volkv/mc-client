# mc-client — Laravel client for a Mission Control hub

Ships three things from a Laravel app to a central [Mission Control](https://github.com/volkv)-style hub over a simple ingest API:

1. **Business counters** — `Mc::count('Premium Conversions')` stores cheap local daily counts and pushes them to the hub once a day (`/api/ingest/business`). Existing [volkv/loggio](https://github.com/volkv/loggio) counters are bridged automatically while you migrate.
2. **App errors** — `Mc::reportError($e)` sends deduplicated, throttled error reports (`/api/ingest/error`).
3. **Worker heartbeats** — `Mc::beat('queue:default', 300)` is a dead-man-switch for crons and workers (`/api/ingest/heartbeat`).

Every call is **fail-safe**: missing config → silent no-op; cache, database, network and HTTP failures are written to the host log (PHP `error_log` if the log channel itself fails), never thrown.

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

`count()` is an atomic `count = count + n` (insert-or-ignore of the day row, then an `UPDATE … SET count = count + n`), `setCount()` is an upsert — both are safe under concurrent writers on PostgreSQL, MySQL and SQLite and rely on the `(date, event_name)` unique index from the package migration.

Schedule the daily push (Kernel / `routes/console.php`):

```php
use Volkv\McClient\Jobs\PushCounts;

$schedule->job(new PushCounts)->environments(['production'])->dailyAt('05:50');
```

The **first** push ships the full history (including the legacy `loggio` table when present — sums per event+date). Later runs re-send every day since the last successful push plus `push_tail_days` (3), so a hub outage loses nothing; the catch-up is capped at `push_max_catchup_days` (90) with a warning in the log. Event names are normalized by the hub (`Premium Conversions` → `business.premium_conversions`).

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

Fingerprint = exception class + file:line; repeats are throttled client-side (60s, per-process while the cache store is down) and deduplicated hub-side. In HTTP requests the report is sent after the response (terminating callbacks; a shutdown function covers fatal errors), in console and queue processes immediately. When the report cannot be delivered, the original error is written to the host log.

## Heartbeats

```php
$schedule->call(fn () => Mc::beat('scheduler', 300))->everyFiveMinutes();
// or at the end of a worker/cron cycle:
Mc::beat('queue:default', 600);
```

The hub alerts when a heartbeat goes silent for 2× its interval. A beat is retried once on a connection failure or 5xx (`connect_timeout` 2 s, `http_timeout` 3 s).

## Tests

```bash
composer install
vendor/bin/phpunit                      # in-memory SQLite (concurrency test on a temp SQLite file)
DB_CONNECTION=pgsql DB_HOST=… DB_DATABASE=… DB_USERNAME=… DB_PASSWORD=… vendor/bin/phpunit
```

The concurrency test forks writers and needs `pcntl` and `posix`; without them it is skipped.

## License

MIT
