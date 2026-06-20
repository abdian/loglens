<?php

namespace LogLens\Parsing\Parsers;

use LogLens\Contracts\Parser;
use LogLens\Indexing\Level;
use LogLens\Parsing\ParsedEntry;
use LogLens\Support\Timestamp;
use LogLens\Support\Utf8;

/**
 * NDJSON / Monolog JsonFormatter parser. Auto-detects the shape by
 * sniffing a leading `{` plus the required Monolog keys, and tolerates the
 * datetime/level variants Monolog v2 and v3 emit.
 */
class JsonLinesParser implements Parser
{
    public function id(): string
    {
        return 'json';
    }

    public function detect(array $sampleLines): float
    {
        $hits = 0;
        $considered = 0;
        foreach ($sampleLines as $line) {
            $line = ltrim($line);
            if ($line === '' || $line[0] !== '{') {
                continue;
            }
            $considered++;
            $data = Utf8::jsonDecode($line);
            if ($data !== null && $this->looksMonolog($data)) {
                $hits++;
            }
        }
        if ($considered === 0) {
            return 0.0;
        }

        // Slightly under Laravel so a stray JSON line in a Laravel file doesn't
        // hijack detection; a real NDJSON file scores ~1.0.
        return min(0.99, $hits / max(1, $considered));
    }

    public function isEntryStart(string $line): bool
    {
        $line = ltrim(Utf8::stripBom($line));

        return isset($line[0]) && $line[0] === '{';
    }

    public function parse(string $raw): ParsedEntry
    {
        $raw = Utf8::stripBom(Utf8::sanitize($raw));
        $data = Utf8::jsonDecode(trim($raw));
        $entry = new ParsedEntry(raw: $raw);

        if ($data === null) {
            $entry->message = trim($raw);

            return $entry;
        }

        $entry->message = (string) ($data['message'] ?? $data['msg'] ?? '');
        $entry->channel = isset($data['channel']) ? (string) $data['channel'] : null;
        $entry->level = $this->resolveLevel($data);
        $entry->timestamp = $this->resolveTime($data);
        $entry->context = isset($data['context']) && is_array($data['context']) ? $data['context'] : null;
        $entry->extra = isset($data['extra']) && is_array($data['extra']) ? $data['extra'] : null;
        $entry->environment = isset($data['env']) ? (string) $data['env'] : null;

        // Structured exception (Monolog normalized) → fingerprint inputs.
        if (isset($data['context']['exception']) && is_array($data['context']['exception'])) {
            $ex = $data['context']['exception'];
            $entry->exceptionClass = $ex['class'] ?? null;
            $entry->exceptionMessage = $ex['message'] ?? null;
            $entry->throwFile = $ex['file'] ?? null;
            $entry->throwLine = isset($ex['line']) ? (int) $ex['line'] : null;
            if ($entry->throwFile) {
                $entry->appFrame = $entry->throwLine !== null
                    ? $entry->throwFile . ':' . $entry->throwLine
                    : $entry->throwFile;
            }
        }

        return $entry;
    }

    private function looksMonolog(array $data): bool
    {
        $hasMessage = isset($data['message']) || isset($data['msg']);
        $hasLevel = isset($data['level']) || isset($data['level_name']);
        $hasTime = isset($data['datetime']) || isset($data['time']) || isset($data['@timestamp']);

        return $hasMessage && ($hasLevel || $hasTime);
    }

    private function resolveLevel(array $data): int
    {
        if (isset($data['level_name'])) {
            return Level::ordinal($data['level_name']);
        }
        if (isset($data['level'])) {
            return Level::ordinal($data['level']);
        }

        return Level::INFO;
    }

    private function resolveTime(array $data)
    {
        $raw = $data['datetime'] ?? $data['time'] ?? $data['@timestamp'] ?? null;
        if (is_array($raw)) {
            $raw = $raw['date'] ?? null;
        }

        return $raw !== null ? Timestamp::parse((string) $raw) : null;
    }
}
