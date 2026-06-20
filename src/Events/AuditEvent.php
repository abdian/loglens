<?php

namespace LogLens\Events;

/**
 * Base audit event. Every file view plus every
 * download/delete/clear/prune dispatches a concrete subclass the host app can
 * listen to and persist (user, action, canonical path, IP, time).
 */
abstract class AuditEvent
{
    public int $time;

    public function __construct(
        public ?int $userId,
        public string $path,
        public ?string $ip = null,
        ?int $time = null
    ) {
        $this->time = $time ?? time();
    }

    abstract public function action(): string;

    public function toArray(): array
    {
        return [
            'action' => $this->action(),
            'user_id' => $this->userId,
            'path' => $this->path,
            'ip' => $this->ip,
            'time' => $this->time,
        ];
    }
}
