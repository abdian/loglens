<?php

namespace LogLens\Tests\Feature;

use LogLens\Tests\TestCase;

class BrowsingApiTest extends TestCase
{
    public function test_lists_discovered_files(): void
    {
        $this->fixtures->laravel('laravel.log', 50);
        $this->fixtures->json('structured.log', 20);

        $res = $this->getJson('loglens/api/files');
        $res->assertOk();
        $names = array_column($res->json('files'), 'name');
        $this->assertContains('laravel.log', $names);
        $this->assertContains('structured.log', $names);
    }

    public function test_opens_file_with_index_state_and_entries(): void
    {
        $this->fixtures->laravel('laravel.log', 100);
        $id = $this->fileId('laravel.log');

        $res = $this->getJson("loglens/api/files/{$id}/open");
        $res->assertOk();
        $res->assertJsonStructure(['entries', 'index' => ['state'], 'file']);
        $this->assertNotEmpty($res->json('entries'));
    }

    public function test_keyset_pagination(): void
    {
        $this->fixtures->laravel('laravel.log', 100);
        $id = $this->fileId('laravel.log');
        $this->getJson("loglens/api/files/{$id}/open"); // trigger index

        $page1 = $this->getJson("loglens/api/files/{$id}/entries?limit=10");
        $page1->assertOk();
        $this->assertCount(10, $page1->json('entries'));
        $cursor = $page1->json('cursor.older');
        $this->assertNotNull($cursor);

        $page2 = $this->getJson("loglens/api/files/{$id}/entries?limit=10&cursor={$cursor}");
        $page2->assertOk();
        $seqs1 = array_column($page1->json('entries'), 'seq');
        $seqs2 = array_column($page2->json('entries'), 'seq');
        $this->assertEmpty(array_intersect($seqs1, $seqs2));
    }

    public function test_entry_detail_exposes_exception(): void
    {
        $this->fixtures->laravel('laravel.log', 10);
        $id = $this->fileId('laravel.log');
        $this->getJson("loglens/api/files/{$id}/open");

        // The fixture's last entry is the multi-line exception.
        $entries = $this->getJson("loglens/api/files/{$id}/entries?limit=200")->json('entries');
        $exception = collect($entries)->firstWhere('exception', '!=', null);
        $this->assertNotNull($exception);
        $this->assertSame('RuntimeException', $exception['exception']['class']);
        $this->assertNotEmpty($exception['exception']['frames']);
    }

    public function test_level_counts(): void
    {
        $this->fixtures->laravel('laravel.log', 100);
        $id = $this->fileId('laravel.log');
        $this->getJson("loglens/api/files/{$id}/open");

        $res = $this->getJson("loglens/api/files/{$id}/levels");
        $res->assertOk();
        $this->assertGreaterThan(0, $res->json('levels.error'));
    }

    public function test_histogram_from_stats(): void
    {
        $this->fixtures->laravel('laravel.log', 100);
        $id = $this->fileId('laravel.log');
        $this->getJson("loglens/api/files/{$id}/open");

        $res = $this->getJson("loglens/api/files/{$id}/histogram");
        $res->assertOk();
        $res->assertJsonStructure(['granularity', 'buckets', 'levels']);
    }

    public function test_issues_groups_collapse_occurrences(): void
    {
        $this->fixtures->laravel('laravel.log', 100);
        $id = $this->fileId('laravel.log');
        $this->getJson("loglens/api/files/{$id}/open");

        $res = $this->getJson("loglens/api/files/{$id}/groups");
        $res->assertOk();
        $this->assertTrue($res->json('supported'));
        $this->assertNotEmpty($res->json('groups'));
    }

    public function test_group_fingerprint_is_a_string_and_drills_in(): void
    {
        // Fingerprints are 64-bit ints; they must be serialized as strings so a
        // JS client can round-trip them without precision loss, otherwise the
        // group drill-in returns nothing.
        $this->fixtures->laravel('laravel.log', 100);
        $id = $this->fileId('laravel.log');
        $this->getJson("loglens/api/files/{$id}/open");

        $groups = $this->getJson("loglens/api/files/{$id}/groups")->json('groups');
        $this->assertNotEmpty($groups);

        $group = $groups[0];
        $this->assertIsString($group['fp']);

        // Drilling into the group's fingerprint returns its occurrences, every
        // one carrying that same (string) fingerprint.
        $res = $this->getJson("loglens/api/files/{$id}/entries?group=" . urlencode($group['fp']));
        $res->assertOk();
        $entries = $res->json('entries');
        $this->assertNotEmpty($entries);
        foreach ($entries as $entry) {
            $this->assertSame($group['fp'], $entry['fingerprint']);
        }
    }

    public function test_unicode_file_never_500s(): void
    {
        $this->fixtures->unicode('unicode.log');
        $id = $this->fileId('unicode.log');

        $res = $this->getJson("loglens/api/files/{$id}/open");
        $res->assertOk();
    }

    public function test_jump_to_timestamp(): void
    {
        $this->fixtures->laravel('laravel.log', 100);
        $id = $this->fileId('laravel.log');
        $this->getJson("loglens/api/files/{$id}/open");

        $res = $this->getJson("loglens/api/files/{$id}/jump?at=" . urlencode('2026-06-13 00:30:00'));
        $res->assertOk();
        $this->assertNotNull($res->json('anchor'));
    }
}
