<?php

namespace LogLens\Http\Controllers;

use Illuminate\Http\Request;
use LogLens\Analytics\Histogram;
use LogLens\Indexing\IndexManager;
use LogLens\Indexing\SqliteIndexStore;
use LogLens\Sources\LocalFileSource;
use LogLens\Support\Timestamp;

/**
 * Analytics endpoints. Histogram and
 * sparklines are answered exclusively from the pre-aggregated stats/groups
 * tables — no entry scans.
 */
class StatsController extends Controller
{
    public function __construct(
        private IndexManager $manager,
        private LocalFileSource $files
    ) {
    }

    public function histogram(Request $request, string $file): \Illuminate\Http\JsonResponse
    {
        $identity = $this->identityOrFail($file);
        $store = $this->manager->store($identity);

        $from = $request->filled('from') ? Timestamp::parse((string) $request->query('from')) : null;
        $to = $request->filled('to') ? Timestamp::parse((string) $request->query('to')) : null;
        $granularity = $this->bucketSeconds((string) $request->query('bucket', ''));

        $filters = array_filter(['after' => $from, 'before' => $to], fn ($v) => $v !== null);
        $histogram = Histogram::build($store->stats($filters), $from, $to, $granularity);
        $state = $this->manager->indexState($store, $identity->size);
        $histogram['partial'] = $state['state'] !== 'ready';

        return $this->json($histogram);
    }

    public function sparkline(Request $request, string $file): \Illuminate\Http\JsonResponse
    {
        $store = $this->manager->store($this->identityOrFail($file));
        $stats = $store->stats();

        // Compress hourly totals into a small fixed-width series for the UI.
        $buckets = $stats['buckets'] ?? [];
        ksort($buckets);
        $series = [];
        foreach ($buckets as $hour => $levels) {
            $series[] = array_sum($levels);
        }

        return $this->json(['series' => $this->downsample($series, 32)]);
    }

    public function groupSparkline(string $file, int $fp): \Illuminate\Http\JsonResponse
    {
        $store = $this->manager->store($this->identityOrFail($file));
        if (! $store instanceof SqliteIndexStore) {
            return $this->json(['series' => []]);
        }

        return $this->json(['series' => $store->groupSparkline($fp, 24)]);
    }

    /** Map a UI bucket keyword to seconds (null = auto-select by range). */
    private function bucketSeconds(string $bucket): ?int
    {
        return [
            'hour' => 3600,
            'day' => 86400,
            'week' => 604800,
            'month' => 2592000,
        ][$bucket] ?? null;
    }

    private function downsample(array $series, int $target): array
    {
        $n = count($series);
        if ($n <= $target) {
            return $series;
        }
        $out = [];
        $step = $n / $target;
        for ($i = 0; $i < $target; $i++) {
            $slice = array_slice($series, (int) floor($i * $step), max(1, (int) ceil($step)));
            $out[] = array_sum($slice);
        }

        return $out;
    }
}
