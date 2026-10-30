@extends('auth.layout', ['title' => 'Tailscale access'])
@section('content')
@if(request()->user())
    <a class="access-continue" href="{{ route('home') }}">Open fleet →</a>
    <section class="access-panel" aria-labelledby="access-title">
        <h1 id="access-title">You're connected.</h1>
        <p>Tailscale verified your access to this private fleet. No separate account is needed.</p>
    </section>
    <p class="evidence-note">Connected as {{ request()->user()->name }}.</p>
@else
    <a class="access-continue" href="{{ route('home') }}">Try again →</a>
    <section class="access-panel" aria-labelledby="access-title">
        <h1 id="access-title">Connect Tailscale</h1>
        <p>Connect your phone or computer to your tailnet, then open this hub again.</p>
    </section>
    <p class="evidence-note">Access follows your tailnet's sharing and access rules. Both personal and tagged devices are supported. If you're already connected, this hub could not verify your Tailscale identity.</p>
@endif
@endsection
