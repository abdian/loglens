<?php

namespace LogLens\Indexing\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use LogLens\Indexing\Indexer;
use LogLens\Indexing\WorkerHeartbeat;

/**
 * Chunked, resumable index job. Re-dispatches itself until the file
 * is fully indexed so a single job never blocks a worker for minutes, and
 * refreshes the worker heartbeat so the coordinator knows workers are alive.
 */
class IndexFileJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public string $fileId,
        public int $budgetMs = 1500
    ) {
    }

    public function handle(Indexer $indexer, WorkerHeartbeat $heartbeat): void
    {
        $heartbeat->beat();

        $status = $indexer->index($this->fileId, ['budget_ms' => $this->budgetMs]);

        if (($status['state'] ?? 'ready') === 'building') {
            // More work remains — resume in a fresh job (keeps the queue moving).
            self::dispatch($this->fileId, $this->budgetMs)->onQueue($this->queue ?? null);
        }
    }
}
