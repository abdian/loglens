<?php

namespace LogLens\Tests\Unit;

use LogLens\Contracts\IndexStore;
use LogLens\Indexing\BinaryIndexStore;
use LogLens\Indexing\IndexedEntry;
use LogLens\Indexing\Level;
use LogLens\Indexing\SqliteIndexStore;
use LogLens\Support\FileIdentity;
use PHPUnit\Framework\TestCase;

/**
 * Conformance suite that runs identically against the SQLite primary store and
 * the packed-binary fallback. Both stores are exercised inside each test (each
 * in its own directory) rather than via a data provider, so the suite behaves
 * the same across every supported PHPUnit line.
 */
class IndexStoreConformanceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'loglens_store_' . uniqid();
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->dir);
    }

    /** @return array<string, callable(string):IndexStore> */
    private function stores(): array
    {
        return [
            'sqlite' => fn (string $dir): IndexStore => new SqliteIndexStore($dir),
            'binary' => fn (string $dir): IndexStore => new BinaryIndexStore($dir),
        ];
    }

    public function test_append_and_page(): void
    {
        foreach ($this->stores() as $name => $factory) {
            $store = $this->seed($factory, $name);

            $this->assertSame(100, $store->count(), $name);

            $newest = $store->page(null, 10, 'older');
            $this->assertCount(10, $newest, $name);
            $this->assertSame(100, $newest[0]->seq, $name);
            $this->assertSame(91, $newest[9]->seq, $name);
        }
    }

    public function test_level_filter(): void
    {
        foreach ($this->stores() as $name => $factory) {
            $store = $this->seed($factory, $name);
            $errors = $store->page(null, 1000, 'older', ['levels' => [Level::ERROR]]);
            // every 10th entry is an error
            $this->assertSame(10, count($errors), $name);
            foreach ($errors as $e) {
                $this->assertSame(Level::ERROR, $e->level, $name);
            }
        }
    }

    public function test_seek_timestamp(): void
    {
        foreach ($this->stores() as $name => $factory) {
            $store = $this->seed($factory, $name);
            // seq $i has ts 1000 + $i*60; first entry at-or-after ts(50)=4000 is seq 50.
            $seq = $store->seekTimestamp(1000 + 50 * 60);
            $this->assertSame(50, $seq, $name);
        }
    }

    public function test_stats_aggregation(): void
    {
        foreach ($this->stores() as $name => $factory) {
            $store = $this->seed($factory, $name);
            $stats = $store->stats();
            $this->assertArrayHasKey('levels', $stats, $name);
            $this->assertSame(10, $stats['levels'][Level::ERROR] ?? 0, $name);
        }
    }

    public function test_keyset_cursor_both_directions(): void
    {
        foreach ($this->stores() as $name => $factory) {
            $store = $this->seed($factory, $name);
            $older = $store->page(50, 5, 'older');
            $this->assertSame([49, 48, 47, 46, 45], array_map(fn ($e) => $e->seq, $older), $name);
            $newer = $store->page(50, 5, 'newer');
            $this->assertSame([55, 54, 53, 52, 51], array_map(fn ($e) => $e->seq, $newer), $name);
        }
    }

    public function test_soft_delete_excludes_entry(): void
    {
        foreach ($this->stores() as $name => $factory) {
            $store = $this->seed($factory, $name);

            if (! $store->supportsSoftDelete()) {
                // Binary fallback cannot soft-delete; the call is a safe no-op.
                $this->assertFalse($store->softDelete(50), $name);
                $this->assertNotNull($store->find(50), $name);
                continue;
            }

            $this->assertTrue($store->softDelete(50), $name);
            // A second delete of the same seq reports no live row affected.
            $this->assertFalse($store->softDelete(50), $name);

            $this->assertNull($store->find(50), $name);
            $seqs = array_map(fn ($e) => $e->seq, $store->page(null, 1000, 'older'));
            $this->assertNotContains(50, $seqs, $name);
            // seekTimestamp must skip the deleted entry (seq 51 is the next).
            $this->assertSame(51, $store->seekTimestamp(1000 + 50 * 60), $name);
        }
    }

    public function test_soft_delete_adjusts_stats(): void
    {
        $store = new SqliteIndexStore($this->dir . DIRECTORY_SEPARATOR . 'stats');
        mkdir($this->dir . DIRECTORY_SEPARATOR . 'stats', 0775, true);
        $store->open(new FileIdentity('hash' . uniqid(), 1, 1, 'fp', 0, 0, '/x.log'));
        $store->beginBatch();
        for ($i = 1; $i <= 100; $i++) {
            $store->append(new IndexedEntry(
                seq: $i, offset: $i * 100, length: 100, timestamp: 1000 + $i * 60,
                level: $i % 10 === 0 ? Level::ERROR : Level::INFO,
                fpApp: $i % 10 === 0 ? 999 : 111, title: "entry $i"
            ));
        }
        $store->commitBatch();

        $this->assertSame(10, $store->stats()['levels'][Level::ERROR] ?? 0);
        $store->softDelete(50); // seq 50 is an ERROR
        $this->assertSame(9, $store->stats()['levels'][Level::ERROR] ?? 0);
    }

    /** @param callable(string):IndexStore $factory */
    private function seed(callable $factory, string $name): IndexStore
    {
        $dir = $this->dir . DIRECTORY_SEPARATOR . $name;
        mkdir($dir, 0775, true);

        $store = $factory($dir);
        $store->open(new FileIdentity('hash' . uniqid(), 1, 1, 'fp', 0, 0, '/x.log'));
        $store->beginBatch();
        for ($i = 1; $i <= 100; $i++) {
            $store->append(new IndexedEntry(
                seq: $i,
                offset: $i * 100,
                length: 100,
                timestamp: 1000 + $i * 60,
                level: $i % 10 === 0 ? Level::ERROR : Level::INFO,
                fpApp: $i % 10 === 0 ? 999 : 111,
                title: "entry $i",
                searchText: "entry number $i message"
            ));
        }
        $store->commitBatch();
        $store->setLastIndexedOffset(10100);

        return $store;
    }

    private function rmrf(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
