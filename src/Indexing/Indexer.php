<?php

namespace LogLens\Indexing;

use LogLens\Contracts\IndexStore;
use LogLens\Contracts\LogSource;
use LogLens\Fingerprint\FingerprintEngine;
use LogLens\Parsing\ParserManager;
use LogLens\Sources\LocalFileSource;
use LogLens\Support\FileIdentity;

/**
 * Single-pass streaming indexer.
 *
 * Streams the (appended) byte range at constant memory, assembling multi-line
 * entries via the parser's isEntryStart pre-check, computing fingerprints and
 * stats in the same pass, and writing in batched transactions. Detects
 * truncation / rotation / copytruncate and reindexes when needed.
 */
class Indexer
{
    private int $batchSize;

    private int $titleCap = 200;

    private int $ftsCap = 16384;

    public function __construct(
        private LogSource $source,
        private ParserManager $parsers,
        private FingerprintEngine $fingerprints,
        private IndexManager $manager,
        private array $config = []
    ) {
        $this->batchSize = (int) ($config['batch_size'] ?? 2000);
    }

    /**
     * Index a file fully (or resume). Returns a small status array.
     *
     * @param  array{budget_ms?:int}  $opts
     * @return array{state:string, indexed:int, entries:int, driver:string}
     */
    public function index(string $fileId, array $opts = []): array
    {
        $identity = $this->source instanceof LocalFileSource
            ? $this->source->identity($fileId)
            : null;
        if (! $identity) {
            return ['state' => 'none', 'indexed' => 0, 'entries' => 0, 'driver' => $this->manager->driverName()];
        }

        // Serialize indexing per file. Two concurrent slices (e.g. two browser
        // requests for an un-indexed file, or an in-request slice racing a queued
        // job/CLI) would both resume from the same offset and re-emit the same
        // seqs, duplicating entries and corrupting the sequence. A non-blocking
        // advisory lock lets the loser report current state instead of racing.
        $lock = $this->acquireLock($identity);
        if ($lock === null) {
            $store = $this->manager->store($identity);

            return $this->status($store, $identity->size, $identity->key());
        }

        try {
            return $this->runIndex($fileId, $identity, $opts);
        } finally {
            $this->releaseLock($lock);
        }
    }

    /**
     * @return resource|null  a held lock handle, false to proceed unlocked
     *                        (best-effort), or null when another holder is active.
     */
    private function acquireLock(FileIdentity $identity)
    {
        $dir = (string) ($this->config['directory'] ?? sys_get_temp_dir());
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $path = $dir . DIRECTORY_SEPARATOR . $identity->key() . '.lock';
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            return false; // can't create a lock file → degrade to unlocked
        }
        if (! @flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null; // held by another indexer → caller should skip
        }

        return $handle;
    }

    /** @param  resource|false  $handle */
    private function releaseLock($handle): void
    {
        if (is_resource($handle)) {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function runIndex(string $fileId, FileIdentity $identity, array $opts): array
    {
        $store = $this->manager->store($identity);
        $fileSize = $identity->size;

        $lastOffset = $this->resolveResumePoint($store, $identity, $fileSize);

        if ($lastOffset >= $fileSize) {
            $store->setLastIndexedOffset($fileSize);
            $this->finish($store);

            return $this->status($store, $fileSize, $identity->key());
        }

        $parser = $this->resolveParser($store, $fileId);
        $store->setMeta('parser', $parser->id());

        $deadline = isset($opts['budget_ms']) ? microtime(true) + $opts['budget_ms'] / 1000 : null;

        $stream = $this->source->open($fileId, $lastOffset);
        if (! $stream) {
            $this->finish($store);

            return $this->status($store, $fileSize, $identity->key());
        }

        $offset = $lastOffset;
        if ($lastOffset === 0) {
            $offset = $this->skipLeadingNuls($stream, $offset);
        }

        $seq = $store->maxSeq();
        $store->beginBatch();

        $buffer = '';
        $entryStart = $offset;
        $haveEntry = false;
        $lastLineTerminated = true;
        $written = 0;
        $batchCounter = 0;

        while (($line = fgets($stream)) !== false) {
            $lineBytes = strlen($line);
            $lastLineTerminated = substr($line, -1) === "\n";
            $probe = rtrim($line, "\r\n");

            if ($parser->isEntryStart($probe)) {
                if ($haveEntry) {
                    $seq = $this->flush($store, $parser, $seq, $buffer, $entryStart, $offset - $entryStart);
                    $written++;
                    if (++$batchCounter >= $this->batchSize) {
                        $store->commitBatch();
                        // The just-flushed entry covered [entryStart, offset);
                        // $offset (the current entry-start line) is the
                        // high-water mark of fully-indexed bytes. Resuming from
                        // $entryStart would re-read and DUPLICATE that entry.
                        $store->setLastIndexedOffset($offset);
                        $store->beginBatch();
                        $batchCounter = 0;
                        if ($deadline !== null && microtime(true) >= $deadline) {
                            // Bounded in-request slice: stop cleanly, resume later.
                            $store->setLastIndexedOffset($offset);
                            break;
                        }
                    }
                }
                $buffer = $line;
                $entryStart = $offset;
                $haveEntry = true;
            } elseif ($haveEntry) {
                $buffer .= $line;
            } elseif (trim($probe) !== '') {
                // No entry-start matched yet but content exists — start an entry
                // so unstructured logs aren't dropped.
                $buffer = $line;
                $entryStart = $offset;
                $haveEntry = true;
            }

            $offset += $lineBytes;
        }

        // Trailing entry: flush only if cleanly terminated (not mid-write).
        if ($haveEntry && $lastLineTerminated && ($deadline === null || microtime(true) < $deadline)) {
            $this->flush($store, $parser, $seq, $buffer, $entryStart, $offset - $entryStart);
            $store->setLastIndexedOffset($offset);
        } elseif ($haveEntry) {
            // Hold the incomplete trailing entry; resume from its start.
            $store->setLastIndexedOffset($entryStart);
        } else {
            $store->setLastIndexedOffset($offset);
        }

        fclose($stream);
        $store->commitBatch();
        $this->finish($store);

        return $this->status($store, $fileSize, $identity->key());
    }

    /**
     * Detect truncation / rotation and decide where to resume from. Resets the
     * store when the file is no longer an append-only continuation.
     */
    private function resolveResumePoint(IndexStore $store, FileIdentity $identity, int $fileSize): int
    {
        $snapshot = $store->getMeta('identity');
        $lastOffset = $store->lastIndexedOffset();

        $truncated = $fileSize < $lastOffset;
        $rotated = $identity->differsFrom($snapshot);

        if ($truncated || $rotated) {
            $store->reset();
            $store->setMeta('identity', $identity->toArray());

            return 0;
        }

        if ($snapshot === null) {
            $store->setMeta('identity', $identity->toArray());
        }

        return $lastOffset;
    }

    private function resolveParser(IndexStore $store, string $fileId)
    {
        $existing = $store->getMeta('parser');
        if ($existing) {
            return $this->parsers->get($existing) ?? $this->parsers->detect($this->sample($fileId));
        }

        return $this->parsers->detect($this->sample($fileId));
    }

    private function sample(string $fileId): array
    {
        $head = $this->source->readRange($fileId, 0, 65536);
        $lines = preg_split('/\r?\n/', $head) ?: [];

        return array_slice(array_filter($lines, fn ($l) => $l !== ''), 0, 50);
    }

    private function skipLeadingNuls($stream, int $offset): int
    {
        $pos = ftell($stream);
        while (! feof($stream)) {
            $chunk = fread($stream, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $trimmed = ltrim($chunk, "\0");
            if ($trimmed === $chunk) {
                // No leading NULs in this chunk: rewind to chunk start and stop.
                fseek($stream, $pos);
                break;
            }
            $skipped = strlen($chunk) - strlen($trimmed);
            $offset += $skipped;
            if ($trimmed !== '') {
                fseek($stream, $pos + $skipped);
                break;
            }
            $pos += strlen($chunk);
        }

        return $offset;
    }

    private function flush(IndexStore $store, $parser, int $seq, string $raw, int $offset, int $length): int
    {
        $seq++;
        $entry = $parser->parse($raw);
        $fp = $this->fingerprints->compute($entry);

        $title = $this->makeTitle($entry->message);
        $searchText = self::makeSearchText($entry, $this->ftsCap);

        $store->append(new IndexedEntry(
            seq: $seq,
            offset: $offset,
            length: $length,
            timestamp: $entry->timestamp,
            level: $entry->level,
            fpApp: $fp['app'],
            fpSys: $fp['sys'],
            title: $title,
            searchText: $searchText
        ));

        return $seq;
    }

    private function makeTitle(string $message): string
    {
        $line = $message;
        $nl = strpos($line, "\n");
        if ($nl !== false) {
            $line = substr($line, 0, $nl);
        }

        return mb_substr($line, 0, $this->titleCap);
    }

    /**
     * The synthetic search-metadata prefix (timestamp + level name + channel +
     * environment) prepended to a entry's searchable text. Exposed so the
     * search confirmation layer (AstEvaluator, via SearchEngine) can match
     * against the SAME superset the FTS index was built from — otherwise a
     * free-text query for a level/date/channel finds FTS candidates that the
     * evaluator would then wrongly reject. Single source of truth.
     */
    public static function searchMetaPrefix(\LogLens\Parsing\ParsedEntry $entry): string
    {
        $meta = '';
        if ($entry->timestamp !== null) {
            // Both date and time, space-separated, so "2026-06-21" and "00:21"
            // each tokenize cleanly for the trigram FTS index.
            $meta .= gmdate('Y-m-d H:i:s', $entry->timestamp) . ' ';
        }
        $meta .= Level::name($entry->level) . ' ';
        if ($entry->channel) {
            $meta .= $entry->channel . ' ';
        }
        if ($entry->environment) {
            $meta .= $entry->environment . ' ';
        }

        return $meta;
    }

    /**
     * Build the full FTS search text for an entry. Static + public so the
     * parallel SegmentIndexer builds byte-for-byte identical text — otherwise a
     * segmented (large-file) index would silently lose level/date/channel/context
     * and exceptionMessage from its FTS rows. Single source of truth.
     */
    public static function makeSearchText(\LogLens\Parsing\ParsedEntry $entry, int $cap = 16384): string
    {
        // Keep the metadata and exception identifiers at the FRONT so they are
        // never the first thing evicted by the cap truncation — they are the
        // highest-value search terms.
        $text = self::searchMetaPrefix($entry);
        if ($entry->exceptionClass) {
            $text .= $entry->exceptionClass . ' ' . ($entry->exceptionMessage ?? '') . ' ';
        }
        $text .= $entry->message;
        if ($entry->context) {
            $text .= ' ' . json_encode($entry->context, JSON_INVALID_UTF8_SUBSTITUTE);
        }

        return substr($text, 0, $cap);
    }

    private function finish(IndexStore $store): void
    {
        // Roll up groups when the store supports it (SQLite). Cheap on a single
        // GROUP BY; binary store has no groups table.
        if ($store instanceof SqliteIndexStore) {
            $store->rebuildGroups();
        }
        if (method_exists($store, 'touch')) {
            $store->touch();
        }
    }

    private function status(IndexStore $store, int $fileSize, string $key): array
    {
        $state = $this->manager->indexState($store, $fileSize);

        return [
            'state' => $state['state'],
            'percent' => $state['percent'],
            'indexed' => $store->lastIndexedOffset(),
            'entries' => $store->count(),
            'driver' => $store->driver(),
            'key' => $key,
        ];
    }
}
