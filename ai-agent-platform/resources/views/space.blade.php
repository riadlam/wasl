<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="description" content="Wasl workspace — inbox, leads, and AI agent for Algerian shops.">
        <title>{{ ($start ?? 'inbox') === 'workflows' ? 'Wasl — Workflows' : 'Wasl — Workspace' }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=dm-sans:400,500,600,700|outfit:500,600,700,800" rel="stylesheet" />
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700" rel="stylesheet" />

        <script>
            window.__WASL_REVERB__ = {
                key: @json(config('broadcasting.connections.reverb.key')),
                host: @json(config('broadcasting.connections.reverb.options.host')),
                port: @json((int) config('broadcasting.connections.reverb.options.port')),
                scheme: @json(config('broadcasting.connections.reverb.options.scheme')),
            };
        </script>

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/space.jsx'])
    </head>
    <body class="h-dvh overflow-hidden bg-white text-ink antialiased">
        <div id="space-root" class="h-full" data-start="{{ $start ?? 'inbox' }}"></div>
    </body>
</html>
