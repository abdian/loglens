<?php

namespace LogLens\Support;

/**
 * Parse human size strings ("512M", "2G", "1.5g") into byte counts, and format
 * byte counts back for display.
 */
final class ByteSize
{
    private const UNITS = ['B' => 1, 'K' => 1024, 'M' => 1048576, 'G' => 1073741824, 'T' => 1099511627776];

    public static function parse($value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }

        if (! preg_match('/^\s*([\d.]+)\s*([BKMGT]?)B?\s*$/i', (string) $value, $m)) {
            return 0;
        }

        $unit = strtoupper($m[2] ?: 'B');

        return (int) round(((float) $m[1]) * (self::UNITS[$unit] ?? 1));
    }

    public static function format(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        $units = ['K', 'M', 'G', 'T'];
        $i = -1;
        $n = $bytes;
        do {
            $n /= 1024;
            $i++;
        } while ($n >= 1024 && $i < count($units) - 1);

        return round($n, 1) . ' ' . $units[$i] . 'B';
    }
}
