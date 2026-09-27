<?php

use App\Http\Middleware\EnsureBusinessContext;
use App\Http\Middleware\EnsureInternalAiKey;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureShopSelected;
use App\Http\Middleware\EnsureSuperAdmin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['web', 'auth']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->validateCsrfTokens(except: [
            'api/webhooks/socialapi',
            'api/webhooks/fal/image',
        ]);
        $middleware->statefulApi();
        $middleware->web(append: [
            EnsureBusinessContext::class,
        ]);
        $middleware->api(append: [
            EnsureBusinessContext::class,
        ]);
        $middleware->alias([
            'permission' => EnsurePermission::class,
            'super_admin' => EnsureSuperAdmin::class,
            'shop' => EnsureShopSelected::class,
            'internal_ai' => EnsureInternalAiKey::class,
        ]);
        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo(function () {
            $user = auth()->user();
            if ($user?->isSuperAdmin()) {
                return '/admin';
            }

            return '/space';
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
