<?php

namespace LogLens\Indexing;

/**
 * One row of the index: enough to render a list item and locate the full entry
 * in the underlying file without re-parsing. 51 B/entry in the SQLite store.
 */
final class IndexedEntry
{
    public function __construct(
        public int $seq,
        public int $offset,
        public int $length,
        public ?int $timestamp,
        public int $level,
        public ?int $fpApp = null,
        public ?int $fpSys = null,
        /** Short title/preview captured at index time (for fast list paint). */
        public ?string $title = null,
        /** Transient: full searchable text fed to FTS at append time (not stored). */
        public ?string $searchText = null
    ) {
    }

    public function toArray(): array
    {
        return [
            'seq' => $this->seq,
            'offset' => $this->offset,
            'length' => $this->length,
            'timestamp' => $this->timestamp,
            'level' => Level::name($this->level),
            'level_value' => $this->level,
            'fp_app' => $this->fpApp,
            'fp_sys' => $this->fpSys,
            'title' => $this->title,
        ];
    }
}
