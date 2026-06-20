<?php

namespace LogLens\Events;

class LogFileCleared extends AuditEvent
{
    public function action(): string
    {
        return 'cleared';
    }
}
