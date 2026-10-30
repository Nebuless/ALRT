<?php

namespace App\Auth;

use App\Models\Machine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ActionConfirmation
{
    public function __construct(private readonly BetterAuth $provider, private readonly OwnerAccess $access) {}

    /** Issue a server-side, session-bound proof after explicit action confirmation. */
    public function issue(Request $request, string $target, string $password = '', string $code = ''): string
    {
        $owner = $request->user();
        if ($owner === null || ! $this->access->enrolled($owner) || ! $this->access->sessionIsCurrent($request) ||
            ! Machine::whereKey($target)->exists()) {
            throw ValidationException::withMessages(['confirmation' => 'Confirm your identity again.']);
        }
        if (config('nebuless.auth_mode') !== 'tailscale') {
            if (! preg_match('/^\d{6}$/D', $code)) {
                throw ValidationException::withMessages(['confirmation' => 'Confirm your password and a fresh authenticator code.']);
            }
            $confirmed = $this->provider->call($request, 'confirm', ['password' => $password, 'code' => $code]);
            if (($confirmed['confirmed'] ?? false) !== true || ($confirmed['subject'] ?? null) !== $owner->auth_subject || ($confirmed['sessionId'] ?? null) !== $request->attributes->get('owner.provider_session')['id']) {
                throw ValidationException::withMessages(['confirmation' => 'Confirm your identity again.']);
            }
        }
        $proof = Str::random(64);
        DB::table('action_confirmation_proofs')->insert([
            'token_hash' => hash('sha256', $proof),
            'user_id' => $owner->id,
            'auth_version' => $owner->auth_version,
            'target' => $target,
            'session_hash' => $this->access->sessionHash($request),
            'expires_at' => now()->addMinutes(5),
        ]);

        return $proof;
    }

    /** Call inside the same DB transaction as queuing the named target action. */
    public function consume(Request $request, string $target, string $proof): void
    {
        $owner = $request->user();
        if ($owner === null || ! $this->access->enrolled($owner) || ! $this->access->sessionIsCurrent($request)) {
            throw ValidationException::withMessages(['confirmation' => 'Confirm your identity again.']);
        }
        $consumed = DB::table('action_confirmation_proofs')
            ->where('token_hash', hash('sha256', $proof))
            ->where('user_id', $owner->id)
            ->where('auth_version', $owner->auth_version)
            ->where('target', $target)
            ->where('session_hash', $this->access->sessionHash($request))
            ->where('expires_at', '>', now())->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);
        if ($consumed !== 1) {
            throw ValidationException::withMessages(['confirmation' => 'Confirm your identity again.']);
        }
    }
}
