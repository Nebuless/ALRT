<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireBetterAuthMode
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('nebuless.auth_mode') === 'better-auth', 404);

        return $next($request);
    }
}
