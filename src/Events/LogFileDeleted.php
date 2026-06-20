<?php

namespace LogLens\Events;

class LogFileDeleted extends AuditEvent
{
    public function action(): string
    {
        return 'deleted';
    }
}
