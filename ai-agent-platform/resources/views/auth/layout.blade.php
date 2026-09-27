<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ $title }} — Wasl</title>
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700" rel="stylesheet" />
        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/auth.jsx'])
    </head>
    <body class="min-h-dvh bg-cream text-ink antialiased">
        <div class="mx-auto flex min-h-dvh max-w-md flex-col justify-center px-4 py-10">
            <a href="/" class="mb-8 text-center text-2xl font-extrabold tracking-tight">Wasl</a>
            {{ $slot }}
        </div>
    </body>
</html>
