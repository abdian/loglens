<?php

namespace LogLens\Console;

use Illuminate\Console\Command;
use LogLens\Indexing\Indexer;
use LogLens\Indexing\Level;
use LogLens\Search\ParseException;
use LogLens\Search\SearchEngine;
use LogLens\Sources\LocalFileSource;

/**
 * `loglens:search` — full query language against the shared index, with file
 * scoping and plain/JSON output.
 */
class SearchCommand extends Command
{
    protected $signature = 'loglens:search
        {query : The search query}
        {--file=* : Restrict to specific file ids or names}
        {--limit=100 : Max results per file}
        {--json : Output JSON}
        {--case-sensitive : Case-sensitive matching}';

    protected $description = 'Search indexed logs with the LogLens query language';

    public function handle(LocalFileSource $source, SearchEngine $engine, Indexer $indexer): int
    {
        $query = (string) $this->argument('query');
        $targets = $this->targets($source);
        $limit = (int) $this->option('limit');
        $results = [];

        foreach ($targets as $stat) {
            $indexer->index($stat->id); // ensure ready
            try {
                $res = $engine->search($stat->id, $query, [], null, $limit, [
                    'case_sensitive' => (bool) $this->option('case-sensitive'),
                ]);
            } catch (ParseException $e) {
                $this->error("Query error at position {$e->position}: {$e->getMessage()}");

                return self::FAILURE;
            }
            foreach ($res['entries'] as $r) {
                $results[] = [
                    'file' => $stat->name,
                    'seq' => $r['entry']->seq,
                    'datetime' => $r['parsed']->timestamp ? gmdate('Y-m-d H:i:s', $r['parsed']->timestamp) : null,
                    'level' => Level::name($r['entry']->level),
                    'message' => $r['parsed']->message,
                ];
            }
        }

        if ($this->option('json')) {
            $this->line(json_encode($results, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if (empty($results)) {
            $this->warn('No matches.');

            return self::SUCCESS;
        }

        foreach ($results as $r) {
            $this->line(sprintf('[%s] %s.%s: %s', $r['datetime'], $r['file'], strtoupper($r['level']), $this->truncate($r['message'])));
        }
        $this->info(count($results) . ' match(es).');

        return self::SUCCESS;
    }

    private function targets(LocalFileSource $source): array
    {
        $names = (array) $this->option('file');
        $all = $source->list();
        if (empty($names)) {
            return $all;
        }

        return array_values(array_filter($all, fn ($f) => in_array($f->id, $names, true) || in_array($f->name, $names, true)));
    }

    private function truncate(string $s, int $len = 160): string
    {
        $s = strtok($s, "\n");

        return mb_strlen($s) > $len ? mb_substr($s, 0, $len) . '…' : $s;
    }
}
