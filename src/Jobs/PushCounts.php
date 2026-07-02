<?php

namespace Volkv\McClient\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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
 * push ships the FULL history (backfill); afterwards only a trailing window —
 * re-pushing a day is harmless, the hub dedupes on read.
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

        $since = Cache::get(self::BACKFILL_MARKER)
            ? now()->subDays((int) config('mc-client.push_tail_days', 3))->format('Y-m-d')
            : null; // first run → full history

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
            Cache::forever(self::BACKFILL_MARKER, now()->toIso8601String());
        } else {
            Log::warning('mc-client: business push incomplete — will retry next run');
        }
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
