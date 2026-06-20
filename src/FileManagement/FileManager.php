<?php

namespace LogLens\FileManagement;

use LogLens\Indexing\IndexManager;
use LogLens\Sources\LocalFileSource;
use LogLens\Support\FileIdentity;

/**
 * File operations with correct Unix semantics.
 *
 * Clear = fopen('r+') + LOCK_EX + ftruncate(0) (inode-preserving, safe for
 * O_APPEND writers) + transactional index reset + head-fingerprint recompute.
 * Delete = unlink + identity-keyed index tombstone. Failures report the precise
 * cause, distinguishing write-on-file (truncate) from write-on-directory
 * (unlink).
 */
class FileManager
{
    public function __construct(
        private LocalFileSource $source,
        private IndexManager $manager
    ) {
    }

    /**
     * @return array{ok:bool, reason:string, message:string}
     */
    public function clear(string $fileId): array
    {
        $path = $this->source->path($fileId);
        if (! $path || ! is_file($path)) {
            return $this->fail('not_found', 'File not found.');
        }
        if (! is_writable($path)) {
            return $this->fail('failed_permission', $this->permissionHint($path, 'clear'));
        }

        $handle = @fopen($path, 'r+');
        if (! $handle) {
            return $this->fail('failed_locked', 'Could not open the file for writing (locked or permission denied).');
        }

        try {
            if (! @flock($handle, LOCK_EX)) {
                return $this->fail('failed_locked', 'Could not acquire an exclusive lock (another process holds it).');
            }
            ftruncate($handle, 0);
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        // Transactionally reset the index and recompute the head fingerprint.
        $identity = FileIdentity::forPath($path);
        $store = $this->manager->store($identity);
        $store->reset();
        $store->setMeta('identity', $identity->toArray());
        $store->setLastIndexedOffset(0);
        if (method_exists($store, 'commitBatch')) {
            $store->commitBatch();
        }
        $store->close();

        return ['ok' => true, 'reason' => 'cleared', 'message' => 'File cleared.'];
    }

    /**
     * @return array{ok:bool, reason:string, message:string}
     */
    public function delete(string $fileId): array
    {
        $path = $this->source->path($fileId);
        if (! $path || ! is_file($path)) {
            return $this->fail('not_found', 'File not found.');
        }

        $dir = dirname($path);
        if (! is_writable($dir)) {
            return $this->fail('failed_permission', $this->permissionHint($path, 'delete'));
        }

        $identity = FileIdentity::forPath($path);
        if (! @unlink($path)) {
            return $this->fail('failed_locked', 'Could not delete the file (locked or in use).');
        }

        // Tombstone + drop the index so a same-path recreation indexes fresh.
        $this->manager->tombstone($identity);
        $this->manager->deleteIndex($identity);

        return ['ok' => true, 'reason' => 'deleted', 'message' => 'File deleted.'];
    }

    /**
     * Batch operation with per-file structured results — never a silent skip.
     *
     * @param  string[]  $fileIds
     * @return array{succeeded:array,failed:array,summary:array}
     */
    public function batch(array $fileIds, string $op, callable $authorize): array
    {
        $succeeded = [];
        $failed = [];

        foreach ($fileIds as $fileId) {
            $stat = $this->source->stat($fileId);
            if (! $stat) {
                $failed[] = ['file' => $fileId, 'reason' => 'not_found', 'message' => 'Not found.'];
                continue;
            }
            if (! $authorize($fileId, $stat)) {
                $failed[] = ['file' => $fileId, 'name' => $stat->name, 'reason' => 'skipped_unauthorized', 'message' => 'Not authorized.'];
                continue;
            }

            $result = $op === 'delete' ? $this->delete($fileId) : $this->clear($fileId);
            if ($result['ok']) {
                $succeeded[] = ['file' => $fileId, 'name' => $stat->name];
            } else {
                $failed[] = ['file' => $fileId, 'name' => $stat->name, 'reason' => $result['reason'], 'message' => $result['message']];
            }
        }

        return [
            'succeeded' => $succeeded,
            'failed' => $failed,
            'summary' => [
                'succeeded' => count($succeeded),
                'skipped_unauthorized' => count(array_filter($failed, fn ($f) => $f['reason'] === 'skipped_unauthorized')),
                'failed_locked' => count(array_filter($failed, fn ($f) => $f['reason'] === 'failed_locked')),
                'failed_permission' => count(array_filter($failed, fn ($f) => $f['reason'] === 'failed_permission')),
            ],
        ];
    }

    /** Preflight: which operations are possible given file/dir writability. */
    public function writability(string $fileId): array
    {
        $path = $this->source->path($fileId);
        if (! $path) {
            return ['can_clear' => false, 'can_delete' => false];
        }

        return [
            'can_clear' => is_writable($path),          // write-on-file
            'can_delete' => is_writable(dirname($path)), // write-on-directory
        ];
    }

    private function permissionHint(string $path, string $op): string
    {
        if ($op === 'delete') {
            return sprintf(
                'Delete requires write permission on the directory (%s), not the file. The PHP process user likely differs from the file/dir owner.',
                dirname($path)
            );
        }

        return sprintf('Clear requires write permission on the file (%s). It is owned by another user and not writable by the PHP process.', basename($path));
    }

    private function fail(string $reason, string $message): array
    {
        return ['ok' => false, 'reason' => $reason, 'message' => $message];
    }
}
