<?php

namespace LogLens\Events;

class LogEntryDeleted extends AuditEvent
{
    public function action(): string
    {
        return 'entry_deleted';
    }
}
