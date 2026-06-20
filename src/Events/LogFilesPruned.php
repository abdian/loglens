<?php

namespace LogLens\Events;

class LogFilesPruned extends AuditEvent
{
    public function action(): string
    {
        return 'pruned';
    }
}
