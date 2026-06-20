<?php

namespace LogLens\Tail;

use LogLens\Support\FileIdentity;

/**
 * Unified resume cursor. The same opaque string is the SSE event id
 * (Last-Event-ID resume) AND the polling cursor: `pathHash:inode:offset:headFp`.
 * Encoding identity into the cursor lets the tail engine detect rotation (inode
 * OR head-fingerprint change) and truncation (offset > size) on resume. The
 * trailing head fingerprint lets rotation be detected on inode-less mounts
 * (FAT/SMB). The client treats the whole string as opaque.
 */
final class Cursor
{
    public function __construct(
        public string $pathHash,
        public ?int $inode,
        public int $offset,
        public ?string $headFp = null
    ) {
    }

    public static function parse(?string $raw): ?self
    {
        if (! $raw) {
            return null;
        }
        $parts = explode(':', $raw);
        // 3 parts = legacy (pathHash:inode:offset); 4 = + head fingerprint.
        if (count($parts) !== 3 && count($parts) !== 4) {
            return null;
        }

        return new self(
            $parts[0],
            $parts[1] === 'x' ? null : (int) hexdec($parts[1]),
            (int) $parts[2],
            $parts[3] ?? null
        );
    }

    public static function fromIdentity(FileIdentity $identity, int $offset): self
    {
        return new self($identity->pathHash, $identity->inode, $offset, $identity->headFingerprint ?: null);
    }

    public function __toString(): string
    {
        return sprintf(
            '%s:%s:%d:%s',
            $this->pathHash,
            $this->inode !== null ? dechex($this->inode) : 'x',
            $this->offset,
            $this->headFp ?? ''
        );
    }

    public function matchesIdentity(FileIdentity $identity): bool
    {
        if ($this->pathHash !== $identity->pathHash) {
            return false;
        }
        // Inode is authoritative when both sides have it.
        if ($this->inode !== null && $identity->inode !== null) {
            return $this->inode === $identity->inode;
        }
        // Inode-less mount (FAT/SMB): a head-fingerprint change means the file
        // was rotated/replaced even though the inode can't tell us.
        if ($this->headFp !== null && $this->headFp !== '' && $identity->headFingerprint !== '') {
            return $this->headFp === $identity->headFingerprint;
        }

        return true;
    }
}
