<?php

namespace LogLens\Indexing\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use LogLens\Fingerprint\FingerprintEngine;
use LogLens\Indexing\IndexManager;
use LogLens\Indexing\SegmentIndexer;
use LogLens\Indexing\WorkerHeartbeat;
use LogLens\Parsing\ParserManager;
use LogLens\Sources\LocalFileSource;

/**
 * Runs the segmented (large-file) index pass on a queue worker so it never
 * blocks the web request that triggered it.
 */
class IndexSegmentsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public string $fileId)
    {
    }

    public function handle(
        LocalFileSource $source,
        IndexManager $manager,
        ParserManager $parsers,
        FingerprintEngine $fingerprints,
        WorkerHeartbeat $heartbeat
    ): void {
        $heartbeat->beat();

        $identity = $source->identity($this->fileId);
        if (! $identity) {
            return;
        }

        $config = (array) (function_exists('config') ? config('loglens.index', []) : []);
        (new SegmentIndexer($source, $manager, $parsers, $fingerprints, $config))
            ->runSync($this->fileId, $identity);
    }
}
