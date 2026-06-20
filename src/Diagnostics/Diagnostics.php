<?php

namespace LogLens\Diagnostics;

use LogLens\Indexing\IndexManager;
use LogLens\Support\ByteSize;

/**
 * Self-diagnostics surfaced in the UI panel: which index
 * store and search tier are active, runtime/buffering detection for live tail,
 * and capability flags so operators understand any degraded path.
 */
class Diagnostics
{
    public const VERSION = '1.0.0';

    public function __construct(private IndexManager $manager)
    {
    }

    public function report(): array
    {
        return [
            'version' => self::VERSION,
            'php_version' => PHP_VERSION,
            'os' => PHP_OS_FAMILY,
            'index' => [
                'driver' => $this->manager->driverName(),
                'directory' => $this->manager->directory(),
                'total_size' => $this->manager->totalSize(),
                'total_size_human' => ByteSize::format($this->manager->totalSize()),
            ],
            'capabilities' => $this->capabilities(),
            'runtime' => $this->runtime(),
        ];
    }

    public function capabilities(): array
    {
        $fts5 = false;
        $trigram = false;
        $sqliteVersion = null;
        if ($this->manager->hasSqlite()) {
            try {
                $pdo = new \PDO('sqlite::memory:');
                $sqliteVersion = $pdo->query('SELECT sqlite_version()')->fetchColumn();
                try {
                    $pdo->exec('CREATE VIRTUAL TABLE p USING fts5(x)');
                    $fts5 = true;
                    try {
                        $pdo->exec('CREATE VIRTUAL TABLE p2 USING fts5(x, tokenize=trigram)');
                        $trigram = true;
                    } catch (\Throwable $e) {
                    }
                } catch (\Throwable $e) {
                }
            } catch (\Throwable $e) {
            }
        }

        return [
            'pdo_sqlite' => extension_loaded('pdo_sqlite'),
            'sqlite_version' => $sqliteVersion,
            'fts5' => $fts5,
            'trigram' => $trigram,
            'zlib' => extension_loaded('zlib'),
            'mbstring' => extension_loaded('mbstring'),
            'xxh3' => in_array('xxh3', hash_algos(), true),
            'search_tier' => $trigram ? 'fts5_trigram' : ($fts5 ? 'fts5_unicode61' : ($this->manager->hasSqlite() ? 'like' : 'scan')),
        ];
    }

    public function runtime(): array
    {
        return [
            'octane' => $this->isOctane(),
            'swoole' => extension_loaded('swoole') || extension_loaded('openswoole'),
            'output_buffering' => (bool) ini_get('output_buffering'),
            'zlib_output_compression' => (bool) ini_get('zlib.output_compression'),
            'sapi' => PHP_SAPI,
            'pcntl' => extension_loaded('pcntl'),
            // Octane/Swoole buffers streamed responses → force polling transport.
            'force_polling' => $this->isOctane() || extension_loaded('swoole') || extension_loaded('openswoole'),
        ];
    }

    private function isOctane(): bool
    {
        if (function_exists('app')) {
            try {
                return app()->bound('octane') || (bool) env('OCTANE_DATABASE_SESSION_TTL', false) || isset($_SERVER['LARAVEL_OCTANE']);
            } catch (\Throwable $e) {
            }
        }

        return isset($_SERVER['LARAVEL_OCTANE']);
    }
}
