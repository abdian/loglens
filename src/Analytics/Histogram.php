<?php

namespace LogLens\Analytics;

use LogLens\Indexing\Level;

/**
 * Build a level-stacked volume histogram from the pre-aggregated per-hour stats
 * buckets. Auto-selects granularity by range so
 * the chart stays readable from minutes to months, never scanning entries.
 */
final class Histogram
{
    /**
     * @param  array  $stats  ['buckets' => [hour => [level => count]], 'levels' => [...]]
     * @return array{granularity:int, buckets:array, levels:array}
     */
    public static function build(array $stats, ?int $from = null, ?int $to = null, ?int $forceGranularity = null): array
    {
        $hourBuckets = $stats['buckets'] ?? [];
        if (empty($hourBuckets)) {
            return ['granularity' => $forceGranularity ?: 3600, 'buckets' => [], 'levels' => self::levelTotals($stats)];
        }

        $keys = array_keys($hourBuckets);
        // The store's stats() floors after/before to the hour before filtering, so
        // align these bounds the same way — otherwise the bucket straddling the
        // boundary is included by stats() but dropped here (or vice-versa).
        $min = $from !== null ? intdiv($from, 3600) * 3600 : min($keys);
        $max = $to !== null ? intdiv($to, 3600) * 3600 : (max($keys) + 3600);
        // The source stats are hourly, so anything finer than an hour is clamped
        // up to an hour; coarser (day/week/month) merges as requested.
        $granularity = $forceGranularity && $forceGranularity > 0
            ? max(3600, $forceGranularity)
            : self::granularity($max - $min);

        $merged = [];
        foreach ($hourBuckets as $hour => $levels) {
            if ($hour < $min || $hour > $max) {
                continue;
            }
            $slot = intdiv((int) $hour, $granularity) * $granularity;
            foreach ($levels as $lvl => $count) {
                $merged[$slot][$lvl] = ($merged[$slot][$lvl] ?? 0) + $count;
            }
        }
        ksort($merged);

        $out = [];
        foreach ($merged as $slot => $levels) {
            $named = [];
            foreach ($levels as $lvl => $count) {
                $named[Level::name((int) $lvl)] = $count;
            }
            $out[] = ['t' => $slot, 'levels' => $named, 'total' => array_sum($levels)];
        }

        return [
            'granularity' => $granularity,
            'buckets' => $out,
            'levels' => self::levelTotals($stats),
        ];
    }

    private static function granularity(int $span): int
    {
        // Aim for ~60–120 bars across the range.
        $candidates = [60, 300, 900, 3600, 21600, 86400, 604800, 2592000];
        foreach ($candidates as $g) {
            if ($span / $g <= 120) {
                return $g;
            }
        }

        return end($candidates);
    }

    private static function levelTotals(array $stats): array
    {
        $named = [];
        foreach (Level::all() as $ord => $name) {
            $named[$name] = $stats['levels'][$ord] ?? 0;
        }

        return $named;
    }
}
