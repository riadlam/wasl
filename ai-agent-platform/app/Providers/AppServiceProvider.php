<?php

namespace App\Providers;

use App\Mcp\WaslMcpRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WaslMcpRegistry::class);
    }

    public function boot(): void
    {
        RateLimiter::for('mcp', function (Request $request) {
            $token = (string) $request->bearerToken();

            return Limit::perMinute((int) config('services.wasl_mcp.rate_per_minute', 120))
                ->by($token !== '' ? 'mcp:'.hash('sha256', $token) : 'mcp-ip:'.$request->ip());
        });

        // Keep Sanctum cookie auth working on the current APP_URL host (ngrok, LAN, etc.).
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        if ($appHost) {
            $existing = config('sanctum.stateful', []);
            $withHost = array_values(array_unique(array_filter([
                ...$existing,
                $appHost,
                $appHost.':8083',
                'localhost',
                '127.0.0.1',
                '192.168.1.9',
                '192.168.1.9:8083',
            ])));
            config(['sanctum.stateful' => $withHost]);
        }
    }
}
