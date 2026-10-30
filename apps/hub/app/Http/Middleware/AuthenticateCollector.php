<?php

namespace App\Http\Middleware;

use App\Models\Machine;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateCollector
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $routeMachine = $request->route('machine');
        $routeId = $routeMachine instanceof Machine ? $routeMachine->getKey() : $routeMachine;
        $headerId = $request->header('X-Machine-ID');
        $identity = $routeId ?? $headerId;
        $machine = is_string($token) && strlen($token) === 64
            ? Machine::where('token_hash', hash('sha256', $token))->whereNull('token_revoked_at')->first()
            : null;
        if (! $machine || ! is_string($identity) || ! hash_equals($machine->id, $identity) ||
            ($headerId !== null && ! hash_equals($machine->id, $headerId))) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        $request->attributes->set('collector', $machine);

        return $next($request);
    }
}
