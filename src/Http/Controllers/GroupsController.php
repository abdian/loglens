<?php

namespace LogLens\Http\Controllers;

use Illuminate\Http\Request;
use LogLens\Indexing\IndexManager;
use LogLens\Indexing\Level;
use LogLens\Indexing\SqliteIndexStore;
use LogLens\Security\Redactor;
use LogLens\Sources\LocalFileSource;
use LogLens\Support\Timestamp;

/**
 * Error-grouping (Issues) endpoints.
 * Sortable group list with sparklines, drill-to-occurrences (via the entries
 * endpoint's `group` filter), and "new since" diffing.
 */
class GroupsController extends Controller
{
    public function __construct(
        private IndexManager $manager,
        private LocalFileSource $files,
        private Redactor $redactor
    ) {
    }

    public function index(Request $request, string $file): \Illuminate\Http\JsonResponse
    {
        $store = $this->manager->store($this->identityOrFail($file));
        if (! $store instanceof SqliteIndexStore) {
            return $this->json(['groups' => [], 'supported' => false]);
        }

        $sort = $request->query('sort', 'last_ts');
        $dir = $request->query('direction', 'desc');
        $limit = max(1, min(500, (int) $request->query('limit', 100)));
        $newSince = $request->filled('since') ? Timestamp::parse((string) $request->query('since')) : null;

        $rows = $store->groups($sort, $dir, $limit, $newSince);
        $groups = array_map(function ($g) use ($store) {
            return [
                // 64-bit fingerprint as a STRING: JS can't hold it as a Number
                // without precision loss, which would break drill-in matching.
                'fp' => (string) $g['fp'],
                'kind' => (int) $g['kind'],
                'count' => (int) $g['count'],
                'first_ts' => $g['first_ts'] !== null ? (int) $g['first_ts'] : null,
                'last_ts' => $g['last_ts'] !== null ? (int) $g['last_ts'] : null,
                'first_seen' => $g['first_ts'] !== null ? gmdate('Y-m-d\TH:i:s\Z', (int) $g['first_ts']) : null,
                'last_seen' => $g['last_ts'] !== null ? gmdate('Y-m-d\TH:i:s\Z', (int) $g['last_ts']) : null,
                'level' => Level::name((int) ($g['level'] ?? Level::ERROR)),
                'title' => $this->redactor->redact((string) $g['title']),
                'sample_seq' => $g['sample_seq'] !== null ? (int) $g['sample_seq'] : null,
                'sparkline' => $store->groupSparkline((int) $g['fp'], 24),
            ];
        }, $rows);

        return $this->json(['groups' => $groups, 'supported' => true]);
    }

    public function newSince(Request $request, string $file): \Illuminate\Http\JsonResponse
    {
        $store = $this->manager->store($this->identityOrFail($file));
        if (! $store instanceof SqliteIndexStore) {
            return $this->json(['groups' => [], 'count' => 0, 'supported' => false]);
        }

        $since = Timestamp::parse((string) $request->query('since'));
        if ($since === null) {
            return $this->error('bad_request', 'A valid "since" timestamp is required.', 422);
        }

        $rows = $store->groups('first_ts', 'desc', 500, $since);

        return $this->json([
            'count' => $store->newGroupsSince($since),
            'groups' => array_map(fn ($g) => [
                'fp' => (string) $g['fp'],
                'count' => (int) $g['count'],
                'title' => $this->redactor->redact((string) $g['title']),
                'first_seen' => $g['first_ts'] !== null ? gmdate('Y-m-d\TH:i:s\Z', (int) $g['first_ts']) : null,
            ], $rows),
            'supported' => true,
        ]);
    }
}
