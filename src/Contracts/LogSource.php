<?php

namespace LogLens\Contracts;

use LogLens\Support\FileStat;

/**
 * A source of log files. v1 ships LocalFileSource only; the interface is
 * designed now so S3/SFTP drivers slot in later without touching the core.
 *
 * Implementations must constrain every path to their own root and never leak
 * host identifiers into file identity.
 */
interface LogSource
{
    /**
     * Discover log files matching include/exclude globs.
     *
     * @return FileStat[]
     */
    public function list(): array;

    /**
     * Stat a single file by its opaque id (path hash). Returns null if the
     * file does not resolve inside the root.
     */
    public function stat(string $id): ?FileStat;

    /**
     * Read a byte range from the (possibly decompressed) stream.
     *
     * @param  int  $offset  Byte offset into the logical (decompressed) stream.
     * @param  int  $length  Number of bytes to read; -1 reads to EOF.
     */
    public function readRange(string $id, int $offset, int $length): string;

    /**
     * A weak validator that changes when the file changes
     * (size|mtime|identity), suitable for HTTP ETag / cache cursors.
     */
    public function etag(string $id): ?string;

    /**
     * Open a read stream positioned at $offset for sequential scanning.
     *
     * @return resource|null
     */
    public function open(string $id, int $offset = 0);
}
