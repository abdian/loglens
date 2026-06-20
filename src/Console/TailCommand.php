<?php

namespace LogLens\Console;

use Illuminate\Console\Command;
use LogLens\Indexing\Level;
use LogLens\Sources\LocalFileSource;
use LogLens\Tail\Cursor;
use LogLens\Tail\TailEngine;

/**
 * `loglens:tail` — stream new entries to the terminal using the same
 * rotation-aware engine and query filtering as the browser tail. No PCNTL;
 * works on Windows.
 */
class TailCommand extends Command
{
    protected $signature = 'loglens:tail
        {file? : File id or name to tail (default: newest discovered)}
        {--query= : Filter with the LogLens query language}
        {--interval=1 : Poll interval in seconds}';

    protected $description = 'Tail a log file live in the terminal';

    public function handle(LocalFileSource $source, TailEngine $engine): int
    {
        $stat = $this->resolve($source);
        if (! $stat) {
            $this->error('No log file to tail.');

            return self::FAILURE;
        }

        $query = $this->option('query');
        $cursor = $engine->endCursor($stat->id);
        $this->info("Tailing {$stat->name} (Ctrl+C to stop)…");

        while (true) {
            $result = $engine->read($stat->id, $cursor, $query);
            $cursor = $result['cursor'];
            if ($result['rotated']) {
                $this->comment('-- file rotated --');
            }
            foreach ($result['entries'] as $e) {
                $p = $e['parsed'];
                $dt = $p->timestamp ? gmdate('H:i:s', $p->timestamp) : '--:--:--';
                $this->line(sprintf('[%s] %s: %s', $dt, strtoupper(Level::name($p->level)), strtok($p->message, "\n")));
            }
            usleep((int) $this->option('interval') * 1000000);
        }
    }

    private function resolve(LocalFileSource $source)
    {
        $files = $source->list();
        if (empty($files)) {
            return null;
        }
        $name = $this->argument('file');
        if ($name) {
            foreach ($files as $f) {
                if ($f->id === $name || $f->name === $name) {
                    return $f;
                }
            }

            return null;
        }
        usort($files, fn ($a, $b) => $b->mtime <=> $a->mtime);

        return $files[0];
    }
}
