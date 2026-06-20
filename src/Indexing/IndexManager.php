<?php

namespace LogLens\Indexing;

use LogLens\Contracts\IndexStore;
use LogLens\Support\ByteSize;
use LogLens\Support\FileIdentity;

/**
 * Factory + lifecycle for per-file index stores.
 *
 * Picks the SQLite store when pdo_sqlite is present (unless force_binary),
 * else the packed-binary fallback. Owns the global size budget + LRU pruning,
 * orphan cleanup, and identity-keyed tombstones.
 */
class IndexManager
{
    private string $directory;

    private int $budget;

    private bool $forceBinary;

    private ?bool $sqliteAvailable = null;

    public function __construct(private array $config = [])
    {
        $this->directory = $config['directory'] ?? sys_get_temp_dir() . '/loglens';
        $this->budget = ByteSize::parse($config['size_budget'] ?? '1G');
        $this->forceBinary = (bool) ($config['force_binary'] ?? false);
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function hasSqlite(): bool
    {
        if ($this->sqliteAvailable === null) {
            $this->sqliteAvailable = ! $this->forceBinary
                && extension_loaded('pdo_sqlite')
                && in_array('sqlite', \PDO::getAvailableDrivers(), true);
        }

        return $this->sqliteAvailable;
    }

    public function driverName(): string
    {
        return $this->hasSqlite() ? 'sqlite' : 'binary';
    }

    /** A fresh, unopened store of the active driver. */
    public function newStore(): IndexStore
    {
        return $this->hasSqlite()
            ? new SqliteIndexStore($this->directory)
            : new BinaryIndexStore($this->directory);
    }

    /** An opened store for the given identity. */
    public function store(FileIdentity $identity): IndexStore
    {
        $store = $this->newStore();
        $store->open($identity);

        return $store;
    }

    // ---- index state -------------------------------------------------------

    /**
     * Resolve the public indexState for a file given its store and current
     * size: none | building(percent) | ready.
     *
     * @return array{state:string, percent:int}
     */
    public function indexState(IndexStore $store, int $fileSize): array
    {
        $indexed = $store->lastIndexedOffset();
        if ($store->count() === 0 && $indexed === 0) {
            return ['state' => 'none', 'percent' => 0];
        }
        if ($fileSize <= 0 || $indexed >= $fileSize) {
            return ['state' => 'ready', 'percent' => 100];
        }

        return ['state' => 'building', 'percent' => (int) floor($indexed / $fileSize * 100)];
    }

    // ---- tombstones --------------------------------------------------------

    public function tombstone(FileIdentity $identity): void
    {
        $tombstones = $this->loadTombstones();
        $tombstones[$identity->key()] = time();
        $this->saveTombstones($tombstones);
    }

    public function isTombstoned(FileIdentity $identity): bool
    {
        return isset($this->loadTombstones()[$identity->key()]);
    }

    public function clearTombstone(FileIdentity $identity): void
    {
        $tombstones = $this->loadTombstones();
        unset($tombstones[$identity->key()]);
        $this->saveTombstones($tombstones);
    }

    // ---- lifecycle / budget ------------------------------------------------

    public function totalSize(): int
    {
        $total = 0;
        foreach ($this->indexFiles() as $file) {
            $total += (int) @filesize($file);
        }

        return $total;
    }

    /**
     * Drop indexes for files no longer present. $liveKeys = identity keys of
     * currently discoverable files.
     *
     * @param  string[]  $liveKeys
     * @return int number of pruned index sets
     */
    public function pruneOrphans(array $liveKeys): int
    {
        $live = array_flip($liveKeys);
        $pruned = 0;
        foreach ($this->indexKeys() as $key) {
            if (! isset($live[$key])) {
                $this->deleteIndexByKey($key);
                $pruned++;
            }
        }

        return $pruned;
    }

    /** Enforce the size budget by pruning least-recently-viewed indexes. */
    public function enforceBudget(): int
    {
        $total = $this->totalSize();
        if ($total <= $this->budget) {
            return 0;
        }

        $candidates = [];
        foreach ($this->indexKeys() as $key) {
            $candidates[$key] = $this->lastViewed($key);
        }
        asort($candidates); // oldest first

        // Track the running total and subtract each pruned set's size rather than
        // re-globbing + re-stat'ing the whole index directory on every iteration.
        $pruned = 0;
        foreach (array_keys($candidates) as $key) {
            if ($total <= $this->budget) {
                break;
            }
            $total -= $this->indexSetSize($key);
            $this->deleteIndexByKey($key);
            $pruned++;
        }

        return $pruned;
    }

    /** Total on-disk bytes of every index file belonging to one identity key. */
    private function indexSetSize(string $key): int
    {
        $total = 0;
        foreach (['.lidx', '.lidx-wal', '.lidx-shm', '.bidx', '.bidx.meta.json'] as $ext) {
            $f = $this->directory . DIRECTORY_SEPARATOR . $key . $ext;
            if (is_file($f)) {
                $total += (int) @filesize($f);
            }
        }

        return $total;
    }

    public function deleteIndex(FileIdentity $identity): void
    {
        $this->deleteIndexByKey($identity->key());
    }

    public function deleteIndexByKey(string $key): void
    {
        foreach (['.lidx', '.lidx-wal', '.lidx-shm', '.bidx', '.bidx.meta.json', '.lock'] as $ext) {
            $f = $this->directory . DIRECTORY_SEPARATOR . $key . $ext;
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }

    // ---- internals ---------------------------------------------------------

    private function lastViewed(string $key): int
    {
        $sqlite = $this->directory . DIRECTORY_SEPARATOR . $key . '.lidx';
        $binary = $this->directory . DIRECTORY_SEPARATOR . $key . '.bidx';
        $file = is_file($sqlite) ? $sqlite : $binary;

        return is_file($file) ? (int) @filemtime($file) : 0;
    }

    /** @return string[] absolute paths of primary index files */
    private function indexFiles(): array
    {
        if (! is_dir($this->directory)) {
            return [];
        }
        $files = [];
        foreach (['*.lidx', '*.lidx-wal', '*.lidx-shm', '*.bidx', '*.bidx.meta.json'] as $glob) {
            foreach (glob($this->directory . DIRECTORY_SEPARATOR . $glob) ?: [] as $f) {
                $files[] = $f;
            }
        }

        return $files;
    }

    /** @return string[] distinct identity keys present in the index dir */
    private function indexKeys(): array
    {
        $keys = [];
        foreach (['*.lidx', '*.bidx'] as $glob) {
            foreach (glob($this->directory . DIRECTORY_SEPARATOR . $glob) ?: [] as $f) {
                $keys[basename($f, '.' . pathinfo($f, PATHINFO_EXTENSION))] = true;
            }
        }

        return array_keys($keys);
    }

    private function tombstonePath(): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . 'tombstones.json';
    }

    private function loadTombstones(): array
    {
        $path = $this->tombstonePath();

        return is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];
    }

    private function saveTombstones(array $tombstones): void
    {
        if (! is_dir($this->directory)) {
            @mkdir($this->directory, 0775, true);
        }
        @file_put_contents($this->tombstonePath(), json_encode($tombstones));
    }
}
