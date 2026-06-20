<?php

namespace LogLens\Fingerprint;

/**
 * Ordered-regex message normalizer.
 *
 * Produces a stable masked template used both as a group fingerprint input and
 * as the human-readable group title. Order matters: dates BEFORE IPs,
 * uuid/sha/md5 BEFORE generic hex. A digit-presence pre-gate lets constant
 * messages skip the whole regex pass (the common case).
 */
final class MessageTemplate
{
    private const CAP = 2048;

    /** @var array<int,array{0:string,1:string}> [pattern, replacement] in order */
    private static array $rules = [
        // SQL bindings / parameter markers
        ['/\b(?:values?\s*)?\([^()]*\?[^()]*\)/i', '(<bindings>)'],
        // ISO + common datetimes (before IPs so 14:30:00 isn\'t read as octets)
        ['/\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?/', '<date>'],
        ['/\d{1,2}\/\w{3}\/\d{4}:\d{2}:\d{2}:\d{2}/', '<date>'],
        // UUIDs
        ['/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i', '<uuid>'],
        // sha1 / md5 / long hex runs
        ['/\b[0-9a-f]{32,64}\b/i', '<hash>'],
        // emails
        ['/[\w.+-]+@[\w-]+\.[\w.-]+/', '<email>'],
        // URLs
        ['#https?://[^\s"\'<>]+#i', '<url>'],
        // IPv4 (after dates)
        ['/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/', '<ip>'],
        // file paths
        ['#(?:/[\w.\- ]+){2,}#', '<path>'],
        ['#[A-Za-z]:\\\\(?:[\w.\- ]+\\\\?){2,}#', '<path>'],
        // model id pattern: [App\Order] 4211
        ['/(\[[\w\\\\]+\])\s+\d+/', '$1 <num>'],
        // quoted strings
        ['/"[^"]*"/', '<str>'],
        ["/'[^']*'/", '<str>'],
        // remaining hex
        ['/\b0x[0-9a-f]+\b/i', '<hex>'],
        // any remaining run of digits (dates/uuids/hashes/ips already consumed
        // above). The old 1–7 bound left 8+ digit values — memory sizes, large
        // auto-increment IDs, epoch-millis, byte counts — literal, splitting one
        // error into many fingerprints.
        ['/\b\d+\b/', '<num>'],
    ];

    public static function normalize(string $message): string
    {
        $message = trim($message);
        if (strlen($message) > self::CAP) {
            $message = substr($message, 0, self::CAP);
        }

        // Digit pre-gate: a message with no digits has nothing variable to mask.
        if (! preg_match('/\d/', $message)) {
            return $message;
        }

        foreach (self::$rules as [$pattern, $replacement]) {
            $out = @preg_replace($pattern, $replacement, $message);
            if ($out !== null) {
                $message = $out;
            }
        }

        return $message;
    }
}
