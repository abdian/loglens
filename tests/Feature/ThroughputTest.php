<?php

namespace LogLens\Tests\Feature;

use LogLens\Fingerprint\FingerprintEngine;
use LogLens\Indexing\Indexer;
use LogLens\Indexing\IndexManager;
use LogLens\Parsing\ParserManager;
use LogLens\Sources\LocalFileSource;
use LogLens\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Indexing throughput. The design target is ≥100k entries/s on fast
 * Linux hardware; the indexer is intentionally CPU-bound (regex + trigram FTS).
 * To stay non-flaky across CI runners we assert a conservative floor and print
 * the measured rate (the published benchmark suite reports the real numbers).
 */
#[Group('benchmark')]
class ThroughputTest extends TestCase
{
    public function test_index_build_throughput(): void
    {
        $count = 50000;
        $path = $this->logDir . '/perf.log';
        $fh = fopen($path, 'wb');
        $base = gmmktime(0, 0, 0, 1, 1, 2026);
        for ($i = 0; $i < $count; $i++) {
            fwrite($fh, '[' . gmdate('Y-m-d H:i:s', $base + $i) . "] production.INFO: Processing order $i for user " . ($i % 1000) . "\n");
        }
        fclose($fh);

        $id = $this->fileId('perf.log');
        /** @var Indexer $indexer */
        $indexer = $this->app->make(Indexer::class);

        $start = microtime(true);
        $status = $indexer->index($id);
        $seconds = microtime(true) - $start;
        $rate = $status['entries'] / max(0.001, $seconds);

        fprintf(STDERR, "\n  [throughput] %s entries/s (%d entries, %.2fs, %s)\n", number_format($rate), $status['entries'], $seconds, $status['driver']);

        $this->assertSame($count, $status['entries']);
        // Conservative floor — real hardware is far higher; this only guards
        // against accidental O(n^2) regressions.
        $this->assertGreaterThan(3000, $rate, 'Indexing throughput regressed badly.');
    }
}
