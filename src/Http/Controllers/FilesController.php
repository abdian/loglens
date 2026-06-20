<?php

namespace LogLens\Http\Controllers;

use Illuminate\Http\Request;
use LogLens\Browsing\Browser;
use LogLens\Events\LogFileViewed;
use LogLens\Indexing\Level;
use LogLens\Support\Timestamp;

/**
 * File/folder discovery, tail-first open, keyset pagination, entry detail,
 * expand, jump-to-timestamp, permalink, view-in-context, level counts
 *.
 */
class FilesController extends Controller
{
    public function __construct(private Browser $browser)
    {
    }

    public function index(): \Illuminate\Http\JsonResponse
    {
        return $this->json($this->browser->files((array) config('loglens.sort')));
    }

    public function open(Request $request, string $file): \Illuminate\Http\JsonResponse
    {
        $stat = $this->fileOrFail($file);
        $this->audit(LogFileViewed::class, $stat->path, $request);
        $limit = $this->limit($request);

        return $this->json($this->browser->open($file, $limit));
    }

    public function entries(Request $request, string $file): \Illuminate\Http\JsonResponse
    {
        $this->fileOrFail($file);
        $cursor = $request->filled('cursor') ? (int) $request->query('cursor') : null;
        $direction = $request->query('direction', 'older') === 'newer' ? 'newer' : 'older';

        return $this->json($this->browser->page($file, $cursor, $this->limit($request), $direction, $this->filters($request)));
    }

    public function entry(Request $request, string $file, int $seq): \Illuminate\Http\JsonResponse
    {
        $this->fileOrFail($file);
        $entry = $this->browser->entry($file, $seq, $request->boolean('full'));

        return $entry ? $this->json($entry) : $this->error('not_found', 'Entry not found.', 404);
    }

    public function expand(Request $request, string $file): \Illuminate\Http\JsonResponse
    {
        $this->fileOrFail($file);
        $offset = (int) $request->query('offset', 0);
        $length = (int) $request->query('length', 0);

        return $this->json($this->browser->expand($file, $offset, $length));
    }

    public function jump(Request $request, string $file): \Illuminate\Http\JsonResponse
    {
        $this->fileOrFail($file);
        $ts = Timestamp::parse((string) $request->query('at'));
        if ($ts === null) {
            return $this->error('bad_request', 'Invalid timestamp.', 422);
        }

        return $this->json($this->browser->jumpToTimestamp($file, $ts, $this->limit($request)));
    }

    public function permalink(Request $request, string $file, int $seq): \Illuminate\Http\JsonResponse
    {
        $this->fileOrFail($file);

        return $this->json($this->browser->permalink($file, $seq, $this->limit($request)));
    }

    public function context(Request $request, string $file, int $seq): \Illuminate\Http\JsonResponse
    {
        $this->fileOrFail($file);

        return $this->json($this->browser->context($file, $seq, (int) $request->query('radius', 25)));
    }

    public function levels(Request $request, string $file): \Illuminate\Http\JsonResponse
    {
        $this->fileOrFail($file);

        return $this->json($this->browser->levelCounts($file, $this->filters($request)));
    }

    private function limit(Request $request): int
    {
        return max(1, min(500, (int) $request->query('limit', 100)));
    }

    private function filters(Request $request): array
    {
        $filters = [];
        if ($request->filled('levels')) {
            $names = is_array($request->query('levels')) ? $request->query('levels') : explode(',', (string) $request->query('levels'));
            $filters['levels'] = array_map(fn ($n) => Level::ordinal($n), $names);
        }
        if ($request->filled('after')) {
            $filters['after'] = Timestamp::parse((string) $request->query('after'));
        }
        if ($request->filled('before')) {
            $filters['before'] = Timestamp::parse((string) $request->query('before'));
        }
        if ($request->filled('group')) {
            $filters['group'] = (int) $request->query('group');
        }

        return array_filter($filters, fn ($v) => $v !== null);
    }
}
