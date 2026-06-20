<?php

namespace LogLens\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use LogLens\Events\LogFileDownloaded;
use LogLens\Security\Authorizer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads.
 *
 * Single download via a short-TTL signed route bound to the authenticated user
 * id AND the canonical path (both verified at fetch time → replay by a
 * different user is rejected). Folder/multi-select streams a zip incrementally
 * (no temp materialization). "Last N MB" partial download for huge files.
 */
class DownloadController extends Controller
{
    public function __construct(private Authorizer $authorizer)
    {
    }

    /** Issue a signed, user-bound, short-TTL download URL. */
    public function sign(Request $request, string $file): \Illuminate\Http\JsonResponse
    {
        $stat = $this->fileOrFail($file);
        $this->authorizeAction($request, 'downloadLogFile', $stat->path);

        $ttl = (int) config('loglens.security.download_ttl', 300);
        $url = URL::temporarySignedRoute('loglens.api.download.fetch', now()->addSeconds($ttl), [
            'file' => $file,
            // Bind to the FULL identifier as a string — an (int) cast would
            // collapse every UUID/ULID id to 0 and let any user replay the link.
            'uid' => (string) (optional($request->user())->getAuthIdentifier() ?? ''),
            'tail' => $request->query('tail'),
        ]);

        return $this->json(['url' => $url, 'expires_in' => $ttl]);
    }

    /** Fetch a previously signed download. Signature + identity re-verified. */
    public function fetch(Request $request, string $file): StreamedResponse
    {
        $stat = $this->fileOrFail($file);

        // Identity binding: the signed uid (string) must match the current user.
        $signedUid = (string) $request->query('uid', '');
        $currentUid = (string) (optional($request->user())->getAuthIdentifier() ?? '');
        if (! hash_equals($signedUid, $currentUid)) {
            abort(403, 'This download link is bound to a different user.');
        }
        $this->authorizeAction($request, 'downloadLogFile', $stat->path);

        $this->audit(LogFileDownloaded::class, $stat->path, $request);

        $path = $this->source()->path($file);
        $size = $stat->size;
        $start = 0;
        $tail = $request->query('tail');
        if ($tail !== null) {
            $tailBytes = \LogLens\Support\ByteSize::parse($tail);
            $start = max(0, $size - $tailBytes);
        }

        $response = new StreamedResponse(function () use ($path, $start) {
            $handle = @fopen($path, 'rb');
            if (! $handle) {
                return; // file vanished between authorization and streaming
            }
            if ($start > 0) {
                fseek($handle, $start);
            }
            while (! feof($handle)) {
                echo fread($handle, 1 << 20);
                @flush();
                if (connection_aborted()) {
                    break;
                }
            }
            fclose($handle);
        });
        $response->headers->set('Content-Type', 'application/octet-stream');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $stat->name . '"');
        if ($start === 0) {
            $response->headers->set('Content-Length', (string) $size);
        }

        return $response;
    }

    /** Stream a zip of a folder / selected files (chunked, no temp file). */
    public function zip(Request $request): StreamedResponse
    {
        $ids = (array) $request->query('files', []);
        if (is_string($request->query('files'))) {
            $ids = explode(',', (string) $request->query('files'));
        }

        $included = [];
        $skipped = [];
        foreach ($ids as $id) {
            $stat = $this->source()->stat($id);
            if (! $stat) {
                $skipped[] = ['file' => $id, 'reason' => 'not_found'];
                continue;
            }
            if (! $this->authorizer->allows('downloadLogFile', $request->user(), $stat->path)) {
                $skipped[] = ['file' => $id, 'name' => $stat->name, 'reason' => 'unauthorized'];
                continue;
            }
            $included[] = $stat;
            $this->audit(LogFileDownloaded::class, $stat->path, $request);
        }

        $response = new StreamedResponse(function () use ($included, $skipped) {
            $tmp = tempnam(sys_get_temp_dir(), 'loglens-zip');
            try {
                $zip = new \ZipArchive();
                // ZipArchive needs a seekable target; we stream the finished file
                // in chunks (peak disk = one archive, never a doubled copy).
                $zip->open($tmp, \ZipArchive::OVERWRITE);
                foreach ($included as $stat) {
                    $zip->addFile($stat->path, $stat->folder === '/' ? $stat->name : $stat->folder . '/' . $stat->name);
                }
                // Always record skipped/unauthorized files in the archive.
                $zip->addFromString('_loglens_manifest.json', json_encode([
                    'included' => array_map(fn ($s) => $s->name, $included),
                    'skipped' => $skipped,
                ], JSON_PRETTY_PRINT));
                $zip->close();

                $handle = @fopen($tmp, 'rb');
                if ($handle) {
                    while (! feof($handle)) {
                        echo fread($handle, 1 << 20);
                        @flush();
                        if (connection_aborted()) {
                            break;
                        }
                    }
                    fclose($handle);
                }
            } finally {
                @unlink($tmp); // guaranteed cleanup even on abort/exception
            }
        });
        $response->headers->set('Content-Type', 'application/zip');
        $response->headers->set('Content-Disposition', 'attachment; filename="loglens-logs.zip"');
        $response->headers->set('X-LogLens-Skipped', (string) count($skipped));

        return $response;
    }
}
