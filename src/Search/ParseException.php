<?php

namespace LogLens\Search;

/**
 * Position-aware query parse error. Carries the character offset so
 * the API can report `{code, message, position}` and the UI can underline the
 * offending token instead of returning a server error.
 */
class ParseException extends \RuntimeException
{
    public function __construct(string $message, public int $position = 0)
    {
        parent::__construct($message);
    }

    public function toArray(): array
    {
        return [
            'code' => 'query_parse_error',
            'message' => $this->getMessage(),
            'position' => $this->position,
        ];
    }
}
