<?php

namespace LogLens\Tests\Feature;

use LogLens\Tests\TestCase;

class EntryDeleteTest extends TestCase
{
    public function test_soft_delete_excludes_entry_from_reads(): void
    {
        if (! $this->app->make(\LogLens\Indexing\IndexManager::class)->hasSqlite()) {
            $this->markTestSkipped('pdo_sqlite required for single-entry delete.');
        }

        $this->fixtures->laravel('laravel.log', 50);
        $id = $this->fileId('laravel.log');
        $this->getJson("loglens/api/files/{$id}/open"); // builds the index

        $seq = $this->getJson("loglens/api/files/{$id}/entries?limit=10")->json('entries.0.seq');
        $this->assertNotNull($seq);

        $res = $this->deleteJson("loglens/api/files/{$id}/entries/{$seq}");
        $res->assertOk();
        $res->assertExactJson(['ok' => true, 'seq' => $seq]);

        // Gone from the single-entry read path.
        $this->getJson("loglens/api/files/{$id}/entries/{$seq}")->assertStatus(404);

        // Gone from the page read path.
        $seqs = array_column($this->getJson("loglens/api/files/{$id}/entries?limit=100")->json('entries'), 'seq');
        $this->assertNotContains($seq, $seqs);
    }

    public function test_deleting_unknown_entry_returns_404(): void
    {
        if (! $this->app->make(\LogLens\Indexing\IndexManager::class)->hasSqlite()) {
            $this->markTestSkipped('pdo_sqlite required for single-entry delete.');
        }

        $this->fixtures->laravel('laravel.log', 5);
        $id = $this->fileId('laravel.log');
        $this->getJson("loglens/api/files/{$id}/open");

        $res = $this->deleteJson("loglens/api/files/{$id}/entries/999999");
        $res->assertStatus(404);
        $res->assertExactJson(['ok' => false, 'error' => 'Entry not found.']);
    }

    public function test_read_only_blocks_entry_delete(): void
    {
        config()->set('loglens.read_only', true);
        $this->fixtures->laravel('laravel.log', 5);
        $id = $this->fileId('laravel.log');

        $res = $this->deleteJson("loglens/api/files/{$id}/entries/1");
        $res->assertStatus(403);
    }

    public function test_kill_switch_blocks_entry_delete(): void
    {
        config()->set('loglens.security.allow_delete', false);
        $this->fixtures->laravel('laravel.log', 5);
        $id = $this->fileId('laravel.log');

        $res = $this->deleteJson("loglens/api/files/{$id}/entries/1");
        $res->assertStatus(403);
    }
}
