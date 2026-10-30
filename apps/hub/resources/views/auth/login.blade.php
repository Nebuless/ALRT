@extends('auth.layout', ['title' => 'Sign in'])
@section('content')
<h1>Sign in</h1>
<p>Use this hub's owner credentials.</p>
<form method="post" action="{{ route('login.store') }}">
    @csrf
    <label>Email <input type="email" name="email" value="{{ old('email') }}" autocomplete="username" @if($errors->has('email')) aria-invalid="true" aria-describedby="auth-errors" @endif required autofocus></label>
    <label>Password <input type="password" name="password" autocomplete="current-password" @if($errors->any()) aria-describedby="auth-errors" @endif required></label>
    <button type="submit">Continue</button>
</form>
<p>Lost access? Recover the owner account from this hub's local terminal.</p>
@endsection
