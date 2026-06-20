<?php

namespace LogLens\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Gate;
use LogLens\Security\Authorizer;
use LogLens\Sources\LocalFileSource;
use LogLens\Support\FileStat;
use LogLens\Support\Utf8;
use Symfony\Component\HttpKernel\Exception\HttpException;

abstract class Controller extends BaseController
{
    protected function source(): LocalFileSource
    {
        return app(LocalFileSource::class);
    }

    /** Resolve an opaque file id to a stat, 404 on miss (path-guarded). */
    protected function fileOrFail(string $id): FileStat
    {
        $stat = $this->source()->stat($id);
        if (! $stat) {
            throw new HttpException(404, 'Log file not found.');
        }

        return $stat;
    }

    /**
     * Resolve an opaque file id to its FileIdentity, 404 on miss. Use before
     * IndexManager::store() so a file deleted mid-request returns a clean 404
     * instead of a TypeError 500 on a null identity.
     */
    protected function identityOrFail(string $id): \LogLens\Support\FileIdentity
    {
        $identity = $this->source()->identity($id);
        if (! $identity) {
            throw new HttpException(404, 'Log file not found.');
        }

        return $identity;
    }

    /** Authorize a per-action gate honoring config kill switches / read_only. */
    protected function authorizeAction(Request $request, string $ability, $argument = null): void
    {
        /** @var Authorizer $authorizer */
        $authorizer = app(Authorizer::class);
        if (! $authorizer->allows($ability, $request->user(), $argument)) {
            throw new HttpException(403, 'This action is not permitted.');
        }
    }

    protected function json($data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, [], Utf8::jsonFlags());
    }

    protected function error(string $code, string $message, int $status = 400, array $extra = []): JsonResponse
    {
        return $this->json(['error' => array_merge(['code' => $code, 'message' => $message], $extra)], $status);
    }

    protected function audit(string $event, string $path, ?Request $request = null): void
    {
        $request ??= request();
        if (class_exists($event)) {
            event(new $event(
                optional($request->user())->getAuthIdentifier(),
                $path,
                $request->ip()
            ));
        }
    }
}
