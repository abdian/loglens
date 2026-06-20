<?php

namespace LogLens\FileManagement;

use LogLens\Indexing\IndexManager;
use LogLens\Sources\LocalFileSource;
use LogLens\Support\ByteSize;

/**
 * Retention automation. Prune decisions read
 * from file metadata (size/mtime) without re-reading contents. Supports
 * --days, --max-total-size, --compress (gzip instead of delete) and --dry-run.
 */
class Pruner
{
    public function __construct(
        private LocalFileSource $source,
        private IndexManager $manager,
        private FileManager $files
    ) {
    }

    /**
     * @param  array{days?:int,max_total_size?:string|int,compress?:bool,dry_run?:bool}  $opts
     * @return array{actions:array,summary:array}
     */
    public function prune(array $opts = []): array
    {
        $days = $opts['days'] ?? null;
        $maxTotal = isset($opts['max_total_size']) ? ByteSize::parse($opts['max_total_size']) : null;
        $compress = (bool) ($opts['compress'] ?? false);
        $dryRun = (bool) ($opts['dry_run'] ?? false);
        $keepMin = max(0, (int) ($opts['keep_min'] ?? 0));

        $files = $this->source->list();
        // Oldest first for both age and size passes.
        usort($files, fn ($a, $b) => $a->mtime <=> $b->mtime);

        // Optional survivor floor: keep the $keepMin newest files per folder
        // regardless of age/budget, so a misconfigured retention job can't wipe
        // a quiet-but-active log (logrotate always keeps the current file).
        // Default 0 preserves explicit --days / --max-total-size behaviour.
        $protectedIds = [];
        if ($keepMin > 0) {
            $byFolder = [];
            foreach ($files as $f) {
                $byFolder[$f->folder][] = $f;
            }
            foreach ($byFolder as $group) {
                usort($group, fn ($a, $b) => $b->mtime <=> $a->mtime); // newest first
                foreach (array_slice($group, 0, $keepMin) as $keep) {
                    $protectedIds[] = $keep->id;
                }
            }
        }

        $actions = [];
        $now = time();
        $survivors = []; // [mtime, size, stat] still on disk after pass 1

        // Pass 1: age. Compressed files still occupy disk (smaller) and MUST be
        // counted toward the size budget; only deleted files leave the survivor
        // set.
        foreach ($files as $f) {
            if ($days !== null && ($now - $f->mtime) > $days * 86400 && ! $f->compressed
                && ! in_array($f->id, $protectedIds, true)) {
                $action = $compress ? 'compress' : 'delete';
                $actions[] = $this->act($f, $action, $dryRun);
                if ($action === 'compress') {
                    // Estimate the post-compression size (~25% for text logs);
                    // gz is then a survivor the budget pass can still prune.
                    $survivors[] = ['mtime' => $f->mtime, 'size' => (int) ($f->size * 0.25), 'stat' => $f, 'compressed' => true];
                }
                continue;
            }
            $survivors[] = ['mtime' => $f->mtime, 'size' => $f->size, 'stat' => $f, 'compressed' => $f->compressed];
        }

        // Pass 2: total size budget (delete oldest survivors until under).
        if ($maxTotal !== null) {
            usort($survivors, fn ($a, $b) => $a['mtime'] <=> $b['mtime']);
            $total = array_sum(array_column($survivors, 'size'));
            foreach ($survivors as $s) {
                if ($total <= $maxTotal) {
                    break;
                }
                // Keep the per-folder survivor floor in the size pass too.
                if (in_array($s['stat']->id, $protectedIds, true)) {
                    continue;
                }
                // A file compressed this run was already actioned; deleting the
                // resulting .gz on the same pass would need a re-resolve, so we
                // only delete survivors that were not just compressed.
                if (! empty($s['compressed']) && $s['stat']->compressed === false) {
                    continue;
                }
                $actions[] = $this->act($s['stat'], 'delete', $dryRun);
                $total -= $s['size'];
            }
        }

        return [
            'actions' => $actions,
            'summary' => [
                'count' => count($actions),
                'deleted' => count(array_filter($actions, fn ($a) => $a['action'] === 'delete')),
                'compressed' => count(array_filter($actions, fn ($a) => $a['action'] === 'compress')),
                'dry_run' => $dryRun,
            ],
        ];
    }

    private function act($stat, string $action, bool $dryRun): array
    {
        $result = ['file' => $stat->id, 'name' => $stat->name, 'action' => $action, 'size' => $stat->size, 'applied' => false];
        if ($dryRun) {
            return $result;
        }

        if ($action === 'compress') {
            $result['applied'] = $this->compress($stat->path);
        } else {
            $del = $this->files->delete($stat->id);
            $result['applied'] = $del['ok'];
            if (! $del['ok']) {
                $result['error'] = $del['message'];
            }
        }

        return $result;
    }

    private function compress(string $path): bool
    {
        $gz = $path . '.gz';
        $sizeBefore = @filesize($path);
        $mtimeBefore = @filemtime($path);

        $in = @fopen($path, 'rb');
        $out = @gzopen($gz, 'wb9');
        if (! $in || ! $out) {
            if ($out) {
                gzclose($out);
                @unlink($gz);
            }
            if ($in) {
                fclose($in);
            }

            return false;
        }
        while (! feof($in)) {
            gzwrite($out, fread($in, 1 << 20));
        }
        fclose($in);
        gzclose($out);

        // If the source changed while we were reading it (an active writer
        // appended), the .gz is only a partial snapshot — discard it and keep the
        // original intact rather than unlink it and lose the appended tail.
        clearstatcache(true, $path);
        if (@filesize($path) !== $sizeBefore || @filemtime($path) !== $mtimeBefore) {
            @unlink($gz);

            return false;
        }

        if (is_file($gz)) {
            @unlink($path);

            return true;
        }

        return false;
    }
}
