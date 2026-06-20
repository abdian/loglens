<?php

namespace LogLens\Tests\Feature;

use LogLens\Tests\TestCase;

class SearchTest extends TestCase
{
    public function test_substring_search_returns_hits(): void
    {
        $this->fixtures->laravel('laravel.log', 200);
        $id = $this->fileId('laravel.log');
        $this->getJson("loglens/api/files/{$id}/open");

        $res = $this->getJson("loglens/api/files/{$id}/search?q=" . urlencode('Processing order'));
        $res->assertOk();
        $this->assertGreaterThan(0, $res->json('count'));
        $this->assertContains($res->json('tier'), ['fts5_trigram', 'fts5_unicode61', 'like', 'scan']);
    }

    public function test_level_and_negation(): void
    {
        $this->fixtures->laravel('laravel.log', 200);
        $id = $this->fileId('laravel.log');
        $this->getJson("loglens/api/files/{$id}/open");

        $res = $this->getJson("loglens/api/files/{$id}/search?q=" . urlencode('level:error'));
        $res->assertOk();
        foreach ($res->json('entries') as $e) {
            $this->assertSame('error', $e['level']);
        }
    }

    public function test_invalid_query_returns_422_with_position(): void
    {
        $this->fixtures->laravel('laravel.log', 10);
        $id = $this->fileId('laravel.log');
        $this->getJson("loglens/api/files/{$id}/open");

        $res = $this->getJson("loglens/api/files/{$id}/search?q=" . urlencode('(unclosed'));
        $res->assertStatus(422);
        $res->assertJsonStructure(['error' => ['code', 'message', 'position']]);
    }

    public function test_highlights_present(): void
    {
        $this->fixtures->laravel('laravel.log', 50);
        $id = $this->fileId('laravel.log');
        $this->getJson("loglens/api/files/{$id}/open");

        $res = $this->getJson("loglens/api/files/{$id}/search?q=Processing");
        $res->assertOk();
        $first = $res->json('entries.0');
        $this->assertNotNull($first);
        $this->assertNotEmpty($first['highlights']);
    }
}
