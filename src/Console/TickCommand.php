<?php

namespace LogLens\Console;

use Illuminate\Console\Command;
use LogLens\Indexing\Indexer;
use LogLens\Indexing\IndexCoordinator;

/**
 * `loglens:tick` — drives the coordinator's scheduler tier.
 *
 * Register it in the host scheduler so background indexing has a home even when
 * no queue worker runs:
 *
 *   Schedule::command('loglens:tick')->everyMinute()->withoutOverlapping();
 *
 * Each run marks the scheduler alive (so the coordinator enqueues new files for
 * it instead of slicing in-request) and advances any pending files by a bounded
 * budget, resuming them on later ticks until fully indexed.
 */
class TickCommand extends Command
{
    protected $signature = 'loglens:tick {--budget=4000 : Per-file indexing budget in milliseconds}';

    protected $description = 'Advance LogLens background indexing (scheduler tier)';

    public function handle(IndexCoordinator $coordinator, Indexer $indexer): int
    {
        $coordinator->markSchedulerAlive();

        $budget = max(100, (int) $this->option('budget'));
        foreach ($coordinator->pending() as $fileId) {
            $status = $indexer->index($fileId, ['budget_ms' => $budget]);
            if (($status['state'] ?? 'ready') === 'ready') {
                $coordinator->clearPending($fileId);
            }
        }

        return self::SUCCESS;
    }
}
