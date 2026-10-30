@extends('auth.layout', ['title' => 'Authenticator enrollment'])
@section('content')
<h1>Set up this hub's authenticator</h1>
@if (auth()->user()->provider_enrolled)
    @if (count($recoveryCodes))
        <h2>Recovery codes</h2>
        <p>Save these privately now. Each recovers one sign in. Actions still require a fresh authenticator code.</p>
        <ul>@foreach ($recoveryCodes as $code)<li><code>{{ $code }}</code></li>@endforeach</ul>
    @endif
    <a href="{{ route('owner.continue') }}">Open fleet</a>
@elseif ($enrollment)
    <p>Add this setup key to your authenticator, then enter its six-digit code.</p>
    @php(parse_str(parse_url($enrollment['totpURI'], PHP_URL_QUERY), $setup))
    <p>Setup key: <code>{{ $setup['secret'] }}</code></p>
    <a href="{{ $enrollment['totpURI'] }}">Open authenticator</a>
    <form method="post" action="{{ route('owner.enroll.confirm') }}">
        @csrf
        <label>Authenticator code <input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" @if($errors->has('code')) aria-invalid="true" aria-describedby="auth-errors" @endif required autofocus></label>
        <button type="submit">Confirm enrollment</button>
    </form>
@else
    <p>Add this hub to your authenticator before opening the fleet.</p>
    <form method="post" action="{{ route('owner.enroll.start') }}">
        @csrf
        <label>Password <input type="password" name="password" autocomplete="current-password" @if($errors->any()) aria-invalid="true" aria-describedby="auth-errors" @endif required autofocus></label>
        <button type="submit">Start enrollment</button>
    </form>
@endif
<form method="post" action="{{ route('logout') }}">@csrf<button type="submit">Sign out</button></form>
@endsection
