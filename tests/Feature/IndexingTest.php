<?php

namespace LogLens\Tests\Feature;

use LogLens\Indexing\Indexer;
use LogLens\Indexing\IndexManager;
use LogLens\Indexing\TailReader;
use LogLens\Sources\LocalFileSource;
use LogLens\Tests\TestCase;

class IndexingTest extends TestCase
{
    private function index(string $name): array
    {
        $source = $this->app->make(LocalFileSource::class);
        $id = $this->fileId($name);

        return [$source, $id, $this->app->make(Indexer::class), $this->app->make(IndexManager::class)];
    }

    public function test_incremental_append(): void
    {
        $path = $this->fixtures->laravel('laravel.log', 50);
        [$source, $id, $indexer, $manager] = $this->index('laravel.log');

        $first = $indexer->index($id);
        $before = $first['entries'];
        $this->assertGreaterThan(0, $before);

        file_put_contents($path, "[2026-06-13 23:00:00] production.INFO: brand new entry\n", FILE_APPEND);
        $second = $indexer->index($id);

        $this->assertSame($before + 1, $second['entries']);
    }

    public function test_resume_across_batches_has_no_duplicates(): void
    {
        // Regression for the critical resume-offset bug: index in tiny budget
        // slices so the pass resumes at many batch boundaries; the result must
        // have a contiguous, non-duplicated seq sequence.
        $path = $this->fixtures->laravel('laravel.log', 300);
        $source = $this->app->make(LocalFileSource::class);
        $id = $this->fileId('laravel.log');
        $manager = $this->app->make(IndexManager::class);

        // Force a resume on every entry by using batch_size 1 + a 0ms budget.
        $indexer = new \LogLens\Indexing\Indexer(
            $source,
            $this->app->make(\LogLens\Parsing\ParserManager::class),
            $this->app->make(\LogLens\Fingerprint\FingerprintEngine::class),
            $manager,
            ['batch_size' => 1]
        );

        $guard = 0;
        do {
            $status = $indexer->index($id, ['budget_ms' => 0]);
        } while ($status['state'] === 'building' && ++$guard < 1000);

        $store = $manager->store($source->identity($id));
        $rows = $store->page(null, 100000, 'older');
        $seqs = array_map(fn ($e) => $e->seq, $rows);

        // No duplicate seqs.
        $this->assertSame(count($seqs), count(array_unique($seqs)));
        // Contiguous 1..N (newest-first page → reverse).
        $sorted = $seqs;
        sort($sorted);
        $this->assertSame(range(1, count($sorted)), $sorted);
    }

    public function test_truncation_triggers_reindex(): void
    {
        $path = $this->fixtures->laravel('laravel.log', 50);
        [$source, $id, $indexer, $manager] = $this->index('laravel.log');
        $indexer->index($id);

        // External truncation: file smaller than last indexed offset.
        file_put_contents($path, "[2026-06-13 23:30:00] production.INFO: only one now\n");
        $status = $indexer->index($id);

        $store = $manager->store($source->identity($id));
        $this->assertSame(1, $store->count());
    }

    public function test_tail_first_is_index_free(): void
    {
        $this->fixtures->laravel('laravel.log', 100);
        $source = $this->app->make(LocalFileSource::class);
        $id = $this->fileId('laravel.log');

        // No indexing performed — TailReader serves newest entries directly.
        $reader = $this->app->make(TailReader::class);
        $page = $reader->newestPage($id, 10);
        $this->assertNotEmpty($page['entries']);
        // Newest entry is the last written (the multi-line exception).
        $last = end($page['entries']);
        $this->assertNotNull($last['entry']);
    }

    public function test_gz_files_are_readable(): void
    {
        $this->fixtures->gz('archive.log.gz', 30);
        $source = $this->app->make(LocalFileSource::class);
        $id = $this->fileId('archive.log.gz');

        $indexer = $this->app->make(Indexer::class);
        $status = $indexer->index($id);
        $this->assertGreaterThan(0, $status['entries']);

        $manager = $this->app->make(IndexManager::class);
        $store = $manager->store($source->identity($id));
        $page = $store->page(null, 5, 'older');
        $this->assertNotEmpty($page);
    }

    public function test_binary_fallback_store(): void
    {
        config()->set('loglens.index.force_binary', true);
        // Rebuild the manager with the new config.
        $this->app->forgetInstance(IndexManager::class);

        $this->fixtures->laravel('laravel.log', 40);
        $source = $this->app->make(LocalFileSource::class);
        $id = $this->fileId('laravel.log');
        $indexer = $this->app->make(Indexer::class);
        $manager = $this->app->make(IndexManager::class);

        $this->assertSame('binary', $manager->driverName());
        $status = $indexer->index($id);
        $this->assertSame('binary', $status['driver']);
        $this->assertGreaterThan(0, $status['entries']);

        $store = $manager->store($source->identity($id));
        $this->assertNotEmpty($store->page(null, 5, 'older'));
    }

    public function test_browsing_survives_a_broken_cache_driver(): void
    {
        // Regression from a live install test: a host app whose cache driver is
        // misconfigured (e.g. CACHE_STORE=database with no migrated DB) must not
        // break LogLens — the coordinator degrades to in-request indexing.
        $this->fixtures->laravel('laravel.log', 30);
        $id = $this->fileId('laravel.log');

        $cache = \Mockery::mock(\Illuminate\Contracts\Cache\Repository::class)->shouldIgnoreMissing();
        $cache->shouldReceive('get')->andThrow(new \RuntimeException('cache down'));
        $cache->shouldReceive('put')->andThrow(new \RuntimeException('cache down'));
        $this->app->instance(\Illuminate\Contracts\Cache\Repository::class, $cache);
        foreach ([\LogLens\Indexing\WorkerHeartbeat::class, \LogLens\Indexing\IndexCoordinator::class, \LogLens\Browsing\Browser::class] as $svc) {
            $this->app->forgetInstance($svc);
        }

        $res = $this->getJson("loglens/api/files/{$id}/open");
        $res->assertOk();
        $this->assertNotEmpty($res->json('entries'));
    }

    public function test_safe_delete_of_index_directory_mid_session(): void
    {
        $this->fixtures->laravel('laravel.log', 30);
        $source = $this->app->make(LocalFileSource::class);
        $id = $this->fileId('laravel.log');
        $indexer = $this->app->make(Indexer::class);
        $indexer->index($id);

        // Nuke the whole index directory (rm -rf storage/loglens mid-session).
        $this->deleteDir($this->indexDir);
        @mkdir($this->indexDir, 0775, true);

        // Viewer must still work: rebuild on demand.
        $res = $this->getJson("loglens/api/files/{$id}/open");
        $res->assertOk();
        $this->assertNotEmpty($res->json('entries'));
    }
}
