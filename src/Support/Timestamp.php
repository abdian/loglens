<?php

namespace LogLens\Support;

/**
 * Dependency-free timestamp parsing (no Carbon). Handles the variants emitted
 * by Laravel/Monolog and the other built-in formats:
 *
 *   2026-06-13 14:30:00            (Y-m-d H:i:s)
 *   2026-06-13T14:30:00.123456+00:00 (ISO-8601 micros + offset)
 *   13/Jun/2026:14:30:00 +0000     (Apache/nginx access)
 *   2026/06/13 14:30:00            (nginx error, redis)
 *   Jun 13 14:30:00                (syslog-ish / supervisor)
 *
 * Returns a Unix timestamp (int seconds) or null.
 */
final class Timestamp
{
    private const MONTHS = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
        'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
    ];

    /**
     * Source timezone for offset-less log timestamps. Set once at boot from
     * config('loglens.parsing.timezone') ?? config('app.timezone'). Null = UTC.
     * Timestamps that carry an explicit offset (or trailing Z) are always
     * resolved exactly and ignore this.
     */
    private static ?\DateTimeZone $zone = null;

    public static function useTimezone(?string $tz): void
    {
        if ($tz === null || $tz === '' || strtoupper($tz) === 'UTC') {
            self::$zone = null;

            return;
        }
        try {
            self::$zone = new \DateTimeZone($tz);
        } catch (\Throwable $e) {
            self::$zone = null; // unknown zone → fall back to UTC, never throw
        }
    }

    public static function parse(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        // Fast path: Y-m-d H:i:s with optional .micros and optional offset/Z.
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})(?:\.\d+)?(?:\s*([+-]\d{2}):?(\d{2})|(Z))?/', $value, $m)) {
            if (isset($m[7]) && $m[7] !== '') {
                $ts = gmmktime((int) $m[4], (int) $m[5], (int) $m[6], (int) $m[2], (int) $m[3], (int) $m[1]);

                return $ts - (((int) $m[7]) * 3600 + (($m[7][0] === '-' ? -1 : 1) * (int) $m[8]) * 60);
            }
            // Trailing "Z" is explicit UTC; bare wall-clock uses the source zone.
            $utc = isset($m[9]) && $m[9] !== '';

            return self::fromParts((int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4], (int) $m[5], (int) $m[6], $utc);
        }

        // Apache/nginx access: 13/Jun/2026:14:30:00 +0000 (offset usually present)
        if (preg_match('#^(\d{2})/(\w{3})/(\d{4}):(\d{2}):(\d{2}):(\d{2})(?:\s*([+-]\d{2})(\d{2}))?#', $value, $m)) {
            $mon = self::MONTHS[strtolower($m[2])] ?? 1;
            if (isset($m[7]) && $m[7] !== '') {
                $ts = gmmktime((int) $m[4], (int) $m[5], (int) $m[6], $mon, (int) $m[1], (int) $m[3]);

                return $ts - (((int) $m[7]) * 3600 + (($m[7][0] === '-' ? -1 : 1) * (int) $m[8]) * 60);
            }

            return self::fromParts((int) $m[3], $mon, (int) $m[1], (int) $m[4], (int) $m[5], (int) $m[6]);
        }

        // nginx error / redis: 2026/06/13 14:30:00 (no offset)
        if (preg_match('#^(\d{4})/(\d{2})/(\d{2})[ T](\d{2}):(\d{2}):(\d{2})#', $value, $m)) {
            return self::fromParts((int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4], (int) $m[5], (int) $m[6]);
        }

        // Apache 2.4 error log: Sat Jun 13 14:30:00.123456 2026.
        if (preg_match('/^\w{3}\s+(\w{3})\s+(\d{1,2})\s+(\d{2}):(\d{2}):(\d{2})(?:\.\d+)?\s+(\d{4})/', $value, $m)) {
            $mon = self::MONTHS[strtolower($m[1])] ?? 1;

            return self::fromParts((int) $m[6], $mon, (int) $m[2], (int) $m[3], (int) $m[4], (int) $m[5]);
        }

        // syslog: Jun 13 14:30:00 (no year → guess, correcting across the boundary)
        if (preg_match('/^(\w{3})\s+(\d{1,2})\s+(\d{2}):(\d{2}):(\d{2})/', $value, $m)) {
            $mon = self::MONTHS[strtolower($m[1])] ?? 1;
            $year = (int) gmdate('Y');
            $ts = self::fromParts($year, $mon, (int) $m[2], (int) $m[3], (int) $m[4], (int) $m[5]);
            // A yearless date that lands clearly in the future is last year's log
            // (e.g. a December line read in January).
            if ($ts > time() + 86400) {
                $ts = self::fromParts($year - 1, $mon, (int) $m[2], (int) $m[3], (int) $m[4], (int) $m[5]);
            }

            return $ts;
        }

        // Fallback to PHP's parser; never throw.
        $ts = @strtotime($value);

        return $ts === false ? null : $ts;
    }

    /**
     * Build a Unix timestamp from wall-clock parts. Offset-less parts are read in
     * the configured source zone (UTC unless overridden); $utc forces UTC.
     */
    private static function fromParts(int $y, int $mo, int $d, int $h, int $mi, int $s, bool $utc = false): int
    {
        if ($utc || self::$zone === null) {
            return gmmktime($h, $mi, $s, $mo, $d, $y);
        }
        $dt = new \DateTimeImmutable(
            sprintf('%04d-%02d-%02d %02d:%02d:%02d', $y, $mo, $d, $h, $mi, $s),
            self::$zone
        );

        return $dt->getTimestamp();
    }
}
