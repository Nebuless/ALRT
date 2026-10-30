<?php

namespace App\Providers;

use App\Auth\OwnerAccess;
use App\Http\Middleware\RequireOwner;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Auth::viaRequest('better-auth', fn (Request $request) => $request->attributes->get('owner.identity'));
        Livewire::addPersistentMiddleware([RequireOwner::class]);
        RateLimiter::for('owner-login', fn (Request $request) => Limit::perMinute(5)->by(hash('sha256', strtolower((string) $request->input('email')).'|'.$request->ip())));
        RateLimiter::for('owner-two-factor', fn (Request $request) => Limit::perMinute(5)->by($request->session()->getId().'|'.$request->ip()));
        RateLimiter::for('owner-enrollment', fn (Request $request) => Limit::perMinute(5)->by((string) $request->user()?->id));
        RateLimiter::for('owner-action-confirmation', fn (Request $request) => Limit::perMinute(5)->by((string) $request->user()?->id));
        Gate::define('observe-fleet', fn (User $user) => app(OwnerAccess::class)->enrolled($user));
        Gate::define('queue-reboot', fn (User $user) => app(OwnerAccess::class)->canAct($user));
    }
}
