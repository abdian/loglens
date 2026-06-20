<?php

namespace LogLens\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use LogLens\Security\Authorizer;
use Symfony\Component\HttpFoundation\Response;

/**
 * The single authorization gate applied IDENTICALLY to web, JSON API and
 * SSE/streaming routes. Because every route class runs
 * the same middleware, the gate always receives the resolved user — there is no
 * path where a null user slips past with different rules.
 */
class Authorize
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Gate::allows(Authorizer::VIEW)) {
            return $this->deny($request);
        }

        return $next($request);
    }

    private function deny(Request $request): Response
    {
        if ($this->wantsJson($request)) {
            return response()->json([
                'error' => [
                    'code' => 'forbidden',
                    'message' => 'You are not authorized to access LogLens.',
                ],
            ], 403);
        }

        return response('Forbidden', 403);
    }

    private function wantsJson(Request $request): bool
    {
        return $request->expectsJson()
            || str_contains($request->path(), '/api/')
            || $request->is('*/api/*');
    }
}
