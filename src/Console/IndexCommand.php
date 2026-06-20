<?php

namespace LogLens\Console;

use Illuminate\Console\Command;
use LogLens\Indexing\Indexer;
use LogLens\Indexing\IndexManager;
use LogLens\Sources\LocalFileSource;

/**
 * `loglens:index` — build/update indexes sharing the exact format used by the
 * web layer.
 */
class IndexCommand extends Command
{
    protected $signature = 'loglens:index
        {file?* : Specific file ids or names to index (default: all discovered)}
        {--watch : Continuously index appended bytes}
        {--prune-orphans : Drop indexes for files that no longer exist}
        {--interval=2 : Seconds between watch passes}';

    protected $description = 'Build or update LogLens indexes for log files';

    public function handle(LocalFileSource $source, Indexer $indexer, IndexManager $manager): int
    {
        if ($this->option('prune-orphans')) {
            $liveKeys = array_map(fn ($f) => $source->identity($f->id)->key(), $source->list());
            $pruned = $manager->pruneOrphans($liveKeys);
            $this->info("Pruned {$pruned} orphan index(es).");
        }

        do {
            $targets = $this->resolveTargets($source);
            if (empty($targets)) {
                $this->warn('No matching log files found.');

                return self::SUCCESS;
            }

            foreach ($targets as $stat) {
                $status = $indexer->index($stat->id);
                $this->line(sprintf(
                    '  <info>%s</info> — %s, %d entries (%s)',
                    $stat->name,
                    $status['state'],
                    $status['entries'],
                    $status['driver']
                ));
            }
            $manager->enforceBudget();

            if ($this->option('watch')) {
                usleep((int) $this->option('interval') * 1000000);
            }
        } while ($this->option('watch') && ! $this->shouldStop());

        return self::SUCCESS;
    }

    private function resolveTargets(LocalFileSource $source): array
    {
        $names = (array) $this->argument('file');
        $all = $source->list();
        if (empty($names)) {
            return $all;
        }

        return array_values(array_filter($all, fn ($f) => in_array($f->id, $names, true) || in_array($f->name, $names, true)));
    }

    private function shouldStop(): bool
    {
        return false;
    }
}
