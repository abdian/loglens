<?php

namespace LogLens\Indexing;

use LogLens\Contracts\LogSource;
use LogLens\Parsing\ParsedEntry;
use LogLens\Parsing\ParserManager;
use LogLens\Sources\LocalFileSource;

/**
 * Index-free newest-page reader.
 *
 * Reads backward in ~1 MB chunks from EOF, locating entry-start lines, so the
 * UI can paint the latest entries in milliseconds even on a multi-GB file that
 * has never been indexed. The 1 GB perf test asserts < 100 ms.
 *
 * @phpstan-type TailEntry array{offset:int,length:int,raw:string,entry:ParsedEntry}
 */
class TailReader
{
    private int $chunk = 1048576; // 1 MB

    public function __construct(
        private LogSource $source,
        private ParserManager $parsers
    ) {
    }

    /**
     * @return array{entries:array<int,array>,parser:string}
     */
    public function newestPage(string $fileId, int $limit = 100): array
    {
        $stat = $this->source instanceof LocalFileSource ? $this->source->stat($fileId) : null;
        $size = $stat?->size ?? 0;
        $parser = $this->parsers->detect($this->sample($fileId));

        if ($size === 0) {
            return ['entries' => [], 'parser' => $parser->id()];
        }

        // gz: can't seek backward; read forward and keep the tail.
        if ($stat && $stat->compressed) {
            return $this->forwardTail($fileId, $limit, $parser);
        }

        $pos = $size;
        $buffer = '';
        $bufBase = $size;

        // Accumulate backward until we have at least limit+1 entry starts.
        $starts = [];
        $guard = 0;
        while ($pos > 0 && count($starts) <= $limit && $guard < 4096) {
            $guard++;
            $readStart = max(0, $pos - $this->chunk);
            $chunk = $this->source->readRange($fileId, $readStart, $pos - $readStart);
            $buffer = $chunk . $buffer;
            $bufBase = $readStart;
            $pos = $readStart;

            $starts = $this->findStarts($buffer, $bufBase, $parser);
        }

        // Build entries from consecutive starts; last start runs to EOF.
        $entries = [];
        $count = count($starts);
        $sliceFrom = max(0, $count - $limit);
        for ($i = $sliceFrom; $i < $count; $i++) {
            $start = $starts[$i];
            $end = $i + 1 < $count ? $starts[$i + 1] : $size;
            $relStart = $start - $bufBase;
            $raw = rtrim(substr($buffer, $relStart, $end - $start), "\r\n");
            $entries[] = [
                'offset' => $start,
                'length' => $end - $start,
                'raw' => $raw,
                'entry' => $parser->parse($raw),
            ];
        }

        return ['entries' => $entries, 'parser' => $parser->id()];
    }

    /**
     * @return array<int,array>
     */
    private function findStarts(string $buffer, int $base, $parser): array
    {
        $starts = [];
        $offset = $base;
        $len = strlen($buffer);
        $lineStart = 0;
        for ($i = 0; $i <= $len; $i++) {
            if ($i === $len || $buffer[$i] === "\n") {
                $line = rtrim(substr($buffer, $lineStart, $i - $lineStart), "\r");
                if ($line !== '' && $parser->isEntryStart($line)) {
                    $starts[] = $base + $lineStart;
                }
                $lineStart = $i + 1;
            }
        }

        return $starts;
    }

    private function forwardTail(string $fileId, int $limit, $parser): array
    {
        // gz can't seek backward, but we only need the LAST $limit entries — so
        // stream forward keeping just the most recent (limit+1) entry starts and
        // a sliding byte buffer trimmed to the earliest kept start. Memory stays
        // bounded by the tail's size, not the whole decompressed file.
        $stream = $this->source instanceof LocalFileSource ? $this->source->open($fileId, 0) : null;
        if (! $stream) {
            return ['entries' => [], 'parser' => $parser->id()];
        }

        $offset = 0;
        $starts = [];     // absolute decompressed offsets of recent entry starts
        $tail = '';       // sliding window of decompressed bytes
        $tailBase = 0;    // absolute offset of $tail[0]

        while (($line = fgets($stream)) !== false) {
            $probe = rtrim($line, "\r\n");
            if ($probe !== '' && $parser->isEntryStart($probe)) {
                $starts[] = $offset;
                if (count($starts) > $limit + 1) {
                    array_shift($starts);
                    $drop = $starts[0] - $tailBase;
                    if ($drop > 0) {
                        $tail = substr($tail, $drop);
                        $tailBase = $starts[0];
                    }
                }
            }
            $tail .= $line;
            $offset += strlen($line);
        }
        fclose($stream);

        $total = $offset;
        $entries = [];
        $count = count($starts);
        $sliceFrom = max(0, $count - $limit);
        for ($i = $sliceFrom; $i < $count; $i++) {
            $start = $starts[$i];
            $end = $i + 1 < $count ? $starts[$i + 1] : $total;
            $raw = rtrim(substr($tail, $start - $tailBase, $end - $start), "\r\n");
            $entries[] = [
                'offset' => $start,
                'length' => $end - $start,
                'raw' => $raw,
                'entry' => $parser->parse($raw),
            ];
        }

        return ['entries' => $entries, 'parser' => $parser->id()];
    }

    private function sample(string $fileId): array
    {
        $head = $this->source->readRange($fileId, 0, 65536);
        $lines = preg_split('/\r?\n/', $head) ?: [];

        return array_slice(array_filter($lines, fn ($l) => $l !== ''), 0, 50);
    }
}
