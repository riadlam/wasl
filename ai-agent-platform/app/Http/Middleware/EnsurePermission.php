<?php

namespace App\Http\Middleware;

use App\Support\CurrentBusiness;
use App\Support\Permission;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();
        abort_unless($user, 401);

        $businessId = CurrentBusiness::id();

        if ($user->isSuperAdmin() && ! $businessId) {
            abort(403, 'Select a shop first.');
        }

        $allowed = false;
        foreach ($permissions as $permission) {
            foreach (explode('|', $permission) as $key) {
                $key = trim($key);
                if ($key === '') {
                    continue;
                }
                if ($user->hasPermission(Permission::from($key), $businessId)) {
                    $allowed = true;
                    break 2;
                }
            }
        }

        abort_unless($allowed, 403);

        return $next($request);
    }
}
