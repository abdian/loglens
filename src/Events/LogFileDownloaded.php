<?php

namespace LogLens\Events;

class LogFileDownloaded extends AuditEvent
{
    public function action(): string
    {
        return 'downloaded';
    }
}
