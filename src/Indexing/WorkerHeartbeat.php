<?php

namespace LogLens\Indexing;

use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Queue-worker liveness via heartbeat freshness.
 *
 * A worker is "provably alive" only if a LogLens probe job has refreshed the
 * heartbeat key recently — NOT merely because the queue driver isn't `sync`
 * (Laravel 11+ defaults to `database` where jobs rot silently).
 */
class WorkerHeartbeat
{
    private const KEY = 'loglens:worker:heartbeat';

    private const FRESH_SECONDS = 60;

    public function __construct(private Cache $cache)
    {
    }

    public function beat(): void
    {
        try {
            $this->cache->put(self::KEY, time(), self::FRESH_SECONDS * 3);
        } catch (\Throwable $e) {
            // A broken/misconfigured host cache must never break LogLens.
        }
    }

    public function isAlive(): bool
    {
        try {
            $last = (int) $this->cache->get(self::KEY, 0);
        } catch (\Throwable $e) {
            return false; // cache unavailable → assume no worker → in-request path
        }

        return $last > 0 && (time() - $last) <= self::FRESH_SECONDS;
    }

    public function lastBeat(): ?int
    {
        try {
            $last = (int) $this->cache->get(self::KEY, 0);
        } catch (\Throwable $e) {
            return null;
        }

        return $last > 0 ? $last : null;
    }
}
