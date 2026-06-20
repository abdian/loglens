<?php

namespace LogLens\Contracts;

use LogLens\Parsing\ParsedEntry;

/**
 * A log-format parser. Parsers are stateless and operate line-by-line so the
 * indexer can stream multi-GB files at constant memory.
 */
interface Parser
{
    /**
     * Stable identifier (e.g. "laravel", "json"). Stored in index meta.
     */
    public function id(): string;

    /**
     * Confidence in [0.0, 1.0] that a sample of lines belongs to this format.
     * Used for auto-detection ranking. 0.0 means "definitely not me".
     *
     * @param  string[]  $sampleLines
     */
    public function detect(array $sampleLines): float;

    /**
     * Cheap first-byte/character pre-check: does this line *start* a new entry?
     * Called per line before any expensive PCRE (halves throughput otherwise).
     */
    public function isEntryStart(string $line): bool;

    /**
     * Parse an assembled entry (one or more lines already joined) into fields.
     */
    public function parse(string $raw): ParsedEntry;
}
