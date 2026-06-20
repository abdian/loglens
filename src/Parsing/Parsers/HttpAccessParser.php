<?php

namespace LogLens\Parsing\Parsers;

use LogLens\Contracts\Parser;
use LogLens\Indexing\Level;
use LogLens\Parsing\ParsedEntry;
use LogLens\Support\Timestamp;
use LogLens\Support\Utf8;

/**
 * Apache/Nginx combined access log parser. There is no level token, so
 * severity is derived from the HTTP status (5xx → error, 4xx → warning).
 */
class HttpAccessParser implements Parser
{
    private const RE = '#^(?<ip>\S+)\s+\S+\s+\S+\s+\[(?<datetime>[^\]]+)\]\s+"(?<request>[^"]*)"\s+(?<status>\d{3})\s+(?<bytes>\S+)(?:\s+"(?<referer>[^"]*)"\s+"(?<agent>[^"]*)")?#';

    public function id(): string
    {
        return 'http_access';
    }

    public function detect(array $sampleLines): float
    {
        $hits = 0;
        $considered = 0;
        foreach ($sampleLines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $considered++;
            if (preg_match(self::RE, $line)) {
                $hits++;
            }
        }

        return $considered === 0 ? 0.0 : min(0.97, $hits / max(1, $considered));
    }

    public function isEntryStart(string $line): bool
    {
        if (isset($line[0]) && $line[0] === "\xEF") {
            $line = Utf8::stripBom($line);
        }

        return (bool) preg_match(self::RE, $line);
    }

    public function parse(string $raw): ParsedEntry
    {
        $raw = Utf8::stripBom(Utf8::sanitize($raw));
        $entry = new ParsedEntry(raw: $raw);

        if (! preg_match(self::RE, $raw, $m)) {
            $entry->message = trim($raw);

            return $entry;
        }

        $status = (int) $m['status'];
        $entry->timestamp = Timestamp::parse($m['datetime']);
        $entry->level = $status >= 500 ? Level::ERROR : ($status >= 400 ? Level::WARNING : Level::INFO);
        $entry->message = trim($m['request']) . ' ' . $status . ($m['bytes'] !== '-' ? ' ' . $m['bytes'] . 'b' : '');
        $entry->context = array_filter([
            'ip' => $m['ip'],
            'status' => $status,
            'bytes' => $m['bytes'],
            'referer' => $m['referer'] ?? null,
            'agent' => $m['agent'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        return $entry;
    }
}
