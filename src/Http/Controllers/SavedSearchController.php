<?php

namespace LogLens\Http\Controllers;

use Illuminate\Http\Request;
use LogLens\Search\SavedSearchStore;

/**
 * Saved searches CRUD. Stored in LogLens's own
 * storage, scoped per user.
 */
class SavedSearchController extends Controller
{
    public function __construct(private SavedSearchStore $store)
    {
    }

    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        return $this->json(['searches' => $this->store->all($this->uid($request))]);
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $search = $request->validate([
            'name' => 'required|string|max:120',
            'query' => 'nullable|string',
            'files' => 'nullable|array',
            'levels' => 'nullable|array',
            'after' => 'nullable|string',
            'before' => 'nullable|string',
        ]);

        return $this->json(['search' => $this->store->save($this->uid($request), $search)], 201);
    }

    public function destroy(Request $request, string $id): \Illuminate\Http\JsonResponse
    {
        $ok = $this->store->delete($this->uid($request), $id);

        return $this->json(['deleted' => $ok], $ok ? 200 : 404);
    }

    private function uid(Request $request)
    {
        return optional($request->user())->getAuthIdentifier();
    }
}
