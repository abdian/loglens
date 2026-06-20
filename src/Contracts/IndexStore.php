<?php

namespace LogLens\Contracts;

use LogLens\Indexing\IndexedEntry;
use LogLens\Support\FileIdentity;

/**
 * Persistent per-file index. Two implementations share this contract: the
 * SQLite primary store and the packed-binary fallback. A conformance suite
 * runs against both.
 */
interface IndexStore
{
    /** Open (creating/migrating as needed) the index for a file identity. */
    public function open(FileIdentity $identity): void;

    /** True if an index already exists and is non-empty for this identity. */
    public function exists(): bool;

    /** Begin a batched write transaction. */
    public function beginBatch(): void;

    /** Append one indexed entry within the current batch. */
    public function append(IndexedEntry $entry): void;

    /** Commit the current batch. */
    public function commitBatch(): void;

    /** The byte offset up to which the underlying file has been indexed. */
    public function lastIndexedOffset(): int;

    /** Total indexed entry count. */
    public function count(): int;

    /**
     * Fetch a page of entries via keyset cursor.
     *
     * @param  array  $filters  ['levels' => int[], 'after' => int, 'before' => int, 'group' => int]
     * @return IndexedEntry[]
     */
    public function page(?int $cursorSeq, int $limit, string $direction, array $filters = []): array;

    /** Find the seq of the first entry at-or-after a timestamp (binary search). */
    public function seekTimestamp(int $ts): ?int;

    /** A single entry by seq. */
    public function find(int $seq): ?IndexedEntry;

    /** Per-hour-bucket × level counts for the histogram/level facets. */
    public function stats(array $filters = []): array;

    /** Reset all rows + meta (used on truncation / clear). */
    public function reset(): void;

    /** True if the store can soft-delete a single entry (SQLite only). */
    public function supportsSoftDelete(): bool;

    /** Mark a single entry deleted; returns whether a live row was affected. */
    public function softDelete(int $seq): bool;

    /** Persist arbitrary meta keys (identity, capabilities, parser id, …). */
    public function setMeta(string $key, $value): void;

    public function getMeta(string $key, $default = null);

    /** Close handles and flush. */
    public function close(): void;

    /** Backend identifier for diagnostics ("sqlite", "binary"). */
    public function driver(): string;
}
