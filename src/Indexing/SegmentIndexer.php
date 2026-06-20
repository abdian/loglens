<?php

namespace LogLens\Indexing;

use LogLens\Fingerprint\FingerprintEngine;
use LogLens\Parsing\ParserManager;
use LogLens\Sources\LocalFileSource;
use LogLens\Support\FileIdentity;
use PDO;

/**
 * Parallel segment indexing.
 *
 * Splits a large file into byte-range segments snapped to entry boundaries,
 * indexes each into a per-segment SQLite file (concurrently when workers run),
 * then ATTACH-merges them into the canonical index. runSync() produces an
 * index byte-for-byte equivalent to the single-pass indexer (equivalence test).
 */
class SegmentIndexer
{
    private int $segmentSize;

    public function __construct(
        private LocalFileSource $source,
        private IndexManager $manager,
        private ParserManager $parsers,
        private FingerprintEngine $fingerprints,
        private array $config = []
    ) {
        $this->segmentSize = (int) ($config['segment_size'] ?? 64 * 1024 * 1024);
    }

    /**
     * Offload the segmented index to a queue worker. Only ever called by the
     * coordinator when a live worker is detected; runs synchronously only when
     * no queue/job class is available (e.g. tests, the equivalence harness).
     */
    public function dispatch(string $fileId, FileIdentity $identity): void
    {
        if (class_exists(\LogLens\Indexing\Jobs\IndexSegmentsJob::class) && function_exists('dispatch')
            && function_exists('config') && (string) config('queue.default') !== 'sync') {
            \LogLens\Indexing\Jobs\IndexSegmentsJob::dispatch($fileId);

            return;
        }

        $this->runSync($fileId, $identity);
    }

    public function runSync(string $fileId, FileIdentity $identity): array
    {
        if (! is_dir($this->manager->directory())) {
            @mkdir($this->manager->directory(), 0775, true);
        }

        // Share the single-writer lock with the single-pass Indexer so a segment
        // merge and an in-request slice never write the same store concurrently.
        $lock = $this->acquireLock($identity);
        if ($lock === null) {
            $store = $this->manager->store($identity);

            return ['state' => 'building', 'entries' => $store->count(), 'driver' => 'sqlite', 'segments' => 0];
        }

        try {
            return $this->runSyncLocked($fileId, $identity);
        } finally {
            $this->releaseLock($lock);
        }
    }

    private function runSyncLocked(string $fileId, FileIdentity $identity): array
    {
        $size = $identity->size;
        $parser = $this->detectParser($fileId);
        $fingerprints = $this->fingerprints;

        $boundaries = $this->boundaries($fileId, $size, $parser);
        $segmentFiles = [];

        foreach ($boundaries as $i => [$start, $end]) {
            $segPath = $this->manager->directory() . DIRECTORY_SEPARATOR . $identity->key() . ".seg{$i}.tmp";
            // The final segment ends at EOF; like the single-pass indexer it
            // must hold back an unterminated trailing entry so the merged index
            // is byte-for-byte equivalent.
            $isLast = $end >= $size;
            $this->indexSegment($fileId, $start, $end, $parser, $fingerprints, $segPath, $isLast);
            $segmentFiles[] = $segPath;
        }

        $result = $this->merge($identity, $segmentFiles, $size, $parser->id());

        foreach ($segmentFiles as $f) {
            @unlink($f);
        }

        return $result;
    }

    /**
     * Segment boundaries snapped forward to the next entry-start so no entry
     * spans two segments.
     *
     * @return array<int,array{0:int,1:int}>
     */
    private function boundaries(string $fileId, int $size, $parser): array
    {
        $points = [0];
        $target = $this->segmentSize;
        while ($target < $size) {
            $snapped = $this->snapToEntryStart($fileId, $target, $size, $parser);
            if ($snapped !== null && $snapped > end($points)) {
                $points[] = $snapped;
            }
            $target += $this->segmentSize;
        }
        $points[] = $size;
        $points = array_values(array_unique($points));

        $ranges = [];
        for ($i = 0; $i < count($points) - 1; $i++) {
            $ranges[] = [$points[$i], $points[$i + 1]];
        }

        return $ranges ?: [[0, $size]];
    }

    private function snapToEntryStart(string $fileId, int $from, int $size, $parser): ?int
    {
        $window = $this->source->readRange($fileId, $from, min(1 << 20, $size - $from));
        // Skip to the first newline so we start at a line boundary.
        $nl = strpos($window, "\n");
        $offset = $from + ($nl === false ? 0 : $nl + 1);
        $rest = $nl === false ? $window : substr($window, $nl + 1);
        foreach (explode("\n", $rest) as $line) {
            $probe = rtrim($line, "\r");
            if ($probe !== '' && $parser->isEntryStart($probe)) {
                return $offset;
            }
            $offset += strlen($line) + 1;
        }

        return null;
    }

    private function indexSegment(string $fileId, int $start, int $end, $parser, FingerprintEngine $fp, string $segPath, bool $isLast = false): void
    {
        @unlink($segPath);
        $pdo = new PDO('sqlite:' . $segPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA journal_mode=MEMORY');
        $pdo->exec('PRAGMA synchronous=OFF');
        $pdo->exec('CREATE TABLE seg (offset INTEGER, length INTEGER, ts INTEGER, level INTEGER, fp_app INTEGER, fp_sys INTEGER, title TEXT, text TEXT)');
        $insert = $pdo->prepare('INSERT INTO seg VALUES (:o,:l,:ts,:lvl,:fa,:fs,:t,:x)');

        $stream = $this->source->open($fileId, $start);
        $offset = $start;
        $buffer = '';
        $entryStart = $start;
        $have = false;
        $pdo->beginTransaction();

        $flush = function (string $raw, int $o, int $len) use ($insert, $parser, $fp) {
            $e = $parser->parse($raw);
            $f = $fp->compute($e);
            $firstLine = $e->message;
            $nl = strpos($firstLine, "\n");
            if ($nl !== false) {
                $firstLine = substr($firstLine, 0, $nl);
            }
            $insert->execute([
                ':o' => $o, ':l' => $len, ':ts' => $e->timestamp, ':lvl' => $e->level,
                ':fa' => $f['app'], ':fs' => $f['sys'],
                ':t' => mb_substr($firstLine, 0, 200),
                // Identical FTS text to the single-pass indexer (metadata prefix +
                // exception identifiers + message + context) — otherwise a
                // segmented (large-file) index would silently lose level/date/
                // channel/context and exceptionMessage from its searchable text.
                ':x' => Indexer::makeSearchText($e),
            ]);
        };

        $lastTerminated = true;
        while ($offset < $end && ($line = fgets($stream)) !== false) {
            $bytes = strlen($line);
            $lastTerminated = substr($line, -1) === "\n";
            $probe = rtrim($line, "\r\n");
            if ($parser->isEntryStart($probe)) {
                if ($have) {
                    $flush($buffer, $entryStart, $offset - $entryStart);
                }
                $buffer = $line;
                $entryStart = $offset;
                $have = true;
            } elseif ($have) {
                $buffer .= $line;
            } elseif (trim($probe) !== '') {
                $buffer = $line;
                $entryStart = $offset;
                $have = true;
            }
            $offset += $bytes;
        }
        // Hold back the trailing entry of the FINAL segment when it is not
        // newline-terminated — matches the single-pass indexer for equivalence.
        if ($have && ! ($isLast && ! $lastTerminated)) {
            $flush($buffer, $entryStart, min($offset, $end) - $entryStart);
        }
        fclose($stream);
        $pdo->commit();
        $pdo = null;
    }

    private function merge(FileIdentity $identity, array $segmentFiles, int $size, string $parserId): array
    {
        /** @var SqliteIndexStore $store */
        $store = $this->manager->store($identity);
        $store->reset();
        $store->setMeta('parser', $parserId);
        $pdo = $store->pdo();
        $caps = $store->capabilities();
        $hasFts = ! empty($caps['fts5']);

        $insertEntry = $pdo->prepare('INSERT INTO entries (seq,offset,length,ts,level,fp_app,fp_sys,title) VALUES (:s,:o,:l,:ts,:lvl,:fa,:fs,:t)');
        $insertFts = $hasFts ? $pdo->prepare('INSERT INTO fts (rowid,text) VALUES (:r,:x)') : null;
        $upsertStats = $pdo->prepare('INSERT INTO stats (bucket_hour, level, count) VALUES (:b, :l, 1)
            ON CONFLICT(bucket_hour, level) DO UPDATE SET count = count + 1');

        $seq = 0;
        $pdo->beginTransaction();
        foreach ($segmentFiles as $segPath) {
            $seg = new PDO('sqlite:' . $segPath);
            $seg->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $rows = $seg->query('SELECT * FROM seg ORDER BY offset ASC');
            foreach ($rows as $r) {
                $seq++;
                $insertEntry->execute([
                    ':s' => $seq, ':o' => (int) $r['offset'], ':l' => (int) $r['length'],
                    ':ts' => $r['ts'], ':lvl' => (int) $r['level'],
                    ':fa' => $r['fp_app'], ':fs' => $r['fp_sys'], ':t' => $r['title'],
                ]);
                if ($insertFts && $r['text'] !== null && $r['text'] !== '') {
                    $insertFts->execute([':r' => $seq, ':x' => $r['text']]);
                }
                if ($r['ts'] !== null) {
                    // Prepared upsert (not per-row exec with interpolated values).
                    $upsertStats->execute([
                        ':b' => intdiv((int) $r['ts'], 3600) * 3600,
                        ':l' => (int) $r['level'],
                    ]);
                }
            }
            $seg = null;
        }
        $pdo->commit();

        $store->rebuildGroups();
        $store->setLastIndexedOffset($size);
        $store->touch();

        return ['state' => 'ready', 'entries' => $seq, 'driver' => 'sqlite', 'segments' => count($segmentFiles)];
    }

    private function detectParser(string $fileId)
    {
        $head = $this->source->readRange($fileId, 0, 65536);
        $lines = array_slice(array_filter(preg_split('/\r?\n/', $head) ?: [], fn ($l) => $l !== ''), 0, 50);

        return $this->parsers->detect($lines);
    }

    /** @return resource|false|null  see Indexer::acquireLock */
    private function acquireLock(FileIdentity $identity)
    {
        $dir = $this->manager->directory();
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $handle = @fopen($dir . DIRECTORY_SEPARATOR . $identity->key() . '.lock', 'c');
        if ($handle === false) {
            return false;
        }
        if (! @flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
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
}
