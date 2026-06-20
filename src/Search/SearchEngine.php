<?php

namespace LogLens\Search;

use LogLens\Contracts\IndexStore;
use LogLens\Contracts\LogSource;
use LogLens\Indexing\IndexManager;
use LogLens\Indexing\Level;
use LogLens\Indexing\SqliteIndexStore;
use LogLens\Parsing\ParserManager;
use LogLens\Sources\LocalFileSource;
use LogLens\Support\Utf8;

/**
 * Search execution ladder.
 *
 *   FTS5+trigram → FTS5 unicode61 → SQL LIKE → streamed PCRE scan
 *
 * The FTS tier narrows candidates; the AstEvaluator confirms every candidate
 * and computes highlight ranges, so results are identical across tiers. Pure
 * level/time queries skip re-parsing entirely (answered from index columns).
 */
class SearchEngine
{
    public function __construct(
        private LogSource $source,
        private IndexManager $manager,
        private ParserManager $parsers,
        private array $config = []
    ) {
    }

    /**
     * @param  array  $filters  ['levels'=>int[], 'after'=>int, 'before'=>int]
     * @return array{entries:array,cursor:?int,tier:string,case_sensitive:bool}
     */
    public function search(string $fileId, string $query, array $filters = [], ?int $cursor = null, int $limit = 100, array $opts = []): array
    {
        $caseSensitive = $opts['case_sensitive'] ?? (bool) ($this->config['case_sensitive'] ?? false);
        $now = $opts['now'] ?? null;

        $ast = (new QueryParser($now))->parse($query);
        $evaluator = new AstEvaluator($caseSensitive);

        $identity = $this->source instanceof LocalFileSource ? $this->source->identity($fileId) : null;
        if (! $identity) {
            return ['entries' => [], 'cursor' => null, 'tier' => 'none', 'case_sensitive' => $caseSensitive];
        }

        $store = $this->manager->store($identity);
        $structured = $this->mergeStructured($ast, $filters);
        $tier = $this->tier($store);

        $parser = $this->parsers->get($store->getMeta('parser')) ?? $this->parsers->detect($this->sample($fileId));
        $name = $this->source instanceof LocalFileSource ? ($this->source->stat($fileId)?->name) : null;

        $results = [];
        $lastSeq = null;
        // Bound the brute-force scan/LIKE tier: a sparse query over a multi-GB
        // file otherwise reads + parses + regex-evaluates every entry with no
        // ceiling. Stop after the configured byte budget and tell the UI the
        // result is partial (it can "load more" to resume from $lastSeq).
        $scanCap = (int) ($this->config['pcre_scan_cap'] ?? 256 * 1024 * 1024);
        $bytesScanned = 0;
        $truncated = false;

        $candidates = $this->candidates($store, $ast, $structured, $cursor, $limit, $tier);
        foreach ($candidates as $entry) {
            $raw = $this->source->readRange($fileId, $entry->offset, $entry->length);
            $bytesScanned += strlen($raw);
            $parsed = $parser->parse($raw);
            $ctx = [
                'text' => $raw,
                // The evaluator confirms term/phrase matches against this
                // haystack, which mirrors the FTS-indexed superset (synthetic
                // timestamp/level/channel/env prefix + raw). Without it a
                // free-text query for a level name or date would surface FTS
                // candidates that the evaluator then rejects. Regex still runs
                // against raw 'text' so anchors keep their meaning.
                'haystack' => \LogLens\Indexing\Indexer::searchMetaPrefix($parsed) . $raw,
                'level' => $entry->level,
                'ts' => $entry->timestamp,
                'channel' => $parsed->channel,
                'env' => $parsed->environment,
                'file' => $name,
                'context' => array_merge($parsed->context ?? [], $parsed->extra ?? []),
            ];

            if (! $evaluator->matches($ast, $ctx)) {
                $lastSeq = $entry->seq;
                if ($bytesScanned >= $scanCap) {
                    $truncated = true;
                    break;
                }
                continue;
            }

            $results[] = [
                'entry' => $entry,
                'parsed' => $parsed,
                'raw' => Utf8::sanitize($raw),
            ];
            $lastSeq = $entry->seq;
            if (count($results) >= $limit) {
                break;
            }
            if ($bytesScanned >= $scanCap) {
                $truncated = true;
                break;
            }
        }

        return [
            'entries' => $results,
            // Hand back a cursor when the page is full OR the scan hit its cap,
            // so "load more" can resume in both cases.
            'cursor' => (count($results) >= $limit || $truncated) ? $lastSeq : null,
            'truncated' => $truncated,
            'tier' => $tier,
            'case_sensitive' => $caseSensitive,
            // Highlights are computed by the presenter against the final
            // (redacted, sanitized) message, not the raw text — so offsets align
            // with what the UI displays.
            'ast' => $ast,
        ];
    }

    /**
     * Lazily yield candidate IndexedEntry rows newest-first, narrowed by the
     * best available index path.
     *
     * @return iterable<\LogLens\Indexing\IndexedEntry>
     */
    private function candidates(IndexStore $store, Node $ast, array $structured, ?int $cursor, int $limit, string $tier): iterable
    {
        // FTS path (trigram) on the SQLite store.
        if ($store instanceof SqliteIndexStore && $tier === 'fts5_trigram') {
            $match = (new FtsCompiler(true))->compile($ast);
            if ($match !== null) {
                yield from $this->ftsCandidates($store, $match, $structured, $cursor, $limit);

                return;
            }
        }

        // Structured / scan path: page through entries applying index filters.
        yield from $this->pagedCandidates($store, $structured, $cursor);
    }

    private function ftsCandidates(SqliteIndexStore $store, string $match, array $structured, ?int $cursor, int $limit): iterable
    {
        $pdo = $store->pdo();
        [$sw, $sp] = $this->structuredSql($structured);
        $batch = max($limit * 4, 200);
        $lastSeq = $cursor;

        // Paginate by descending seq until a short page — a single bounded
        // batch could drop true matches that the evaluator would have kept
        // beyond the first window.
        while (true) {
            $where = ['fts MATCH :m', 'e.deleted = 0'];
            $params = [':m' => $match];
            if ($lastSeq !== null) {
                $where[] = 'e.seq < :cursor';
                $params[':cursor'] = $lastSeq;
            }
            $where = array_merge($where, $sw);
            $params = array_merge($params, $sp);

            $sql = 'SELECT e.* FROM fts JOIN entries e ON e.seq = fts.rowid WHERE ' . implode(' AND ', $where)
                . ' ORDER BY e.seq DESC LIMIT :batch';
            $stmt = $pdo->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v);
            }
            $stmt->bindValue(':batch', $batch, \PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $lastSeq = (int) $row['seq'];
                yield new \LogLens\Indexing\IndexedEntry(
                    seq: (int) $row['seq'], offset: (int) $row['offset'], length: (int) $row['length'],
                    timestamp: $row['ts'] !== null ? (int) $row['ts'] : null, level: (int) $row['level'],
                    fpApp: $row['fp_app'] !== null ? (int) $row['fp_app'] : null, title: $row['title'] ?? null
                );
            }

            if (count($rows) < $batch) {
                return;
            }
        }
    }

    private function pagedCandidates(IndexStore $store, array $structured, ?int $cursor): iterable
    {
        $pageSize = 500;
        $filters = [
            'levels' => $structured['levels'] ?? [],
            'after' => $structured['after'] ?? null,
            'before' => $structured['before'] ?? null,
        ];
        do {
            $page = $store->page($cursor, $pageSize, 'older', $filters);
            foreach ($page as $entry) {
                $cursor = $entry->seq;
                yield $entry;
            }
        } while (count($page) === $pageSize);
    }

    /** Pull level/time predicates out of the AST and merge explicit filters. */
    private function mergeStructured(Node $ast, array $filters): array
    {
        $out = ['levels' => $filters['levels'] ?? [], 'after' => $filters['after'] ?? null, 'before' => $filters['before'] ?? null];
        // Only hoist predicates that appear at the top conjunction (safe to push
        // into the index WHERE). OR/NOT contexts are left to the evaluator.
        $this->hoist($ast, $out);

        return $out;
    }

    private function hoist(Node $node, array &$out): void
    {
        if ($node->type === 'and') {
            foreach ($node->children as $c) {
                $this->hoist($c, $out);
            }
        } elseif ($node->type === 'time') {
            if ($node->prop('field') === 'after') {
                $out['after'] = max($out['after'] ?? 0, $node->prop('value'));
            } else {
                $out['before'] = min($out['before'] ?? PHP_INT_MAX, $node->prop('value'));
            }
        } elseif ($node->type === 'level' && $node->prop('op') === '=') {
            $ord = Level::ordinal($node->prop('value'));
            if (empty($out['levels'])) {
                $out['levels'] = [$ord];
            } else {
                // A query `level:` predicate AND a UI level filter both apply.
                // Intersect them at the index layer so the candidate set stays
                // consistent with what the evaluator confirms; an empty
                // intersection uses an impossible ordinal so the scan stays O(1)
                // instead of silently paging the UI-filtered set to no results.
                $intersection = array_values(array_intersect($out['levels'], [$ord]));
                $out['levels'] = $intersection ?: [-1];
            }
        }
    }

    private function structuredSql(array $structured): array
    {
        $where = [];
        $params = [];
        if (! empty($structured['levels'])) {
            $in = [];
            foreach (array_values($structured['levels']) as $i => $lvl) {
                $in[] = ":l$i";
                $params[":l$i"] = (int) $lvl;
            }
            $where[] = 'e.level IN (' . implode(',', $in) . ')';
        }
        if (! empty($structured['after'])) {
            $where[] = 'e.ts >= :sa';
            $params[':sa'] = (int) $structured['after'];
        }
        if (! empty($structured['before'])) {
            $where[] = 'e.ts <= :sb';
            $params[':sb'] = (int) $structured['before'];
        }

        return [$where, $params];
    }

    private function sample(string $fileId): array
    {
        $head = $this->source->readRange($fileId, 0, 65536);
        $lines = preg_split('/\r?\n/', $head) ?: [];

        return array_slice(array_filter($lines, fn ($l) => $l !== ''), 0, 50);
    }

    private function tier(IndexStore $store): string
    {
        if ($store instanceof SqliteIndexStore) {
            $caps = $store->capabilities();

            return $caps['tier'] ?? 'like';
        }

        return 'scan';
    }
}
