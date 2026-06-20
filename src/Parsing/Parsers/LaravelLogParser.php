<?php

namespace LogLens\Parsing\Parsers;

use LogLens\Contracts\Parser;
use LogLens\Indexing\Level;
use LogLens\Parsing\JsonTailScanner;
use LogLens\Parsing\ParsedEntry;
use LogLens\Support\Timestamp;
use LogLens\Support\Utf8;

/**
 * Laravel/Monolog LineFormatter parser.
 *
 *   [2026-06-13 14:30:00] production.ERROR: message {"ctx":1} {"shared":2}
 *
 * Handles timestamp variants, env.LEVEL tokens, multi-line exception blocks
 * (`[object] (Class(code): msg at file:line)` + `[stacktrace]`), top-app-frame
 * resolution, and the dual adjacent JSON tails of Laravel 11+.
 */
class LaravelLogParser implements Parser
{
    /** Entry-start: "[<datetime>] env.LEVEL: ..." */
    private const HEADER = '/^\[(?<ts>\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:[+-]\d{2}:?\d{2}|Z)?)\]\s+(?:(?<env>[\w-]+)\.)?(?<level>DEBUG|INFO|NOTICE|WARNING|ERROR|CRITICAL|ALERT|EMERGENCY)(?::)?\s*(?<rest>.*)$/su';

    private array $vendorMarkers;

    public function __construct(array $vendorMarkers = ['/vendor/', '\\vendor\\'])
    {
        $this->vendorMarkers = $vendorMarkers;
    }

    public function id(): string
    {
        return 'laravel';
    }

    public function detect(array $sampleLines): float
    {
        $hits = 0;
        $considered = 0;
        foreach ($sampleLines as $line) {
            if ($line === '' || $line[0] !== '[') {
                continue;
            }
            $considered++;
            if (preg_match(self::HEADER, $line)) {
                $hits++;
            }
        }

        return $considered === 0 ? 0.0 : min(1.0, $hits / max(1, $considered));
    }

    public function isEntryStart(string $line): bool
    {
        if (isset($line[0]) && $line[0] === "\xEF") {
            $line = Utf8::stripBom($line);
        }

        // Cheap pre-check before PCRE.
        return isset($line[0]) && $line[0] === '[' && (bool) preg_match(self::HEADER, $line);
    }

    public function parse(string $raw): ParsedEntry
    {
        $raw = Utf8::stripBom(Utf8::sanitize($raw));
        $nl = strpos($raw, "\n");
        $firstLine = $nl === false ? $raw : substr($raw, 0, $nl);

        $entry = new ParsedEntry(raw: $raw);

        if (! preg_match(self::HEADER, $firstLine, $m)) {
            // Orphan/continuation handed in standalone: treat the whole thing as
            // an unparsed info message rather than dropping it.
            $entry->message = trim($raw);

            return $entry;
        }

        $entry->timestamp = Timestamp::parse($m['ts']);
        $entry->environment = $m['env'] ?? null;
        $entry->channel = $m['env'] ?? null;
        $entry->level = Level::ordinal($m['level']);
        $rest = $m['rest'];

        $this->extractException($raw, $entry);

        if ($entry->isException()) {
            // Exception JSON spans multiple lines; cut the visible message at the
            // `{"exception"` marker precisely (cutting at a generic ` {"` would
            // truncate prose that legitimately contains an inline object).
            $cut = strpos($rest, '{"exception"');
            $entry->message = $cut === false ? rtrim($rest) : rtrim(substr($rest, 0, $cut));
            if ($entry->message === '' && $entry->exceptionMessage) {
                $entry->message = $entry->exceptionMessage;
            }
        } else {
            $split = JsonTailScanner::split($rest);
            $entry->message = $split['message'];
            $entry->context = $split['context'];
            $entry->extra = $split['extra'];
        }

        return $entry;
    }

    private function extractException(string $raw, ParsedEntry $entry): void
    {
        $stackPos = strpos($raw, '[stacktrace]');

        // [object] (RuntimeException(code: 0): message at /app/Service.php:42)
        // The header is single-line and always precedes the stacktrace, so bound
        // the (greedy) match to that prefix and keep both the message and the file
        // within one line ([^\n]) — otherwise a malformed entry with no well-formed
        // " at <file>:<line>)" terminator backtracks across the whole stack (ReDoS).
        // The greedy message still tolerates a literal " at " in the text because
        // backtracking stays within the single header line.
        $head = $stackPos !== false ? substr($raw, 0, $stackPos) : substr($raw, 0, 8192);
        if (preg_match('/\[object\]\s+\((?<class>[\w\\\\]+)\((?:code:\s*)?(?<code>-?\d+)\)(?::\s*(?<msg>[^\n]*))?\s+at\s+(?<file>[^\n]+?):(?<line>\d+)\)/u', $head, $em)) {
            $entry->exceptionClass = $em['class'];
            $entry->exceptionMessage = isset($em['msg']) ? trim($em['msg']) : null;
            $entry->throwFile = $em['file'];
            $entry->throwLine = (int) $em['line'];
        }

        if ($stackPos === false) {
            // Still resolve an app frame from the throw site if we have one.
            if ($entry->throwFile !== null) {
                $entry->appFrame = $entry->throwFile . ':' . $entry->throwLine;
                if ($entry->exceptionClass === null) {
                    return;
                }
            }

            return;
        }

        $block = substr($raw, $stackPos + strlen('[stacktrace]'));
        $entry->frames = $this->parseFrames($block);
        $entry->appFrame = $this->topAppFrame($entry->frames)
            ?? ($entry->throwFile !== null ? $entry->throwFile . ':' . $entry->throwLine : null);
    }

    /**
     * Parse "#0 /path/File.php(42): Class->method()" frames.
     *
     * @return array<int,array{file:string,line:?int,call:?string,vendor:bool}>
     */
    private function parseFrames(string $block): array
    {
        $frames = [];
        foreach (preg_split('/\r?\n/', $block) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] !== '#') {
                continue;
            }
            if (preg_match('/^#\d+\s+(?<file>.+?)\((?<line>\d+)\):\s*(?<call>.*)$/', $line, $fm)) {
                $frames[] = [
                    'file' => $fm['file'],
                    'line' => (int) $fm['line'],
                    'call' => $fm['call'] !== '' ? $fm['call'] : null,
                    'vendor' => $this->isVendor($fm['file']),
                ];
            } elseif (preg_match('/^#\d+\s+(?<call>\{main\}|\[internal function\].*)$/', $line, $fm)) {
                $frames[] = ['file' => $fm['call'], 'line' => null, 'call' => null, 'vendor' => true];
            }
        }

        return $frames;
    }

    private function topAppFrame(array $frames): ?string
    {
        foreach ($frames as $frame) {
            if (! $frame['vendor'] && $frame['line'] !== null) {
                return $frame['file'] . ':' . $frame['line'];
            }
        }

        return null;
    }

    private function isVendor(string $file): bool
    {
        foreach ($this->vendorMarkers as $marker) {
            if (strpos($file, $marker) !== false) {
                return true;
            }
        }

        return false;
    }
}
