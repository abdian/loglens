<?php

namespace LogLens\Http\Controllers;

use Illuminate\Http\Request;
use LogLens\Indexing\IndexCoordinator;
use LogLens\Indexing\IndexManager;
use LogLens\Sources\LocalFileSource;

/**
 * Index management endpoints. These are gated by the SAME
 * authorization stack as every other route (index management is
 * never anonymous). Triggering indexing is rate-limited.
 */
class IndexController extends Controller
{
    public function __construct(
        private IndexCoordinator $coordinator,
        private IndexManager $manager,
        private LocalFileSource $source
    ) {
    }

    public function status(string $file): \Illuminate\Http\JsonResponse
    {
        $identity = $this->source->identity($file);
        if (! $identity) {
            return $this->error('not_found', 'File not found.', 404);
        }
        $store = $this->manager->store($identity);
        $state = $this->manager->indexState($store, $identity->size);

        return $this->json($state + ['driver' => $store->driver(), 'entries' => $store->count()]);
    }

    public function build(string $file): \Illuminate\Http\JsonResponse
    {
        $this->fileOrFail($file);

        return $this->json($this->coordinator->ensure($file));
    }

    public function rebuild(string $file): \Illuminate\Http\JsonResponse
    {
        $identity = $this->source->identity($file);
        if (! $identity) {
            return $this->error('not_found', 'File not found.', 404);
        }
        $this->manager->deleteIndex($identity);

        return $this->json($this->coordinator->ensure($file));
    }
}
