<?php

namespace App\Http\Middleware;

use App\Support\CurrentBusiness;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureShopSelected
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user, 401);

        if ($user->isSuperAdmin() && ! CurrentBusiness::id()) {
            if ($request->expectsJson()) {
                abort(403, 'Select a shop first.');
            }

            return redirect()->route('admin');
        }

        if (! $user->isSuperAdmin() && ! CurrentBusiness::id()) {
            abort(403, 'No shop assigned.');
        }

        return $next($request);
    }
}
