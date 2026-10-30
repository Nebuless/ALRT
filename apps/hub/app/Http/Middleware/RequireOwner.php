<?php

namespace App\Http\Middleware;

use App\Auth\OwnerAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        $access = app(OwnerAccess::class);
        if (! $access->sessionIsCurrent($request)) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            if ($request->expectsJson()) {
                abort(401, 'Unauthenticated.');
            }

            return redirect()->guest(route('login'));
        }
        if (! $access->enrolled($request->user())) {
            // Livewire replays this middleware during hydration. It only honors
            // exceptions or redirects, so a returned JSON response would not stop it.
            if ($request->expectsJson()) {
                abort(403, 'Complete two-factor enrollment.');
            }

            return redirect()->route('owner.enroll');
        }

        return $next($request);
    }
}
