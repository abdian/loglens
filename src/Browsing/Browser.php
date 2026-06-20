<?php

namespace LogLens\Browsing;

use LogLens\Http\EntryPresenter;
use LogLens\Indexing\IndexCoordinator;
use LogLens\Indexing\IndexedEntry;
use LogLens\Indexing\IndexManager;
use LogLens\Indexing\Level;
use LogLens\Indexing\TailReader;
use LogLens\Parsing\ParserManager;
use LogLens\Sources\LocalFileSource;

/**
 * Read-side orchestration for browsing. Ties the index store,
 * tail-first reader, coordinator and presenter together so controllers stay
 * thin. Every file-scoped response carries `indexState` for progressive
 * enhancement.
 */
class Browser
{
    public function __construct(
        private LocalFileSource $source,
        private IndexManager $manager,
        private IndexCoordinator $coordinator,
        private TailReader $tailReader,
        private ParserManager $parsers,
        private EntryPresenter $presenter
    ) {
    }

    /** Folder-grouped file list with size/mtime and per-file index status. */
    public function files(array $sort = []): array
    {
        $files = $this->source->list();
        $by = $sort['by'] ?? 'mtime';
        $dir = ($sort['direction'] ?? 'desc') === 'asc' ? 1 : -1;
        usort($files, function ($a, $b) use ($by, $dir) {
            $av = $a->{$by} ?? $a->mtime;
            $bv = $b->{$by} ?? $b->mtime;

            return ($av <=> $bv) * $dir;
        });

        $folders = [];
        foreach ($files as $f) {
            $folders[$f->folder][] = $f->toArray();
        }

        return [
            'folders' => $folders,
            'files' => array_map(fn ($f) => $f->toArray(), $files),
        ];
    }

    /**
     * Open a file: newest page (index-free when needed) + indexState. Kicks off
     * background indexing without blocking.
     */
    public function open(string $fileId, int $limit = 100): array
    {
        $state = $this->coordinator->ensure($fileId);
        $stat = $this->source->stat($fileId);

        if ($state['state'] === 'ready' || $state['entries'] > 0) {
            $page = $this->page($fileId, null, $limit, 'older');
            $page['index'] = $state;
            $page['file'] = $stat?->toArray();

            return $page;
        }

        // Index not ready yet → tail-first, index-free newest page.
        $tail = $this->tailReader->newestPage($fileId, $limit);
        $entries = [];
        foreach ($tail['entries'] as $t) {
            $entries[] = $this->presenter->present(
                new IndexedEntry(0, $t['offset'], $t['length'], $t['entry']->timestamp, $t['entry']->level),
                $t['entry'],
                ['raw' => $t['raw']]
            );
        }

        return [
            'entries' => $entries,
            'cursor' => ['older' => null, 'newer' => null],
            'index' => $state,
            'index_free' => true,
            'file' => $stat?->toArray(),
        ];
    }

    /** A keyset page of entries in the given direction with optional filters. */
    public function page(string $fileId, ?int $cursor, int $limit, string $direction = 'older', array $filters = []): array
    {
        $identity = $this->source->identity($fileId);
        $store = $this->manager->store($identity);
        $rows = $store->page($cursor, $limit, $direction, $filters);

        $entries = array_map(fn (IndexedEntry $e) => $this->readPresent($fileId, $e), $rows);

        $olderCursor = ! empty($rows) ? min(array_map(fn ($e) => $e->seq, $rows)) : null;
        $newerCursor = ! empty($rows) ? max(array_map(fn ($e) => $e->seq, $rows)) : null;
        $hasMore = count($rows) >= $limit;

        // Gate the SAME-direction cursor on a full page (more entries that way);
        // the opposite-direction cursor is the boundary seq for scrolling back.
        return [
            'entries' => $entries,
            'cursor' => [
                'older' => ($direction === 'older' ? $hasMore : true) ? $olderCursor : null,
                'newer' => ($direction === 'newer' ? $hasMore : true) ? $newerCursor : null,
            ],
            'index' => ['state' => $this->manager->indexState($store, $identity->size)['state']],
        ];
    }

    public function entry(string $fileId, int $seq, bool $full = false): ?array
    {
        $identity = $this->source->identity($fileId);
        $store = $this->manager->store($identity);
        $row = $store->find($seq);

        return $row ? $this->readPresent($fileId, $row, $full) : null;
    }

    /** Fetch a raw range (the "expand" path for truncated entries). */
    public function expand(string $fileId, int $offset, int $length): array
    {
        $raw = $this->source->readRange($fileId, $offset, $length);
        $parser = $this->parserFor($fileId);

        return $this->presenter->present(null, $parser->parse($raw), ['raw' => $raw, 'full' => true]);
    }

    /** Jump to the first entry at-or-after a timestamp; returns its page. */
    public function jumpToTimestamp(string $fileId, int $ts, int $limit = 100): array
    {
        $identity = $this->source->identity($fileId);
        $store = $this->manager->store($identity);
        $seq = $store->seekTimestamp($ts);
        if ($seq === null) {
            return $this->page($fileId, null, $limit, 'older');
        }

        // Center the page on the target: page newer-and-including from seq-1.
        $page = $this->page($fileId, $seq - 1, $limit, 'newer');
        $page['anchor'] = $seq;

        return $page;
    }

    /** Resolve a permalink (file + seq) to the page containing the entry. */
    public function permalink(string $fileId, int $seq, int $limit = 100): array
    {
        $page = $this->page($fileId, max(0, $seq - intdiv($limit, 2)), $limit, 'newer');
        $page['selected'] = $seq;

        return $page;
    }

    /** Unfiltered surrounding entries centered on a seq (view-in-context). */
    public function context(string $fileId, int $seq, int $radius = 25): array
    {
        $identity = $this->source->identity($fileId);
        $store = $this->manager->store($identity);

        // One keyset page over [seq-radius, seq+radius] instead of 2*radius+1
        // individual find() round-trips. page('newer', cursor=lo-1) yields seqs
        // ≥ lo newest-first for both store tiers; reverse for chronological view.
        $lo = max(1, $seq - $radius);
        $hi = $seq + $radius;
        $rows = array_reverse($store->page($lo - 1, $hi - $lo + 1, 'newer'));

        $window = array_map(fn ($row) => $this->readPresent($fileId, $row), $rows);

        return ['entries' => $window, 'selected' => $seq];
    }

    public function levelCounts(string $fileId, array $filters = []): array
    {
        $identity = $this->source->identity($fileId);
        $store = $this->manager->store($identity);
        $stats = $store->stats($filters);
        $state = $this->manager->indexState($store, $identity->size);

        $named = [];
        foreach (Level::all() as $ord => $name) {
            $named[$name] = $stats['levels'][$ord] ?? 0;
        }

        return [
            'levels' => $named,
            // Counts are partial until indexing is fully ready (includes the
            // 'none' state, where nothing has been aggregated yet).
            'partial' => $state['state'] !== 'ready',
        ];
    }

    private function readPresent(string $fileId, IndexedEntry $entry, bool $full = false): array
    {
        $raw = $this->source->readRange($fileId, $entry->offset, $entry->length);
        $parser = $this->parserFor($fileId);

        return $this->presenter->present($entry, $parser->parse($raw), ['raw' => $raw, 'full' => $full]);
    }

    private function parserFor(string $fileId)
    {
        $identity = $this->source->identity($fileId);
        $store = $this->manager->store($identity);
        $id = $store->getMeta('parser');
        if ($id && ($p = $this->parsers->get($id))) {
            return $p;
        }
        $head = $this->source->readRange($fileId, 0, 65536);
        $lines = array_slice(array_filter(preg_split('/\r?\n/', $head) ?: [], fn ($l) => $l !== ''), 0, 50);

        return $this->parsers->detect($lines);
    }
}
