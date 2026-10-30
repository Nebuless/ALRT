<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="color-scheme" content="dark"><link rel="icon" href="/icons/orbit.svg" type="image/svg+xml"><title>{{ $title ?? 'Owner access' }} · Nebuless</title>
    @vite(['resources/css/citizens.css', 'resources/css/access.css'])
@if(config('nebuless.auth_mode') === 'tailscale')
    <meta name="theme-color" content="#07080F">
    <meta name="robots" content="noindex, nofollow">
    <link rel="canonical" href="{{ rtrim(config('app.url'), '/') }}/login">
@endif
</head>
<body data-auth-mode="{{ config('nebuless.auth_mode') }}">
    <div class="citizens-wrap access-wrap">
    <header class="masthead"><a class="return-orbit" href="{{ route('home') }}">Orbit</a><span class="badge">{{ config('nebuless.hub_name') }}</span></header>
    <main class="auth-page">
    @if ($errors->any())
            <div id="auth-errors" role="alert" tabindex="-1"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif
    @yield('content')
</main>
    </div>
</body>
</html>
