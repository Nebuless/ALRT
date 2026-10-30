<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#07080F">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
    <title>{{ $title ?? 'Home' }} · Nebuless</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body data-live-fleet>
    <a class="skip-link" href="#main">Skip to content</a>
    <x-nebula />
    <div class="app-shell">
        <header class="masthead">
            <a class="wordmark" href="{{ route('home') }}"><span aria-hidden="true" class="brand-point"></span>nebuless</a>
            @if(config('nebuless.auth_mode') === 'tailscale')
                <a class="freshness" href="{{ route('login') }}">Tailscale access</a>
            @else
                <form method="post" action="{{ route('logout') }}">@csrf<button type="submit">Sign out</button></form>
            @endif
        </header>
        <main id="main" tabindex="-1">{{ $slot }}</main>
    </div>
    @livewireScripts
</body>
</html>
