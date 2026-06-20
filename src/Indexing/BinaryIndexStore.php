<?php

namespace LogLens\Indexing;

use LogLens\Contracts\IndexStore;
use LogLens\Support\FileIdentity;

/**
 * Packed-binary fallback index for hosts
 * without pdo_sqlite. Fixed 29-byte records support offset/timestamp/level
 * operations and binary search by timestamp; FTS and dual-hash groups are
 * deliberately absent (search degrades to a streamed scan). Implements the
 * same IndexStore contract so the conformance suite covers both.
 *
 * Record: offset(P,8) length(V,4) ts(q,8) level(C,1) fp_app(q,8) = 29 bytes.
 */
class BinaryIndexStore implements IndexStore
{
    private const REC = 29;

    private const TS_NULL = PHP_INT_MIN;

    private string $path;

    private string $metaPath;

    private array $meta = [];

    /** @var resource|null */
    private $writeHandle = null;

    private string $writeBuffer = '';

    private int $appendCount = 0;

    public function __construct(private string $directory)
    {
    }

    public function open(FileIdentity $identity): void
    {
        if (! is_dir($this->directory)) {
            @mkdir($this->directory, 0775, true);
        }
        $this->path = $this->directory . DIRECTORY_SEPARATOR . $identity->key() . '.bidx';
        $this->metaPath = $this->path . '.meta.json';
        $this->meta = is_file($this->metaPath)
            ? (json_decode((string) file_get_contents($this->metaPath), true) ?: [])
            : [];
        $this->appendCount = $this->count();
        $this->setMeta('identity', $identity->toArray());
        $this->touch();
    }

    public function exists(): bool
    {
        return $this->count() > 0;
    }

    public function beginBatch(): void
    {
        if ($this->writeHandle === null) {
            $this->writeHandle = fopen($this->path, 'cb');
            fseek($this->writeHandle, 0, SEEK_END);
        }
    }

    public function append(IndexedEntry $entry): void
    {
        $this->beginBatch();
        $this->writeBuffer .= pack(
            'PVqCq',
            $entry->offset,
            $entry->length,
            $entry->timestamp ?? self::TS_NULL,
            $entry->level,
            $entry->fpApp ?? 0
        );
        $this->appendCount++;
        if (strlen($this->writeBuffer) >= self::REC * 4096) {
            $this->flushBuffer();
        }
    }

    public function commitBatch(): void
    {
        $this->flushBuffer();
        if ($this->writeHandle) {
            fflush($this->writeHandle);
        }
        $this->persistMeta();
    }

    private function flushBuffer(): void
    {
        if ($this->writeBuffer !== '' && $this->writeHandle) {
            fwrite($this->writeHandle, $this->writeBuffer);
            $this->writeBuffer = '';
        }
    }

    public function lastIndexedOffset(): int
    {
        return (int) ($this->meta['last_offset'] ?? 0);
    }

    public function setLastIndexedOffset(int $offset): void
    {
        $this->setMeta('last_offset', $offset);
    }

    public function count(): int
    {
        clearstatcache(true, $this->path ?? '');

        return isset($this->path) && is_file($this->path) ? intdiv((int) filesize($this->path), self::REC) : 0;
    }

    public function maxSeq(): int
    {
        return $this->count();
    }

    public function page(?int $cursorSeq, int $limit, string $direction, array $filters = []): array
    {
        $total = $this->count();
        if ($total === 0) {
            return [];
        }
        $handle = fopen($this->path, 'rb');
        $out = [];

        if ($direction === 'newer') {
            $start = ($cursorSeq ?? 0) + 1;
            for ($seq = $start; $seq <= $total && count($out) < $limit; $seq++) {
                $rec = $this->readRecord($handle, $seq);
                if ($rec && $this->matches($rec, $filters)) {
                    $out[] = $rec;
                }
            }
            // Present newest-first to match the SQLite store's contract.
            $out = array_reverse($out);
        } else {
            $start = $cursorSeq !== null ? $cursorSeq - 1 : $total;
            for ($seq = $start; $seq >= 1 && count($out) < $limit; $seq--) {
                $rec = $this->readRecord($handle, $seq);
                if ($rec && $this->matches($rec, $filters)) {
                    $out[] = $rec;
                }
            }
        }
        fclose($handle);

        return $out;
    }

    public function seekTimestamp(int $ts): ?int
    {
        $total = $this->count();
        if ($total === 0) {
            return null;
        }
        $handle = fopen($this->path, 'rb');
        $lo = 1;
        $hi = $total;
        $result = null;
        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            $rec = $this->readRecord($handle, $mid);
            $recTs = $rec->timestamp ?? self::TS_NULL;
            if ($recTs >= $ts) {
                $result = $mid;
                $hi = $mid - 1;
            } else {
                $lo = $mid + 1;
            }
        }
        fclose($handle);

        return $result;
    }

    public function find(int $seq): ?IndexedEntry
    {
        if ($seq < 1 || $seq > $this->count()) {
            return null;
        }
        $handle = fopen($this->path, 'rb');
        $rec = $this->readRecord($handle, $seq);
        fclose($handle);

        return $rec;
    }

    public function stats(array $filters = []): array
    {
        $total = $this->count();
        $buckets = [];
        $levels = [];
        if ($total === 0) {
            return ['buckets' => $buckets, 'levels' => $levels];
        }
        $handle = fopen($this->path, 'rb');
        $after = $filters['after'] ?? null;
        $before = $filters['before'] ?? null;
        for ($seq = 1; $seq <= $total; $seq++) {
            $rec = $this->readRecord($handle, $seq);
            if ($rec->timestamp === null) {
                continue;
            }
            if ($after !== null && $rec->timestamp < $after) {
                continue;
            }
            if ($before !== null && $rec->timestamp > $before) {
                continue;
            }
            $b = intdiv($rec->timestamp, 3600) * 3600;
            $buckets[$b][$rec->level] = ($buckets[$b][$rec->level] ?? 0) + 1;
            $levels[$rec->level] = ($levels[$rec->level] ?? 0) + 1;
        }
        fclose($handle);
        ksort($buckets);

        return ['buckets' => $buckets, 'levels' => $levels];
    }

    public function reset(): void
    {
        $this->flushBuffer();
        if ($this->writeHandle) {
            fclose($this->writeHandle);
            $this->writeHandle = null;
        }
        @file_put_contents($this->path, '');
        $this->appendCount = 0;
        $this->setMeta('last_offset', 0);
    }

    public function supportsSoftDelete(): bool
    {
        return false;
    }

    /** Fixed-width records have no deleted flag; single-entry delete is unsupported. */
    public function softDelete(int $seq): bool
    {
        return false;
    }

    public function setMeta(string $key, $value): void
    {
        $this->meta[$key] = $value;
    }

    public function getMeta(string $key, $default = null)
    {
        return $this->meta[$key] ?? $default;
    }

    public function touch(): void
    {
        $this->setMeta('last_viewed', time());
        $this->persistMeta();
    }

    public function close(): void
    {
        $this->commitBatch();
        if ($this->writeHandle) {
            fclose($this->writeHandle);
            $this->writeHandle = null;
        }
    }

    public function driver(): string
    {
        return 'binary';
    }

    public function capabilities(): array
    {
        return ['fts5' => false, 'trigram' => false, 'tier' => 'scan', 'store' => 'binary'];
    }

    private function persistMeta(): void
    {
        if (isset($this->metaPath)) {
            @file_put_contents($this->metaPath, json_encode($this->meta));
        }
    }

    private function readRecord($handle, int $seq): ?IndexedEntry
    {
        fseek($handle, ($seq - 1) * self::REC);
        $bytes = fread($handle, self::REC);
        if ($bytes === false || strlen($bytes) < self::REC) {
            return null;
        }
        $u = unpack('Poffset/Vlength/qts/Clevel/qfp', $bytes);

        return new IndexedEntry(
            seq: $seq,
            offset: $u['offset'],
            length: $u['length'],
            timestamp: $u['ts'] === self::TS_NULL ? null : $u['ts'],
            level: $u['level'],
            fpApp: $u['fp'] !== 0 ? $u['fp'] : null,
            title: null
        );
    }

    private function matches(IndexedEntry $rec, array $filters): bool
    {
        if (! empty($filters['levels']) && ! in_array($rec->level, array_map('intval', $filters['levels']), true)) {
            return false;
        }
        if (! empty($filters['after']) && ($rec->timestamp === null || $rec->timestamp < $filters['after'])) {
            return false;
        }
        if (! empty($filters['before']) && ($rec->timestamp === null || $rec->timestamp > $filters['before'])) {
            return false;
        }
        if (isset($filters['group']) && $filters['group'] !== null && $rec->fpApp !== (int) $filters['group']) {
            return false;
        }

        return true;
    }
}
