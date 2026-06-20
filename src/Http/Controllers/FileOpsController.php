<?php

namespace LogLens\Http\Controllers;

use Illuminate\Http\Request;
use LogLens\Events\LogEntryDeleted;
use LogLens\Events\LogFileCleared;
use LogLens\Events\LogFileDeleted;
use LogLens\FileManagement\FileManager;
use LogLens\Indexing\IndexManager;
use LogLens\Security\Authorizer;

/**
 * Destructive file operations.
 * POST/DELETE + CSRF (web middleware), per-action gates + kill switches +
 * read_only, audit events, and per-file structured batch results.
 */
class FileOpsController extends Controller
{
    public function __construct(
        private FileManager $manager,
        private Authorizer $authorizer,
        private IndexManager $indexes
    ) {
    }

    public function clear(Request $request, string $file): \Illuminate\Http\JsonResponse
    {
        $stat = $this->fileOrFail($file);
        $this->authorizeAction($request, 'clearLogFile', $stat->path);

        $result = $this->manager->clear($file);
        if ($result['ok']) {
            $this->audit(LogFileCleared::class, $stat->path, $request);
        }

        return $this->json($result, $result['ok'] ? 200 : 422);
    }

    public function delete(Request $request, string $file): \Illuminate\Http\JsonResponse
    {
        $stat = $this->fileOrFail($file);
        $this->authorizeAction($request, 'deleteLogFile', $stat->path);

        $result = $this->manager->delete($file);
        if ($result['ok']) {
            $this->audit(LogFileDeleted::class, $stat->path, $request);
        }

        return $this->json($result, $result['ok'] ? 200 : 422);
    }

    public function deleteEntry(Request $request, string $file, int $seq): \Illuminate\Http\JsonResponse
    {
        $stat = $this->fileOrFail($file);
        $this->authorizeAction($request, 'deleteLogEntry', $stat->path);

        $identity = $this->source()->identity($file);
        if (! $identity) {
            return $this->json(['ok' => false, 'error' => 'Entry not found.'], 404);
        }

        $store = $this->indexes->store($identity);
        try {
            if (! $store->supportsSoftDelete()) {
                return $this->json(['ok' => false, 'error' => 'Single-entry delete requires the SQLite index.'], 422);
            }
            if (! $store->softDelete($seq)) {
                return $this->json(['ok' => false, 'error' => 'Entry not found.'], 404);
            }
        } finally {
            $store->close();
        }

        $this->audit(LogEntryDeleted::class, $stat->path, $request);

        return $this->json(['ok' => true, 'seq' => $seq], 200);
    }

    public function batch(Request $request): \Illuminate\Http\JsonResponse
    {
        $op = $request->input('op') === 'delete' ? 'delete' : 'clear';
        $ability = $op === 'delete' ? 'deleteLogFile' : 'clearLogFile';
        $ids = (array) $request->input('files', []);

        if ($this->authorizer->readOnly() || ! $this->authorizer->killSwitch($ability)) {
            return $this->error('forbidden', 'This operation is disabled by configuration.', 403);
        }

        $result = $this->manager->batch($ids, $op, function ($fileId, $stat) use ($request, $ability) {
            return $this->authorizer->allows($ability, $request->user(), $stat->path);
        });

        foreach ($result['succeeded'] as $s) {
            $event = $op === 'delete' ? LogFileDeleted::class : LogFileCleared::class;
            $this->audit($event, $s['name'], $request);
        }

        return $this->json($result);
    }

    public function writability(string $file): \Illuminate\Http\JsonResponse
    {
        $this->fileOrFail($file);

        return $this->json($this->manager->writability($file));
    }
}
