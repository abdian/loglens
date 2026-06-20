<?php

namespace LogLens\Parsing;

/**
 * The structured result of parsing one assembled log entry. This is the data
 * contract between parsers, the indexer, the search engine and the API.
 */
final class ParsedEntry
{
    public function __construct(
        public ?int $timestamp = null,
        /** Compact severity ordinal (see LogLens\Indexing\Level), not a name. */
        public int $level = 1,
        public ?string $channel = null,
        public ?string $environment = null,
        public string $message = '',
        public string $raw = '',
        /** @var array|null per-call context (first JSON tail) */
        public ?array $context = null,
        /** @var array|null shared Context-facade extra (second JSON tail) */
        public ?array $extra = null,
        /** Exception class when the entry is an exception. */
        public ?string $exceptionClass = null,
        public ?string $exceptionMessage = null,
        public ?string $throwFile = null,
        public ?int $throwLine = null,
        /** @var array<int,array{file:string,line:?int,call:?string,vendor:bool}> */
        public array $frames = [],
        /** First non-vendor frame "file:line", or null. */
        public ?string $appFrame = null,
        public bool $truncated = false
    ) {
    }

    public function isException(): bool
    {
        return $this->exceptionClass !== null;
    }

    public function hasContext(): bool
    {
        return ! empty($this->context) || ! empty($this->extra);
    }
}
