<?php

namespace App\Http\Middleware;

use App\Auth\BetterAuth;
use App\Auth\TailscaleIdentity;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureOwnerSessionCurrent
{
    public function handle(Request $request, Closure $next): Response
    {
        Auth::forgetGuards();
        $request->attributes->remove('owner.identity');
        $request->attributes->remove('owner.provider_session');
        if (config('nebuless.auth_mode') === 'tailscale') {
            $this->tailscale($request);
            $session = null;
        } else {
            // Local logout stays reachable during a provider outage. The web stack
            // still requires CSRF, and this exception grants no owner identity.
            $localLogout = $request->isMethod('POST') && $request->routeIs('logout');
            $session = config('nebuless.auth_mode') === 'better-auth' && ! $localLogout ? app(BetterAuth::class)->current($request) : null;
        }
        if ($session !== null) {
            $owner = User::where('auth_provider', $session['provider'])->where('auth_subject', $session['subject'])->where('owner_slot', 1)->first();
            if ($owner && $request->session()->get('owner.auth_version') === $owner->auth_version) {
                $owner->provider_enrolled = $session['enrolled'] === true;
                $request->attributes->set('owner.identity', $owner);
                $request->attributes->set('owner.provider_session', $session);
            } else {
                $request->session()->forget(['owner.provider_cookies', 'owner.auth_version', 'owner.enrollment']);
            }
        }
        $response = $next($request);
        if ($request->is('owner/*', 'login', 'logout', 'two-factor-challenge', 'two-factor-challenge/*')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }

    private function tailscale(Request $request): void
    {
        $owner = app(TailscaleIdentity::class)->current($request);
        $previous = $request->session()->get('owner.tailscale_subject');
        if ($previous !== $owner?->auth_subject) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
        $request->session()->forget(['owner.provider_cookies', 'owner.enrollment', 'owner.challenge', 'owner.recovery_codes']);
        if ($owner === null) {
            $request->session()->forget(['owner.tailscale_subject', 'owner.auth_version']);

            return;
        }
        $request->session()->put(['owner.tailscale_subject' => $owner->auth_subject, 'owner.auth_version' => $owner->auth_version]);
        $owner->provider_enrolled = true;
        $request->attributes->set('owner.identity', $owner);
        $request->attributes->set('owner.provider_session', [
            'provider' => 'tailscale', 'subject' => $owner->auth_subject,
            'id' => hash('sha256', 'tailscale|'.$owner->auth_subject), 'enrolled' => true,
        ]);
    }
}
