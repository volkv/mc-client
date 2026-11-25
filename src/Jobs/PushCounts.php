<?php

namespace Volkv\McClient\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Volkv\McClient\Mc;

/**
 * Daily push of business counters to Mission Control (/api/ingest/business).
 *
 * Sources: mc_counts + the legacy `loggio` table while it still exists (bridge for
 * the migration period; rows for the same event+date are summed). The first ever
 * push ships the FULL history (backfill); afterwards a trailing window that
 * reaches back to the last complete push — see since().
 */
class PushCounts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const BACKFILL_MARKER = 'mc-client:business-backfilled';
    private const CHUNK = 5000;

    public function handle(): void
    {
        if (!Mc::enabled()) {
            return;
        }

        // The day being read, taken before the read: a counter that moves after
        // the read and before a push that finishes past midnight is still in
        // the window of the next run.
        $readDay = $this->today();
        $since = $this->since(Cache::get(self::BACKFILL_MARKER), $readDay);

        $counts = $this->collect($since);
        if ($counts->isEmpty()) {
            return;
        }

        $allOk = true;
        foreach ($counts->chunk(self::CHUNK) as $chunk) {
            $ok = Mc::post('/api/ingest/business', [
                'slug' => config('mc-client.slug'),
                'counts' => $chunk->values()->all(),
            ]);
            $allOk = $allOk && $ok;
        }

        if ($allOk) {
            Cache::forever(self::BACKFILL_MARKER, $readDay->format('Y-m-d'));
        } else {
            Log::warning('mc-client: business push incomplete — will retry next run');
        }
    }

    /**
     * First run (no marker) → full history. Afterwards the trailing window, but
     * reaching back to the day the last complete push read: the marker is
     * written only when every chunk landed, so a hub outage longer than the
     * window used to leave the days in between unpushed for good. Re-pushing a
     * day is harmless — the hub dedupes on read.
     *
     * The catch-up is capped (push_max_catchup_days): a chunk the hub rejects
     * for good would otherwise keep the marker in place and grow every push by
     * a day, forever. Days past the cap are given up, loudly.
     *
     * All days are counter days, in mc-client.timezone — the zone mc_counts.date
     * is written in — not the app's.
     */
    private function since(mixed $marker, Carbon $today): ?string
    {
        if ($marker === null) {
            return null;
        }

        $tail = $today->copy()->subDays((int) config('mc-client.push_tail_days', 3));
        $floor = $today->copy()->subDays((int) config('mc-client.push_max_catchup_days', 30));

        $last = $this->markerDay($marker);
        if ($last === null || $last->greaterThanOrEqualTo($tail)) {
            return $tail->format('Y-m-d');
        }
        if ($last->lessThan($floor)) {
            Log::warning('mc-client: business push has not completed since '.$last->format('Y-m-d').'; days before '.$floor->format('Y-m-d').' are no longer re-sent');

            return $floor->format('Y-m-d');
        }

        return $last->format('Y-m-d');
    }

    /** The marker as a counter day: Y-m-d now, an ISO time before 0.2.2; anything else → null. */
    private function markerDay(mixed $marker): ?Carbon
    {
        if (!is_string($marker)) {
            return null;
        }
        $timezone = config('mc-client.timezone', 'Europe/Moscow');

        try {
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $marker) === 1
                ? Carbon::createFromFormat('Y-m-d', $marker, $timezone)->startOfDay()
                : Carbon::parse($marker)->timezone($timezone)->startOfDay();
        } catch (\Throwable) {
            Log::warning('mc-client: unreadable business push marker, re-sending the trailing window only');

            return null;
        }
    }

    private function today(): Carbon
    {
        return Carbon::now()->timezone(config('mc-client.timezone', 'Europe/Moscow'))->startOfDay();
    }

    /** Merge mc_counts + legacy loggio rows, summing counts per (date, event). */
    private function collect(?string $sinceDate)
    {
        $rows = collect();
        foreach (['mc_counts', 'loggio'] as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            $query = DB::table($table)->select('date', 'event_name', 'count');
            if ($sinceDate !== null) {
                $query->where('date', '>=', $sinceDate);
            }
            $rows = $rows->concat($query->get());
        }

        return $rows
            ->groupBy(fn ($r) => $r->date . '|' . $r->event_name)
            ->map(fn ($group) => [
                'event' => (string) $group->first()->event_name,
                'date' => substr((string) $group->first()->date, 0, 10),
                'count' => round((float) $group->sum('count'), 6),
            ])
            ->values();
    }
}
