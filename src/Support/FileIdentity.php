<?php

namespace LogLens\Support;

/**
 * Content-addressed identity for a log file.
 *
 *   identity = pathHash + inode/device (when real) + head-4KB fingerprint
 *
 * NEVER includes the server hostname or IP — that is the #425 bug class. On
 * filesystems without usable inodes (FAT/SMB) it degrades to pathHash + head
 * fingerprint and indexing still functions.
 */
final class FileIdentity
{
    public const HEAD_BYTES = 4096;

    public function __construct(
        public string $pathHash,
        public ?int $inode,
        public ?int $device,
        public string $headFingerprint,
        public int $size,
        public int $mtime,
        public string $path
    ) {
    }

    public static function forPath(string $path): self
    {
        $real = realpath($path) ?: $path;
        clearstatcache(true, $real);
        $stat = @stat($real) ?: [];

        $inode = isset($stat['ino']) && $stat['ino'] > 0 ? (int) $stat['ino'] : null;
        $device = isset($stat['dev']) && $stat['dev'] > 0 ? (int) $stat['dev'] : null;

        return new self(
            pathHash: self::hashPath($path),
            inode: $inode,
            device: $device,
            headFingerprint: self::headFingerprint($real),
            size: isset($stat['size']) ? (int) $stat['size'] : 0,
            mtime: isset($stat['mtime']) ? (int) $stat['mtime'] : 0,
            path: $real
        );
    }

    public static function hashPath(string $path): string
    {
        // Normalize separators so the same logical file hashes equally across
        // OSes / mounts that present the path differently.
        $norm = str_replace('\\', '/', $path);

        return substr(Hash::hex($norm), 0, 16);
    }

    public static function headFingerprint(string $path): string
    {
        $fh = @fopen($path, 'rb');
        if (! $fh) {
            return '';
        }
        $head = fread($fh, self::HEAD_BYTES);
        fclose($fh);

        return $head === false || $head === '' ? '' : substr(Hash::hex($head), 0, 16);
    }

    /**
     * Stable opaque key used as the index filename / tombstone key. Combines
     * path hash with inode when present so a recreated-after-delete file (same
     * path, new inode) gets a fresh index.
     */
    public function key(): string
    {
        return $this->inode !== null
            ? $this->pathHash . '-' . dechex($this->inode)
            : $this->pathHash . '-h' . substr($this->headFingerprint, 0, 8);
    }

    /**
     * Has the file's identity changed (rotation/replacement) vs. a previously
     * recorded snapshot? Inode/device change OR head-fingerprint mismatch.
     */
    public function differsFrom(?array $snapshot): bool
    {
        if (! $snapshot) {
            return false;
        }

        if (isset($snapshot['inode']) && $this->inode !== null
            && (int) $snapshot['inode'] !== $this->inode) {
            return true;
        }

        if (! empty($snapshot['head']) && $this->headFingerprint !== ''
            && $snapshot['head'] !== $this->headFingerprint) {
            return true;
        }

        return false;
    }

    public function toArray(): array
    {
        return [
            'pathHash' => $this->pathHash,
            'inode' => $this->inode,
            'device' => $this->device,
            'head' => $this->headFingerprint,
            'size' => $this->size,
            'mtime' => $this->mtime,
        ];
    }
}
