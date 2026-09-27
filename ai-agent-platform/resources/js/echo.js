import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

/**
 * Build Echo against the host the browser can actually reach.
 * HTTPS pages (ngrok) cannot open plain ws:// to a LAN IP — return null and let polling cover live inbox.
 */
export function createEcho() {
    const runtime = window.__WASL_REVERB__ || {};
    const key = runtime.key || import.meta.env.VITE_REVERB_APP_KEY;
    if (!key) {
        return null;
    }

    const pageHttps = window.location.protocol === 'https:';
    const configuredScheme = String(runtime.scheme || import.meta.env.VITE_REVERB_SCHEME || 'http').toLowerCase();
    const configuredHost = runtime.host || import.meta.env.VITE_REVERB_HOST || '';
    const configuredPort = Number(runtime.port || import.meta.env.VITE_REVERB_PORT || (pageHttps ? 443 : 8080));

    // Mixed content / unreachable: page is HTTPS but Reverb is only HTTP on a private host.
    if (pageHttps && configuredScheme !== 'https') {
        return null;
    }

    const host = pickWsHost(configuredHost);
    const forceTLS = pageHttps || configuredScheme === 'https';
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

    try {
        return new Echo({
            broadcaster: 'reverb',
            key,
            wsHost: host,
            wsPort: forceTLS ? Number(runtime.port || import.meta.env.VITE_REVERB_PORT || 443) : configuredPort,
            wssPort: forceTLS ? Number(runtime.port || import.meta.env.VITE_REVERB_PORT || 443) : configuredPort,
            forceTLS,
            enabledTransports: forceTLS ? ['wss', 'ws'] : ['ws', 'wss'],
            withCredentials: true,
            authEndpoint: '/broadcasting/auth',
            auth: {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json',
                    ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
                },
            },
        });
    } catch {
        return null;
    }
}

function pickWsHost(configuredHost) {
    const pageHost = window.location.hostname;
    if (!configuredHost) {
        return pageHost;
    }

    // Prefer the page host when browsing via localhost / 127.0.0.1 so cookies + WS stay aligned.
    if (pageHost === 'localhost' || pageHost === '127.0.0.1') {
        return configuredHost.includes('.') ? configuredHost : pageHost;
    }

    // Same machine LAN browse — use whatever was configured (usually the LAN IP Reverb binds to).
    return configuredHost;
}
