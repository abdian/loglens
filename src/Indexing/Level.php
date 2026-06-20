<?php

namespace LogLens\Indexing;

/**
 * Compact severity ordinal (0..8) stored as a TINYINT in the index, with a
 * Unicode-safe name<->ordinal map covering PSR-3 / Monolog / syslog plus the
 * variants emitted by the non-Laravel formats (nginx "warn", fpm "NOTICE", …).
 */
final class Level
{
    public const DEBUG = 0;
    public const INFO = 1;
    public const NOTICE = 2;
    public const WARNING = 3;
    public const ERROR = 4;
    public const CRITICAL = 5;
    public const ALERT = 6;
    public const EMERGENCY = 7;
    public const UNKNOWN = 8;

    private const NAMES = [
        self::DEBUG => 'debug',
        self::INFO => 'info',
        self::NOTICE => 'notice',
        self::WARNING => 'warning',
        self::ERROR => 'error',
        self::CRITICAL => 'critical',
        self::ALERT => 'alert',
        self::EMERGENCY => 'emergency',
        self::UNKNOWN => 'unknown',
    ];

    private const ALIASES = [
        'debug' => self::DEBUG,
        'trace' => self::DEBUG,
        'info' => self::INFO,
        'information' => self::INFO,
        'informational' => self::INFO,
        'notice' => self::NOTICE,
        'warn' => self::WARNING,
        'warning' => self::WARNING,
        'error' => self::ERROR,
        'err' => self::ERROR,
        'fatal' => self::CRITICAL,
        'critical' => self::CRITICAL,
        'crit' => self::CRITICAL,
        'alert' => self::ALERT,
        'emergency' => self::EMERGENCY,
        'emerg' => self::EMERGENCY,
        'panic' => self::EMERGENCY,
    ];

    // Monolog integer level values -> ordinal.
    private const MONOLOG_INT = [
        100 => self::DEBUG,
        200 => self::INFO,
        250 => self::NOTICE,
        300 => self::WARNING,
        400 => self::ERROR,
        500 => self::CRITICAL,
        550 => self::ALERT,
        600 => self::EMERGENCY,
    ];

    public static function ordinal($value): int
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $i = (int) $value;

            return self::MONOLOG_INT[$i] ?? ($i >= 0 && $i <= 8 ? $i : self::UNKNOWN);
        }

        $key = strtolower(trim((string) $value));

        return self::ALIASES[$key] ?? self::UNKNOWN;
    }

    public static function name(int $ordinal): string
    {
        return self::NAMES[$ordinal] ?? 'unknown';
    }

    /** All ordinals satisfying a comparison like ">=warning". */
    public static function resolveComparison(string $op, string $name): array
    {
        $target = self::ordinal($name);
        $all = array_keys(self::NAMES);
        $all = array_filter($all, fn ($o) => $o <= self::EMERGENCY);

        return array_values(array_filter($all, function ($o) use ($op, $target) {
            switch ($op) {
                case '>=': return $o >= $target;
                case '>': return $o > $target;
                case '<=': return $o <= $target;
                case '<': return $o < $target;
                default: return $o === $target;
            }
        }));
    }

    public static function all(): array
    {
        $out = self::NAMES;
        unset($out[self::UNKNOWN]);

        return $out;
    }
}
