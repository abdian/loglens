<?php

namespace LogLens\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Serve pre-built, version-fingerprinted frontend assets from the package's
 * public directory. Because the URL carries the
 * package version, a composer update changes every asset URL — stale bundles
 * are impossible by construction. Long-lived immutable cache headers.
 */
class AssetController extends Controller
{
    private const TYPES = [
        'js' => 'application/javascript',
        'css' => 'text/css',
        'map' => 'application/json',
        'woff2' => 'font/woff2',
        'woff' => 'font/woff',
        'svg' => 'image/svg+xml',
    ];

    public function show(string $version, string $path): Response
    {
        // Reject traversal in the asset path.
        if (str_contains($path, '..') || str_starts_with($path, '/')) {
            throw new HttpException(404);
        }

        $base = realpath(__DIR__ . '/../../../public/build');
        if ($base === false) {
            throw new HttpException(404, 'Assets not built.');
        }
        $full = realpath($base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path));
        if ($full === false || ! str_starts_with($full, $base) || ! is_file($full)) {
            throw new HttpException(404);
        }

        $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
        $response = new BinaryFileResponse($full);
        $response->headers->set('Content-Type', self::TYPES[$ext] ?? 'application/octet-stream');
        $response->headers->set('Cache-Control', 'public, max-age=31536000, immutable');

        return $response;
    }
}
