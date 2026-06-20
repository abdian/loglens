<?php

namespace LogLens\Indexing\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use LogLens\Indexing\WorkerHeartbeat;

/**
 * One-shot worker-liveness probe.
 *
 * The real IndexFileJob only refreshes the heartbeat while it runs, which means
 * a host with a live worker but no indexing in flight would never light up the
 * heartbeat — so the coordinator's queue tier could never engage (bootstrap
 * deadlock). The coordinator dispatches this tiny job (throttled) when the
 * heartbeat is cold: if a worker exists it beats and the next request switches
 * to the queue tier; if not, it harmlessly waits in the queue.
 */
class HeartbeatProbeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(WorkerHeartbeat $heartbeat): void
    {
        $heartbeat->beat();
    }
}
