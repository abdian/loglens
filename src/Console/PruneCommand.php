<?php

namespace LogLens\Console;

use Illuminate\Console\Command;
use LogLens\Events\LogFilesPruned;
use LogLens\FileManagement\Pruner;

/**
 * `loglens:prune` — retention automation, schedulable. Prune decisions read from metadata;
 * actions are audited.
 */
class PruneCommand extends Command
{
    protected $signature = 'loglens:prune
        {--days= : Remove (or compress) files older than N days}
        {--max-total-size= : Remove oldest files until under this total (e.g. 10G)}
        {--keep-min=0 : Always keep at least N newest files per folder (survivor floor)}
        {--compress : Gzip instead of delete}
        {--dry-run : Show what would happen without changing anything}';

    protected $description = 'Prune old log files by age and/or total size';

    public function handle(Pruner $pruner): int
    {
        $result = $pruner->prune([
            'days' => $this->option('days') !== null ? (int) $this->option('days') : null,
            'max_total_size' => $this->option('max-total-size'),
            'keep_min' => (int) $this->option('keep-min'),
            'compress' => (bool) $this->option('compress'),
            'dry_run' => (bool) $this->option('dry-run'),
        ]);

        foreach ($result['actions'] as $a) {
            $verb = $this->option('dry-run') ? 'would ' . $a['action'] : ucfirst($a['action'] . 'd');
            $this->line("  {$verb}: {$a['name']}");
            if (! $this->option('dry-run') && $a['applied'] && ! $this->option('dry-run')) {
                event(new LogFilesPruned(null, $a['name']));
            }
        }

        $s = $result['summary'];
        $this->info(sprintf(
            '%s%d file(s): %d deleted, %d compressed.',
            $s['dry_run'] ? '[dry-run] ' : '',
            $s['count'],
            $s['deleted'],
            $s['compressed']
        ));

        return self::SUCCESS;
    }
}
