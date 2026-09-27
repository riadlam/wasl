<?php

namespace App\Http\Middleware;

use App\Models\Business;
use App\Support\CurrentBusiness;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBusinessContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $businessId = null;

        if ($request->hasSession()) {
            if ($user->isSuperAdmin()) {
                $businessId = $request->session()->get('impersonated_business_id')
                    ?? $request->session()->get('current_business_id');
            } else {
                $businessId = $request->session()->get('current_business_id');
            }
        }

        if (! $user->isSuperAdmin()) {
            $membership = $businessId ? $user->membershipFor((int) $businessId) : null;

            if (! $membership || $membership->disabled_at) {
                $membership = $user->memberships()->whereNull('disabled_at')->first();
                $businessId = $membership?->business_id;
                if ($businessId && $request->hasSession()) {
                    $request->session()->put('current_business_id', $businessId);
                }
            }
        }

        if ($businessId && Business::query()->whereKey($businessId)->exists()) {
            CurrentBusiness::set((int) $businessId);
        }

        return $next($request);
    }
}
