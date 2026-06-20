<?php

namespace LogLens\Tests\Feature;

use LogLens\Tests\TestCase;

class CliTest extends TestCase
{
    public function test_index_command(): void
    {
        $this->fixtures->laravel('laravel.log', 30);

        $this->artisan('loglens:index')
            ->assertExitCode(0);
    }

    public function test_search_command_json(): void
    {
        $this->fixtures->laravel('laravel.log', 50);

        $this->artisan('loglens:search', ['query' => 'Processing order', '--json' => true])
            ->assertExitCode(0);
    }

    public function test_search_command_invalid_query(): void
    {
        $this->fixtures->laravel('laravel.log', 10);

        $this->artisan('loglens:search', ['query' => '(unclosed'])
            ->assertExitCode(1);
    }

    public function test_stats_command(): void
    {
        $this->fixtures->laravel('laravel.log', 20);

        $this->artisan('loglens:stats')
            ->assertExitCode(0);
    }

    public function test_prune_dry_run_changes_nothing(): void
    {
        $path = $this->fixtures->laravel('old.log', 10);
        // Backdate the file 60 days.
        touch($path, time() - 60 * 86400);

        $this->artisan('loglens:prune', ['--days' => 30, '--dry-run' => true])
            ->assertExitCode(0);

        $this->assertFileExists($path);
    }

    public function test_prune_by_age(): void
    {
        $path = $this->fixtures->laravel('old.log', 10);
        touch($path, time() - 60 * 86400);

        $this->artisan('loglens:prune', ['--days' => 30])
            ->assertExitCode(0);

        $this->assertFileDoesNotExist($path);
    }
}
