<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInternalAiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('ai_runtime.internal_key');
        $provided = (string) $request->header('X-Internal-Key', '');

        if ($expected === '' || ! hash_equals($expected, $provided)) {
            abort(401, 'Invalid internal AI key.');
        }

        $businessId = (int) $request->header('X-Business-Id', 0);
        if ($businessId < 1) {
            abort(400, 'X-Business-Id required.');
        }

        app()->instance('currentBusinessId', $businessId);

        return $next($request);
    }
}
