<?php

namespace LogLens\Support;

/**
 * Path canonicalization guard.
 *
 * Every file parameter resolves through realpath() and MUST reside inside one
 * of the configured roots. Symlink escapes and non-canonical paths are
 * rejected (caller turns null into a 404). This is the single defence against
 * the category's traversal CVEs (CVE-2018-8947, CVE-2021-24966, CVE-2023-41877).
 */
final class PathGuard
{
    /** @var string[] canonical root paths */
    private array $roots;

    /**
     * @param  string[]  $roots
     */
    public function __construct(array $roots)
    {
        $this->roots = [];
        foreach ($roots as $root) {
            $real = realpath($root);
            if ($real !== false) {
                $this->roots[] = self::normalize($real);
            }
        }
    }

    /**
     * Resolve an untrusted absolute path and confirm containment. Returns the
     * canonical real path or null if it escapes every root / does not exist.
     */
    public function resolve(string $candidate): ?string
    {
        $real = realpath($candidate);
        if ($real === false) {
            return null;
        }

        return $this->contains($real) ? $real : null;
    }

    /**
     * Resolve a path that may not exist yet (e.g. a freshly recreated file):
     * canonicalize the parent directory and re-append the basename.
     */
    public function resolveAllowingMissing(string $candidate): ?string
    {
        $real = realpath($candidate);
        if ($real !== false) {
            return $this->contains($real) ? $real : null;
        }

        $parent = realpath(dirname($candidate));
        if ($parent === false) {
            return null;
        }
        $resolved = $parent . DIRECTORY_SEPARATOR . basename($candidate);

        return $this->contains($resolved) ? $resolved : null;
    }

    public function contains(string $real): bool
    {
        $real = self::normalize($real);
        foreach ($this->roots as $root) {
            if ($real === $root || strpos($real, $root . '/') === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string[]
     */
    public function roots(): array
    {
        return $this->roots;
    }

    private static function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        return rtrim($path, '/');
    }
}
