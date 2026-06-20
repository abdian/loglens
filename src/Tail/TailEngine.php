<?php

namespace LogLens\Tail;

use LogLens\Contracts\LogSource;
use LogLens\Parsing\ParserManager;
use LogLens\Search\AstEvaluator;
use LogLens\Search\Node;
use LogLens\Search\QueryParser;
use LogLens\Sources\LocalFileSource;
use LogLens\Support\FileIdentity;

/**
 * Pure-PHP `tail -F` engine. No PCNTL, no shell-outs,
 * Windows-safe. Per tick it stats the file (clearstatcache), detects truncation
 * (size < cursor offset → restart at 0) and rotation (identity change →
 * re-resolve), caps reads, buffers partial trailing lines, and optionally
 * filters entries server-side with the search query language.
 */
class TailEngine
{
    private int $readCap;

    public function __construct(
        private LogSource $source,
        private ParserManager $parsers,
        private array $config = []
    ) {
        $this->readCap = (int) ($config['read_cap_bytes'] ?? 512 * 1024);
    }

    /** The cursor pointing at the current end of the file. */
    public function endCursor(string $fileId): ?Cursor
    {
        $identity = $this->identity($fileId);

        return $identity ? Cursor::fromIdentity($identity, $identity->size) : null;
    }

    /**
     * Read new entries since $cursor. Detects rotation/truncation and caps the
     * read window with jump-to-tail for large backlogs.
     *
     * @return array{entries:array,cursor:Cursor,rotated:bool,truncated:bool,eof:bool}
     */
    public function read(string $fileId, ?Cursor $cursor, ?string $query = null): array
    {
        $identity = $this->identity($fileId);
        if (! $identity) {
            return ['entries' => [], 'cursor' => new Cursor('', null, 0), 'rotated' => false, 'truncated' => false, 'eof' => true];
        }

        $size = $identity->size;
        $offset = $cursor?->offset ?? $size;
        $rotated = false;
        $truncated = false;

        if ($cursor && ! $cursor->matchesIdentity($identity)) {
            // Rotation/replacement → start from the beginning of the new file.
            $offset = 0;
            $rotated = true;
        } elseif ($offset > $size) {
            // External truncation → restart.
            $offset = 0;
            $truncated = true;
        }

        // Jump-to-tail for huge backlogs: never read more than the cap per tick.
        if ($size - $offset > $this->readCap) {
            $offset = $size - $this->readCap;
            // Align to the next line boundary so we don't emit a partial entry.
            $offset = $this->alignForward($fileId, $offset, $size);
        }

        if ($offset >= $size) {
            return ['entries' => [], 'cursor' => Cursor::fromIdentity($identity, $size), 'rotated' => $rotated, 'truncated' => $truncated, 'eof' => true];
        }

        $chunk = $this->source->readRange($fileId, $offset, $size - $offset);
        [$entries, $consumed] = $this->assemble($fileId, $chunk, $offset, $query);

        return [
            'entries' => $entries,
            'cursor' => Cursor::fromIdentity($identity, $offset + $consumed),
            'rotated' => $rotated,
            'truncated' => $truncated,
            'eof' => true,
        ];
    }

    /**
     * Assemble complete entries from a chunk, buffering any partial trailing
     * line (returns the consumed byte count so the cursor stops at the last
     * complete entry).
     *
     * @return array{0:array,1:int}
     */
    private function assemble(string $fileId, string $chunk, int $base, ?string $query): array
    {
        $parser = $this->parserFor($fileId);
        $ast = $query !== null && $query !== '' ? (new QueryParser())->parse($query) : null;
        $evaluator = $ast ? new AstEvaluator(false) : null;
        $name = $this->source instanceof LocalFileSource ? $this->source->stat($fileId)?->name : null;

        // Only consume up to the last newline (partial trailing line is buffered
        // for the next tick).
        $lastNl = strrpos($chunk, "\n");
        if ($lastNl === false) {
            return [[], 0];
        }
        $complete = substr($chunk, 0, $lastNl + 1);
        $consumed = strlen($complete);

        $entries = [];
        $lines = explode("\n", rtrim($complete, "\n"));
        $buffer = '';
        $entryOffset = $base;
        $runningOffset = $base;
        $have = false;

        $flush = function (string $raw, int $offset) use (&$entries, $parser, $ast, $evaluator, $name) {
            $parsed = $parser->parse($raw);
            if ($ast) {
                $ctx = [
                    'text' => $raw, 'level' => $parsed->level, 'ts' => $parsed->timestamp,
                    'channel' => $parsed->channel, 'env' => $parsed->environment, 'file' => $name,
                    'context' => array_merge($parsed->context ?? [], $parsed->extra ?? []),
                ];
                if (! $evaluator->matches($ast, $ctx)) {
                    return;
                }
            }
            $entries[] = ['offset' => $offset, 'parsed' => $parsed, 'raw' => $raw];
        };

        foreach ($lines as $line) {
            $lineBytes = strlen($line) + 1;
            if ($parser->isEntryStart($line)) {
                if ($have) {
                    $flush($buffer, $entryOffset);
                }
                $buffer = $line;
                $entryOffset = $runningOffset;
                $have = true;
            } elseif ($have) {
                $buffer .= "\n" . $line;
            } elseif (trim($line) !== '') {
                $buffer = $line;
                $entryOffset = $runningOffset;
                $have = true;
            }
            $runningOffset += $lineBytes;
        }
        if ($have) {
            $flush($buffer, $entryOffset);
        }

        return [$entries, $consumed];
    }

    private function alignForward(string $fileId, int $offset, int $size): int
    {
        $probe = $this->source->readRange($fileId, $offset, min(65536, $size - $offset));
        $nl = strpos($probe, "\n");

        return $nl === false ? $offset : $offset + $nl + 1;
    }

    private function identity(string $fileId): ?FileIdentity
    {
        if (! $this->source instanceof LocalFileSource) {
            return null;
        }
        clearstatcache();

        return $this->source->identity($fileId);
    }

    private function parserFor(string $fileId)
    {
        $head = $this->source->readRange($fileId, 0, 65536);
        $lines = array_slice(array_filter(preg_split('/\r?\n/', $head) ?: [], fn ($l) => $l !== ''), 0, 50);

        return $this->parsers->detect($lines);
    }
}
