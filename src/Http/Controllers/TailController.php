<?php

namespace LogLens\Http\Controllers;

use Illuminate\Http\Request;
use LogLens\Diagnostics\Diagnostics;
use LogLens\Http\EntryPresenter;
use LogLens\Tail\Cursor;
use LogLens\Tail\TailEngine;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Live tail transports.
 *
 * SSE over a raw StreamedResponse (works on Laravel 8) with short windows,
 * heartbeats, buffer-drain + X-Accel-Buffering, cursor event ids and a clean
 * window-end event. Polling fallback returns only-new-bytes with ETag/304.
 * Both transports share the one cursor. Under Octane/Swoole the server forces
 * polling (streamed responses buffer there).
 */
class TailController extends Controller
{
    public function __construct(
        private TailEngine $engine,
        private EntryPresenter $presenter,
        private Diagnostics $diagnostics
    ) {
    }

    /** Advertise the active transport + detection reasons (diagnostics panel). */
    public function info(Request $request): \Illuminate\Http\JsonResponse
    {
        $runtime = $this->diagnostics->runtime();

        return $this->json([
            'transport' => $runtime['force_polling'] ? 'polling' : 'sse',
            'force_polling' => $runtime['force_polling'],
            'runtime' => $runtime,
            'window_seconds' => (int) config('loglens.tail.window_seconds', 45),
            'heartbeat_seconds' => (int) config('loglens.tail.heartbeat_seconds', 15),
            'poll_active_ms' => (int) config('loglens.tail.poll_active_ms', 2000),
            'poll_idle_ms' => (int) config('loglens.tail.poll_idle_ms', 10000),
        ]);
    }

    public function stream(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        // Under Octane/Swoole a StreamedResponse buffers (info() already reports
        // transport='polling'); refuse the stream so a direct hit fails fast and
        // the client falls back to polling instead of hanging on a dead stream.
        if (! empty($this->diagnostics->runtime()['force_polling'])) {
            return $this->error('sse_unavailable', 'Streaming is disabled on this runtime; use the polling transport.', 409);
        }

        $fileIds = $this->fileIds($request);
        foreach ($fileIds as $id) {
            $this->fileOrFail($id);
        }
        $query = (string) $request->query('q', '');
        $cursors = $this->initialCursors($request, $fileIds);

        $window = (int) config('loglens.tail.window_seconds', 45);
        $heartbeat = (int) config('loglens.tail.heartbeat_seconds', 15);

        $response = new StreamedResponse(function () use ($fileIds, $cursors, $query, $window, $heartbeat) {
            $this->drainBuffers();
            $deadline = time() + $window;
            $lastBeat = time();

            while (time() < $deadline) {
                foreach ($fileIds as $id) {
                    $result = $this->engine->read($id, $cursors[$id] ?? null, $query);
                    $cursors[$id] = $result['cursor'];
                    if ($result['rotated']) {
                        $this->emit('rotation', $id, ['file' => $id], (string) $result['cursor']);
                    }
                    foreach ($result['entries'] as $e) {
                        $payload = $this->presenter->present(null, $e['parsed'], ['raw' => $e['raw']]);
                        $this->emit('entry:' . $id, $id, $payload, (string) $result['cursor']);
                    }
                }

                if (time() - $lastBeat >= $heartbeat) {
                    echo ": ping\n\n";
                    $this->flush();
                    $lastBeat = time();
                }

                if (connection_aborted()) {
                    break;
                }
                usleep(500000); // 0.5s tick
            }

            // Clean window end so the client reconnects from the carried cursor.
            $this->emit('window-end', 'window', ['cursors' => array_map('strval', $cursors)], '');
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache, no-transform');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Connection', 'keep-alive');

        return $response;
    }

    public function poll(Request $request): \Illuminate\Http\JsonResponse
    {
        $fileIds = $this->fileIds($request);
        foreach ($fileIds as $id) {
            $this->fileOrFail($id);
        }
        $query = (string) $request->query('q', '');
        $cursors = $this->initialCursors($request, $fileIds);

        // ETag idle short-circuit (single-file case).
        if (count($fileIds) === 1) {
            $etag = $this->source()->etag($fileIds[0]);
            if ($etag && $request->headers->get('If-None-Match') === $etag) {
                $cur = $cursors[$fileIds[0]] ?? null;
                $end = $this->engine->endCursor($fileIds[0]);
                if ($cur && $end && (string) $cur === (string) $end) {
                    return $this->json([], 304);
                }
            }
        }

        $out = [];
        $newCursors = [];
        $idle = true;
        foreach ($fileIds as $id) {
            $result = $this->engine->read($id, $cursors[$id] ?? null, $query);
            $newCursors[$id] = (string) $result['cursor'];
            foreach ($result['entries'] as $e) {
                $idle = false;
                $out[] = ['file' => $id, 'rotated' => $result['rotated']]
                    + $this->presenter->present(null, $e['parsed'], ['raw' => $e['raw']]);
            }
        }

        $response = $this->json([
            'entries' => $out,
            'cursors' => $newCursors,
            'idle' => $idle,
        ]);
        if (count($fileIds) === 1 && ($etag = $this->source()->etag($fileIds[0]))) {
            $response->headers->set('ETag', $etag);
        }

        return $response;
    }

    // ---- helpers -----------------------------------------------------------

    /** @return string[] */
    private function fileIds(Request $request): array
    {
        $files = $request->query('files', $request->query('file'));
        if (is_string($files)) {
            $files = explode(',', $files);
        }

        return array_values(array_filter((array) $files));
    }

    /** @return array<string,Cursor|null> */
    private function initialCursors(Request $request, array $fileIds): array
    {
        $cursors = [];
        $lastEventId = $request->headers->get('Last-Event-ID');
        $raw = $request->query('cursor', $lastEventId);

        // Per-file cursor map for multi-file resume: either `cursor[id]=..`
        // (array form) or a JSON `cursors={"id":".."}` param. Without this,
        // every file would restart at EOF on reconnect.
        $map = is_array($raw) ? $raw : [];
        if (empty($map) && $request->filled('cursors')) {
            $decoded = json_decode((string) $request->query('cursors'), true);
            if (is_array($decoded)) {
                $map = $decoded;
            }
        }

        foreach ($fileIds as $id) {
            $cursor = null;
            if (isset($map[$id]) && is_string($map[$id])) {
                $cursor = Cursor::parse($map[$id]);
            } elseif (is_string($raw) && count($fileIds) === 1) {
                // Single-file: a bare cursor string or the SSE Last-Event-ID.
                $cursor = Cursor::parse($raw);
            }
            // Default: start from the current end (only stream NEW entries).
            $cursors[$id] = $cursor ?? $this->engine->endCursor($id);
        }

        return $cursors;
    }

    private function emit(string $event, string $id, array $data, string $cursor): void
    {
        echo "event: {$event}\n";
        if ($cursor !== '') {
            echo "id: {$cursor}\n";
        }
        echo 'data: ' . json_encode($data, \LogLens\Support\Utf8::jsonFlags()) . "\n\n";
        $this->flush();
    }

    private function drainBuffers(): void
    {
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
    }

    private function flush(): void
    {
        if (function_exists('ob_flush')) {
            @ob_flush();
        }
        @flush();
    }
}
