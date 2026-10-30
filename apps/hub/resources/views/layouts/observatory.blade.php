<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#151024">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" href="/icons/orbit.svg" type="image/svg+xml"><title>{{ $title ?? 'Observatory' }} · Orbit</title>
    @vite(['resources/css/orbit.css', 'resources/css/host.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body data-live-fleet>
    <a class="host-skip" href="#main">Skip to content</a>
    <main id="main" class="host-wrap" tabindex="-1">{{ $slot }}</main>
    @livewireScripts
</body>
</html>
