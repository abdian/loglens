<?php

namespace LogLens\Sources;

use LogLens\Contracts\LogSource;
use LogLens\Support\FileIdentity;
use LogLens\Support\FileStat;
use LogLens\Support\PathGuard;

/**
 * The only v1 LogSource: local disk. Discovers files via include/exclude
 * globs inside the configured roots, groups by folder, and reads ranges with
 * transparent .gz decompression (offsets are into the decompressed stream).
 *
 * Every resolved path is re-checked against the PathGuard so a stale or forged
 * id cannot escape the root.
 */
class LocalFileSource implements LogSource
{
    private PathGuard $guard;

    /** @var array<string,string> id => canonical path (lazy) */
    private array $map = [];

    private bool $mapped = false;

    public function __construct(
        private array $roots,
        private array $include,
        private array $exclude
    ) {
        $this->guard = new PathGuard($roots);
    }

    public function list(): array
    {
        $this->buildMap();

        $stats = [];
        foreach ($this->map as $id => $path) {
            $stat = $this->statPath($id, $path);
            if ($stat) {
                $stats[] = $stat;
            }
        }

        return $stats;
    }

    public function stat(string $id): ?FileStat
    {
        $path = $this->resolveId($id);

        return $path ? $this->statPath($id, $path) : null;
    }

    public function path(string $id): ?string
    {
        return $this->resolveId($id);
    }

    public function identity(string $id): ?FileIdentity
    {
        $path = $this->resolveId($id);

        return $path ? FileIdentity::forPath($path) : null;
    }

    public function readRange(string $id, int $offset, int $length): string
    {
        $stream = $this->open($id, $offset);
        if (! $stream) {
            return '';
        }

        try {
            if ($length < 0) {
                return stream_get_contents($stream);
            }
            $buf = '';
            $remaining = $length;
            while ($remaining > 0 && ! feof($stream)) {
                $chunk = fread($stream, min(1 << 20, $remaining));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $buf .= $chunk;
                $remaining -= strlen($chunk);
            }

            return $buf;
        } finally {
            fclose($stream);
        }
    }

    public function open(string $id, int $offset = 0)
    {
        $path = $this->resolveId($id);
        if (! $path) {
            return null;
        }

        if ($this->isGz($path)) {
            // zlib wrapper can't random-seek; immutable archives are read from
            // the start and the decompressed prefix is discarded to $offset.
            $stream = @fopen('compress.zlib://' . $path, 'rb');
            if (! $stream) {
                return null;
            }
            $this->skip($stream, $offset);

            return $stream;
        }

        $stream = @fopen($path, 'rb');
        if (! $stream) {
            return null;
        }
        if ($offset > 0) {
            fseek($stream, $offset);
        }

        return $stream;
    }

    public function etag(string $id): ?string
    {
        $stat = $this->stat($id);
        if (! $stat) {
            return null;
        }
        $ident = $this->identity($id);

        return sprintf('"%s-%d-%d-%s"', $id, $stat->size, $stat->mtime, $ident?->inode ?? 'x');
    }

    // ---- discovery & resolution -------------------------------------------

    private function buildMap(): void
    {
        if ($this->mapped) {
            return;
        }

        $found = [];
        foreach ($this->roots as $root) {
            $realRoot = realpath($root);
            if ($realRoot === false) {
                continue;
            }
            foreach ($this->include as $pattern) {
                foreach ($this->globUnder($realRoot, $pattern) as $file) {
                    $found[$file] = true;
                }
            }
        }

        foreach (array_keys($found) as $file) {
            if ($this->isExcluded($file)) {
                continue;
            }
            $real = $this->guard->resolve($file);
            if ($real === null || ! is_file($real)) {
                continue;
            }
            $this->map[FileIdentity::hashPath($real)] = $real;
        }

        $this->mapped = true;
    }

    /**
     * Glob that understands a leading `**` for recursive matching without the
     * GLOB_BRACE portability headaches.
     */
    private function globUnder(string $root, string $pattern): array
    {
        if (strpos($pattern, '**') !== false) {
            // **/*.log => recurse all dirs.
            $suffix = ltrim(substr($pattern, strpos($pattern, '**') + 2), '/\\');

            return $this->recursiveGlob($root, $suffix ?: '*');
        }

        $glob = glob($root . DIRECTORY_SEPARATOR . $pattern, GLOB_NOSORT) ?: [];

        return array_filter($glob, 'is_file');
    }

    private function recursiveGlob(string $dir, string $filePattern): array
    {
        $results = [];
        $top = glob($dir . DIRECTORY_SEPARATOR . $filePattern, GLOB_NOSORT) ?: [];
        foreach ($top as $f) {
            if (is_file($f)) {
                $results[] = $f;
            }
        }
        $subdirs = glob($dir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR | GLOB_NOSORT) ?: [];
        foreach ($subdirs as $sub) {
            $results = array_merge($results, $this->recursiveGlob($sub, $filePattern));
        }

        return $results;
    }

    private function isExcluded(string $file): bool
    {
        foreach ($this->exclude as $pattern) {
            $p = str_replace('\\', '/', $pattern);
            $f = str_replace('\\', '/', $file);
            $p = '#' . str_replace(['**/', '*', '?'], ['(.*/)?', '[^/]*', '.'], preg_quote($p, '#')) . '$#';
            // Undo the over-escaping of our wildcards by preg_quote.
            $p = str_replace(['\(\.\*/\)\?', '\[\^/\]\*', '\.'], ['(.*/)?', '[^/]*', '.'], $p);
            if (@preg_match($p, $f)) {
                return true;
            }
        }

        return false;
    }

    private function resolveId(string $id): ?string
    {
        if (isset($this->map[$id])) {
            return $this->map[$id];
        }
        $this->buildMap();

        return $this->map[$id] ?? null;
    }

    private function statPath(string $id, string $path): ?FileStat
    {
        clearstatcache(true, $path);
        if (! is_file($path)) {
            return null;
        }
        $size = (int) @filesize($path);
        $mtime = (int) @filemtime($path);
        $folder = $this->relativeFolder($path);

        return new FileStat(
            id: $id,
            path: $path,
            name: basename($path),
            folder: $folder,
            size: $size,
            mtime: $mtime,
            compressed: $this->isGz($path)
        );
    }

    private function relativeFolder(string $path): string
    {
        $dir = str_replace('\\', '/', dirname($path));
        foreach ($this->guard->roots() as $root) {
            if (strpos($dir, $root) === 0) {
                $rel = trim(substr($dir, strlen($root)), '/');

                return $rel === '' ? '/' : $rel;
            }
        }

        return basename($dir);
    }

    private function isGz(string $path): bool
    {
        return substr(strtolower($path), -3) === '.gz';
    }

    private function skip($stream, int $bytes): void
    {
        while ($bytes > 0 && ! feof($stream)) {
            $chunk = fread($stream, min(1 << 20, $bytes));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $bytes -= strlen($chunk);
        }
    }
}
