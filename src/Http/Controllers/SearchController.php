<?php

namespace LogLens\Http\Controllers;

use Illuminate\Http\Request;
use LogLens\Http\EntryPresenter;
use LogLens\Indexing\Level;
use LogLens\Search\ParseException;
use LogLens\Search\SearchEngine;
use LogLens\Support\Timestamp;

/**
 * Search endpoint. Returns presented entries with
 * server-computed highlight offsets, the active execution tier, and a keyset
 * cursor. Query parse errors return a structured 422 with the error position,
 * never a 500.
 */
class SearchController extends Controller
{
    public function __construct(
        private SearchEngine $engine,
        private EntryPresenter $presenter
    ) {
    }

    public function search(Request $request, string $file): \Illuminate\Http\JsonResponse
    {
        $this->fileOrFail($file);
        $query = (string) $request->query('q', '');
        $cursor = $request->filled('cursor') ? (int) $request->query('cursor') : null;
        $limit = max(1, min(500, (int) $request->query('limit', 100)));

        try {
            $result = $this->engine->search($file, $query, $this->filters($request), $cursor, $limit, [
                'case_sensitive' => $request->boolean('case_sensitive'),
            ]);
        } catch (ParseException $e) {
            return $this->json(['error' => $e->toArray()], 422);
        }

        $entries = array_map(function ($r) use ($result) {
            return $this->presenter->present($r['entry'], $r['parsed'], [
                'raw' => $r['raw'],
                'highlight_node' => $result['ast'],
                'case_sensitive' => $result['case_sensitive'],
            ]);
        }, $result['entries']);

        return $this->json([
            'entries' => $entries,
            'cursor' => ['older' => $result['cursor']],
            'tier' => $result['tier'],
            'case_sensitive' => $result['case_sensitive'],
            'count' => count($entries),
            // True when the scan hit its byte budget before exhausting the file —
            // the UI shows a "partial results, load more to continue" hint.
            'truncated' => $result['truncated'] ?? false,
        ]);
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

        return array_filter($filters, fn ($v) => $v !== null);
    }
}
