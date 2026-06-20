<?php

namespace LogLens\Support;

/**
 * UTF-8 resilience. Log content is
 * attacker-controlled and frequently carries malformed bytes; we substitute
 * rather than throw, and never let an encoding issue reach a 500.
 */
final class Utf8
{
    /** Replace invalid byte sequences with U+FFFD, leaving valid UTF-8 intact. */
    public static function sanitize(string $s): string
    {
        if ($s === '' || self::isValid($s)) {
            return $s;
        }

        // mbstring path (always available per composer constraints in practice;
        // guarded anyway).
        if (function_exists('mb_convert_encoding')) {
            $prev = mb_substitute_character();
            mb_substitute_character(0xFFFD);
            $out = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
            mb_substitute_character($prev);
            if ($out !== false) {
                return $out;
            }
        }

        // Last resort: drop invalid bytes.
        return preg_replace('/[\x80-\xFF]+/', "\u{FFFD}", $s) ?? $s;
    }

    /** Strip a leading UTF-8 BOM (some Windows tools prepend one to log files). */
    public static function stripBom(string $s): string
    {
        return (isset($s[0]) && $s[0] === "\xEF" && strncmp($s, "\xEF\xBB\xBF", 3) === 0)
            ? substr($s, 3)
            : $s;
    }

    public static function isValid(string $s): bool
    {
        return function_exists('mb_check_encoding')
            ? mb_check_encoding($s, 'UTF-8')
            : (bool) preg_match('//u', $s);
    }

    /**
     * json_encode flags that never throw on bad bytes. Used by every API
     * response so encoding can't produce a 500.
     */
    public static function jsonFlags(): int
    {
        return JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    }

    /** Decode JSON with substitution; returns null on structural failure. */
    public static function jsonDecode(string $s): ?array
    {
        $decoded = json_decode($s, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);

        return is_array($decoded) ? $decoded : null;
    }
}
