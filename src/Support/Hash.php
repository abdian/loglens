<?php

namespace LogLens\Support;

/**
 * Single point of indirection for the fast 64-bit hash used by file identity
 * and error fingerprints.
 *
 * xxh3 ships in hash() on PHP >= 8.1. On 8.0 we fall back to a composite of
 * crc32c + fnv1a64, which is fast, well-distributed, and deterministic. The
 * algorithm id is versioned in index meta so an upgrade just triggers reindex.
 */
final class Hash
{
    private static ?string $algo = null;

    public static function algo(): string
    {
        if (self::$algo === null) {
            $algos = hash_algos();
            if (in_array('xxh3', $algos, true)) {
                self::$algo = 'xxh3';
            } elseif (in_array('crc32c', $algos, true)) {
                self::$algo = 'composite-crc32c-fnv1a64';
            } else {
                self::$algo = 'crc32b-fnv';
            }
        }

        return self::$algo;
    }

    /** Force a specific algorithm (tests / config). */
    public static function setAlgo(?string $algo): void
    {
        self::$algo = $algo;
    }

    /**
     * Return a signed 64-bit integer hash of $data (fits a SQLite INTEGER /
     * pack('q')). Deterministic and stable across machines.
     */
    public static function int64(string $data): int
    {
        switch (self::algo()) {
            case 'xxh3':
                $hex = hash('xxh3', $data); // 16 hex chars = 64 bits
                break;
            case 'composite-crc32c-fnv1a64':
                $hex = hash('crc32c', $data) . hash('fnv1a64', $data); // 8 + 16, take low 16
                $hex = substr($hex, -16);
                break;
            default:
                $hex = hash('crc32b', $data) . hash('fnv164', $data);
                $hex = substr($hex, -16);
        }

        return self::hexToSignedInt64($hex);
    }

    /** Hex digest (for head-fingerprints stored as text in meta). */
    public static function hex(string $data): string
    {
        return self::algo() === 'xxh3' ? hash('xxh3', $data) : hash('crc32c', $data) . hash('fnv1a64', $data);
    }

    private static function hexToSignedInt64(string $hex): int
    {
        $hex = str_pad(substr($hex, 0, 16), 16, '0', STR_PAD_LEFT);

        if (PHP_INT_SIZE >= 8) {
            // Reinterpret the 64-bit pattern as a two's-complement signed int
            // using integer ops only. We must NOT route through hexdec() on the
            // full 16 chars: above PHP_INT_MAX it returns a float, and casting
            // that float back to int is lossy — and on PHP 8.5 raises a warning
            // ("The float ... is not representable as an int, cast occurred")
            // that Laravel promotes to an exception under the test harness.
            // Each 8-hex half is <= 0xFFFFFFFF, so it always stays an int.
            $high = (int) hexdec(substr($hex, 0, 8)); // top 32 bits
            $low = (int) hexdec(substr($hex, 8, 8));  // low 32 bits

            // A high bit set in $high naturally overflows into the sign bit,
            // yielding the correct negative value (shift is modular on ints).
            return ($high << 32) | $low;
        }

        // 32-bit PHP: clamp to a stable 31-bit value.
        return (int) (hexdec(substr($hex, -8)) & 0x7fffffff);
    }
}
