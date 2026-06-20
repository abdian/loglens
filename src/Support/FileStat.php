<?php

namespace LogLens\Support;

/**
 * Lightweight value object describing a discovered log file. The `id` is an
 * opaque path-hash safe to expose in URLs (never the raw path).
 */
final class FileStat
{
    public function __construct(
        public string $id,
        public string $path,
        public string $name,
        public string $folder,
        public int $size,
        public int $mtime,
        public bool $compressed = false
    ) {
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'folder' => $this->folder,
            'size' => $this->size,
            'size_human' => ByteSize::format($this->size),
            'mtime' => $this->mtime,
            'compressed' => $this->compressed,
        ];
    }
}
