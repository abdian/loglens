<?php

namespace LogLens\Tests;

use LogLens\LogLensServiceProvider;
use LogLens\Tests\Support\FixtureGenerator;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected string $workDir;

    protected string $logDir;

    protected string $indexDir;

    protected FixtureGenerator $fixtures;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'loglens_test_' . uniqid();
        $this->logDir = $this->workDir . DIRECTORY_SEPARATOR . 'logs';
        $this->indexDir = $this->workDir . DIRECTORY_SEPARATOR . 'index';
        @mkdir($this->logDir, 0775, true);
        @mkdir($this->indexDir, 0775, true);
        $this->fixtures = new FixtureGenerator($this->logDir);

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->deleteDir($this->workDir);
    }

    protected function getPackageProviders($app): array
    {
        return [LogLensServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('loglens.roots', [$this->logDir]);
        $app['config']->set('loglens.index.directory', $this->indexDir);
        $app['config']->set('loglens.route.middleware', []); // no session/csrf in tests
        $app['config']->set('cache.default', 'array');

        // Pin a real sqlite connection as the default so the suite behaves the
        // same on every testbench line (older lines do not default to sqlite).
        // LogLens never touches this connection — it proves the zero-migration
        // claim (no loglens_* tables are ever created here).
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
    }

    /** Resolve a discovered file's opaque id by name. */
    protected function fileId(string $name): string
    {
        $source = $this->app->make(\LogLens\Sources\LocalFileSource::class);
        foreach ($source->list() as $f) {
            if ($f->name === $name) {
                return $f->id;
            }
        }
        $this->fail("Fixture file {$name} not discovered.");
    }

    protected function deleteDir(string $dir): void
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
