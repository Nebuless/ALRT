@extends('auth.layout', ['title' => 'Authenticator'])
@section('content')
<h1>Confirm your sign in</h1>
<form method="post" action="{{ route('two-factor.login.store') }}">
    @csrf
    <label>Authenticator code <input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" @if($errors->has('code')) aria-invalid="true" aria-describedby="auth-errors" @endif required autofocus></label>
    <button type="submit">Sign in</button>
</form>
<details><summary>Use a recovery code</summary>
    <form method="post" action="{{ route('two-factor.login.store') }}">
        @csrf
        <label>Recovery code <input name="recovery_code" autocomplete="off" @if($errors->any()) aria-describedby="auth-errors" @endif required></label>
        <button type="submit">Recover sign in</button>
    </form>
</details>
<form method="post" action="{{ route('two-factor.restart') }}">@csrf<button type="submit">Start sign in again</button></form>
@endsection
