<?php

namespace LogLens\Indexing;

use Illuminate\Contracts\Cache\Repository as Cache;
use LogLens\Contracts\IndexStore;
use LogLens\Fingerprint\FingerprintEngine;
use LogLens\Indexing\Jobs\HeartbeatProbeJob;
use LogLens\Indexing\Jobs\IndexFileJob;
use LogLens\Parsing\ParserManager;
use LogLens\Sources\LocalFileSource;
use LogLens\Support\FileIdentity;

/**
 * Background indexing strategy ladder.
 *
 *   1. live queue worker (heartbeat) → dispatch chunked IndexFileJob(s)
 *   2. scheduler detected           → enqueue for the scheduler tick
 *   3. else                         → bounded in-request slice, resumable
 *
 * The UI never waits: ensure() returns the current indexState immediately and
 * kicks off (or advances) indexing behind it.
 */
class IndexCoordinator
{
    private const SCHEDULER_FLAG = 'loglens:scheduler:alive';

    private const PENDING_KEY = 'loglens:pending';

    private const PROBE_FLAG = 'loglens:worker:probe';

    public function __construct(
        private Indexer $indexer,
        private IndexManager $manager,
        private LocalFileSource $source,
        private WorkerHeartbeat $heartbeat,
        private Cache $cache,
        private ParserManager $parsers,
        private FingerprintEngine $fingerprints,
        private array $config = []
    ) {
    }

    /**
     * @return array{state:string, percent:int, driver:string, strategy:string, entries:int}
     */
    public function ensure(string $fileId): array
    {
        $identity = $this->source->identity($fileId);
        if (! $identity) {
            return ['state' => 'none', 'percent' => 0, 'driver' => $this->manager->driverName(), 'strategy' => 'none', 'entries' => 0];
        }

        $store = $this->manager->store($identity);
        $state = $this->manager->indexState($store, $identity->size);

        if ($state['state'] === 'ready') {
            return $this->result($state, 'none', $store->count());
        }

        $strategy = $this->chooseAndRun($fileId, $identity, $state, $store);

        // Re-read state (in-request slicing may have advanced it).
        $store = $this->manager->store($identity);
        $state = $this->manager->indexState($store, $identity->size);

        return $this->result($state, $strategy, $store->count());
    }

    public function markSchedulerAlive(): void
    {
        try {
            $this->cache->put(self::SCHEDULER_FLAG, time(), 86400 + 3600);
        } catch (\Throwable $e) {
        }
    }

    /** Files awaiting the scheduler tick. */
    public function pending(): array
    {
        try {
            return array_keys($this->cache->get(self::PENDING_KEY, []));
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function clearPending(string $fileId): void
    {
        try {
            $pending = $this->cache->get(self::PENDING_KEY, []);
            unset($pending[$fileId]);
            $this->cache->put(self::PENDING_KEY, $pending, 86400);
        } catch (\Throwable $e) {
            // ignore
        }
    }

    private function chooseAndRun(string $fileId, FileIdentity $identity, array $state, IndexStore $store): string
    {
        // Large files + live workers → parallel segment indexing.
        $threshold = (int) ($this->config['segment_threshold'] ?? PHP_INT_MAX);

        if ($this->heartbeat->isAlive()) {
            if ($identity->size >= $threshold && $this->manager->hasSqlite()) {
                $this->dispatchSegments($fileId, $identity);

                return 'queue_segments';
            }
            $this->dispatchJob($fileId);

            return 'queue';
        }

        if ($this->schedulerAlive()) {
            $this->enqueuePending($fileId);

            return 'scheduler';
        }

        // Heartbeat is cold. If a real (async) worker might exist, send a one-shot
        // throttled probe so the queue tier can light up on a subsequent request —
        // otherwise the heartbeat, only beaten by running jobs, never warms up.
        $this->maybeProbeWorker();

        // Last resort: bounded in-request slice (keeps the UI progressing now).
        $this->indexer->index($fileId, ['budget_ms' => (int) ($this->config['in_request_budget_ms'] ?? 200)]);

        return 'in_request';
    }

    /**
     * Dispatch a single throttled worker-liveness probe when the heartbeat is
     * cold. Never on the sync queue driver (that would run the job — and every
     * subsequently-dispatched index job — inline, blocking the web request).
     */
    private function maybeProbeWorker(): void
    {
        try {
            if (! class_exists(HeartbeatProbeJob::class) || ! function_exists('dispatch')) {
                return;
            }
            if (function_exists('config') && (string) config('queue.default') === 'sync') {
                return;
            }
            if ($this->cache->get(self::PROBE_FLAG)) {
                return; // already probed recently — don't spam the queue
            }
            $this->cache->put(self::PROBE_FLAG, time(), 30);
            HeartbeatProbeJob::dispatch();
        } catch (\Throwable $e) {
            // A broken cache/queue must never break the in-request fallback.
        }
    }

    private function dispatchJob(string $fileId): void
    {
        if (class_exists(IndexFileJob::class) && function_exists('dispatch')) {
            IndexFileJob::dispatch($fileId);
        }
    }

    private function dispatchSegments(string $fileId, FileIdentity $identity): void
    {
        $segmenter = new SegmentIndexer($this->source, $this->manager, $this->parsers, $this->fingerprints, $this->config);
        $segmenter->dispatch($fileId, $identity);
    }

    private function enqueuePending(string $fileId): void
    {
        try {
            $pending = $this->cache->get(self::PENDING_KEY, []);
            $pending[$fileId] = time();
            $this->cache->put(self::PENDING_KEY, $pending, 86400);
        } catch (\Throwable $e) {
            // ignore — in-request indexing already ran as the fallback
        }
    }

    private function schedulerAlive(): bool
    {
        try {
            $last = (int) $this->cache->get(self::SCHEDULER_FLAG, 0);
        } catch (\Throwable $e) {
            return false; // cache unavailable → fall through to in-request slice
        }

        return $last > 0 && (time() - $last) <= 86400 + 3600;
    }

    private function result(array $state, string $strategy, int $entries): array
    {
        return [
            'state' => $state['state'],
            'percent' => $state['percent'],
            'driver' => $this->manager->driverName(),
            'strategy' => $strategy,
            'entries' => $entries,
        ];
    }
}
