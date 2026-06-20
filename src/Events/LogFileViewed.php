<?php

namespace LogLens\Events;

class LogFileViewed extends AuditEvent
{
    public function action(): string
    {
        return 'viewed';
    }
}
