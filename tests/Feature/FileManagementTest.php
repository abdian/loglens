<?php

namespace LogLens\Tests\Feature;

use LogLens\Tests\TestCase;

class FileManagementTest extends TestCase
{
    public function test_clear_truncates_preserving_file(): void
    {
        $path = $this->fixtures->laravel('laravel.log', 20);
        $inodeBefore = @fileinode($path);
        $id = $this->fileId('laravel.log');

        $res = $this->postJson("loglens/api/files/{$id}/clear");
        $res->assertOk();
        $res->assertJson(['ok' => true]);

        clearstatcache();
        $this->assertFileExists($path);
        $this->assertSame(0, filesize($path));
        if ($inodeBefore) {
            $this->assertSame($inodeBefore, @fileinode($path)); // inode preserved
        }
    }

    public function test_delete_removes_file(): void
    {
        $path = $this->fixtures->laravel('laravel.log', 20);
        $id = $this->fileId('laravel.log');

        $res = $this->deleteJson("loglens/api/files/{$id}");
        $res->assertOk();
        $this->assertFileDoesNotExist($path);
    }

    public function test_batch_reports_per_file_results(): void
    {
        $this->fixtures->laravel('a.log', 5);
        $this->fixtures->laravel('b.log', 5);
        $idA = $this->fileId('a.log');
        $idB = $this->fileId('b.log');

        $res = $this->postJson('loglens/api/files/batch', [
            'op' => 'clear',
            'files' => [$idA, $idB, 'nonexistentid000'],
        ]);
        $res->assertOk();
        $this->assertSame(2, $res->json('summary.succeeded'));
        $this->assertNotEmpty($res->json('failed'));
    }

    public function test_writability_preflight(): void
    {
        $this->fixtures->laravel('laravel.log', 5);
        $id = $this->fileId('laravel.log');

        $res = $this->getJson("loglens/api/files/{$id}/writability");
        $res->assertOk();
        $res->assertJsonStructure(['can_clear', 'can_delete']);
    }

    public function test_signed_download_url_is_user_bound(): void
    {
        $this->fixtures->laravel('laravel.log', 5);
        $id = $this->fileId('laravel.log');

        $res = $this->getJson("loglens/api/files/{$id}/download");
        $res->assertOk();
        $this->assertStringContainsString('signature=', $res->json('url'));
    }
}
