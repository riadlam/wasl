<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="Wasl is the AI inbox for Algerian shops — unify Instagram, Facebook and WhatsApp, classify leads, and let an agent reply, create posts, and pull stats.">
        <title>Wasl — AI inbox for Algerian shops</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700" rel="stylesheet" />

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/landing.jsx'])
    </head>
    <body class="bg-cream text-ink antialiased">
        <div
            id="landing-root"
            data-login="{{ Route::has('login') ? route('login') : '/login' }}"
            data-register="{{ Route::has('register') ? route('register') : '/register' }}"
        ></div>
    </body>
</html>
