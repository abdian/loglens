<?php

namespace LogLens\Parsing\Parsers;

use LogLens\Contracts\Parser;
use LogLens\Indexing\Level;
use LogLens\Parsing\ParsedEntry;
use LogLens\Support\Timestamp;
use LogLens\Support\Utf8;

/**
 * Configurable single-line-format parser. One PCRE with named groups
 * (`datetime`, `level`, `message`, optional `channel`) drives both the
 * built-in opcodes-parity formats and declarative custom formats from config
 *. Lines that don't match the entry-start pattern are treated
 * as continuations of the previous entry by the indexer.
 */
class RegexParser implements Parser
{
    /**
     * @param  array<string,string>  $levelMap  raw token => canonical level name
     */
    public function __construct(
        private string $id,
        private string $pattern,
        private array $levelMap = [],
        private string $defaultLevel = 'info',
        private ?string $firstByte = null
    ) {
    }

    public function id(): string
    {
        return $this->id;
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
            if (@preg_match($this->pattern, $line)) {
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
        if ($this->firstByte !== null && (! isset($line[0]) || strpos($this->firstByte, $line[0]) === false)) {
            return false;
        }

        return (bool) @preg_match($this->pattern, $line);
    }

    public function parse(string $raw): ParsedEntry
    {
        $raw = Utf8::stripBom(Utf8::sanitize($raw));
        $nl = strpos($raw, "\n");
        $firstLine = $nl === false ? $raw : substr($raw, 0, $nl);

        $entry = new ParsedEntry(raw: $raw);

        if (! @preg_match($this->pattern, $firstLine, $m)) {
            $entry->message = trim($raw);

            return $entry;
        }

        $entry->timestamp = isset($m['datetime']) ? Timestamp::parse($m['datetime']) : null;
        $entry->level = $this->mapLevel($m['level'] ?? null);
        $entry->channel = isset($m['channel']) && $m['channel'] !== '' ? $m['channel'] : null;

        $message = $m['message'] ?? $firstLine;
        // Append continuation lines (multi-line tracebacks etc.) to the message.
        if ($nl !== false) {
            $message .= substr($raw, $nl);
        }
        $entry->message = rtrim($message);

        return $entry;
    }

    private function mapLevel(?string $token): int
    {
        if ($token === null || $token === '') {
            return Level::ordinal($this->defaultLevel);
        }
        $mapped = $this->levelMap[$token] ?? $this->levelMap[strtolower($token)] ?? $token;

        return Level::ordinal($mapped);
    }
}
