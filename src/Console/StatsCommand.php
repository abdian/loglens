<?php

namespace LogLens\Console;

use Illuminate\Console\Command;
use LogLens\Indexing\Indexer;
use LogLens\Indexing\IndexManager;
use LogLens\Indexing\Level;
use LogLens\Sources\LocalFileSource;
use LogLens\Support\ByteSize;

/**
 * `loglens:stats` — per-file entry counts, level distribution, date span, size
 * and index status from aggregates.
 */
class StatsCommand extends Command
{
    protected $signature = 'loglens:stats {--json : Output JSON}';

    protected $description = 'Summarize indexed log files';

    public function handle(LocalFileSource $source, IndexManager $manager, Indexer $indexer): int
    {
        $rows = [];
        foreach ($source->list() as $stat) {
            $identity = $source->identity($stat->id);
            $store = $manager->store($identity);
            if ($store->count() === 0) {
                $indexer->index($stat->id);
                $store = $manager->store($identity);
            }
            $statsData = $store->stats();
            $byLevel = [];
            foreach ($statsData['levels'] as $ord => $count) {
                $byLevel[Level::name($ord)] = $count;
            }
            $hours = array_keys($statsData['buckets']);
            $rows[] = [
                'file' => $stat->name,
                'entries' => $store->count(),
                'errors' => ($byLevel['error'] ?? 0) + ($byLevel['critical'] ?? 0),
                'warnings' => $byLevel['warning'] ?? 0,
                'size' => ByteSize::format($stat->size),
                'span' => empty($hours) ? '—' : gmdate('Y-m-d', min($hours)) . ' → ' . gmdate('Y-m-d', max($hours)),
                'index' => $manager->indexState($store, $stat->size)['state'],
            ];
        }

        if ($this->option('json')) {
            $this->line(json_encode($rows, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE));

            return self::SUCCESS;
        }

        $this->table(['File', 'Entries', 'Errors', 'Warnings', 'Size', 'Date span', 'Index'], array_map('array_values', $rows));

        return self::SUCCESS;
    }
}
