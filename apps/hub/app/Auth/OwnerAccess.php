<?php

namespace App\Auth;

use App\Models\HubIdentity;
use App\Models\User;
use Illuminate\Http\Request;

class OwnerAccess
{
    public function enrolled(?User $user): bool
    {
        if (config('nebuless.auth_mode') === 'tailscale') {
            return $user !== null && $user->auth_provider === 'tailscale' && $user->provider_enrolled;
        }

        return $user !== null && $user->owner_slot === 1 && $user->auth_provider === 'better-auth' && $user->provider_enrolled;
    }

    public function sessionIsCurrent(Request $request): bool
    {
        $session = $request->attributes->get('owner.provider_session');
        $owner = $request->user();

        if (config('nebuless.auth_mode') === 'tailscale' && ($owner === null ||
            app(TailscaleIdentity::class)->subject($request) !== $owner->auth_subject ||
            $request->session()->get('owner.tailscale_subject') !== $owner->auth_subject)) {
            return false;
        }

        return $owner !== null && is_array($session) &&
            $session['provider'] === $owner->auth_provider && $session['subject'] === $owner->auth_subject &&
            $request->session()->get('owner.auth_version') === $owner->auth_version;
    }

    public function sessionHash(Request $request): string
    {
        return hash('sha256', $request->session()->getId().'|'.($request->attributes->get('owner.provider_session')['id'] ?? ''));
    }

    public function canAct(User $user): bool
    {
        return $this->enrolled($user) && (HubIdentity::find(1)->role ?? config('nebuless.role')) === 'primary';
    }
}
