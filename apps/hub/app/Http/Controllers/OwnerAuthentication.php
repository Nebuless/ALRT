<?php

namespace App\Http\Controllers;

use App\Auth\BetterAuth;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class OwnerAuthentication
{
    public function __construct(private readonly BetterAuth $provider) {}

    public function signIn(Request $request): RedirectResponse
    {
        $input = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $request->session()->forget(['owner.provider_cookies', 'owner.enrollment', 'owner.auth_version', 'owner.challenge']);
        try {
            $result = $this->provider->call($request, 'sign-in', $input);
        } catch (ValidationException) {
            throw ValidationException::withMessages(['email' => 'The sign-in details were not accepted.']);
        }
        $request->session()->regenerate();
        if ($result['twoFactorRedirect'] ?? false) {
            $request->session()->put('owner.challenge', true);

            return redirect()->route('two-factor.login');
        }

        return $this->finishSignIn($request);
    }

    public function challenge(Request $request): RedirectResponse
    {
        abort_unless($request->session()->get('owner.challenge') === true, 403);
        $input = $request->validate(['code' => ['nullable', 'string'], 'recovery_code' => ['nullable', 'string']]);
        try {
            $this->provider->call($request, ! empty($input['recovery_code']) ? 'backup' : 'totp', ['code' => $input['recovery_code'] ?? $input['code'] ?? '']);
        } catch (ValidationException) {
            throw ValidationException::withMessages(['code' => 'The authenticator or recovery code was not accepted.']);
        }

        return $this->finishSignIn($request);
    }

    private function finishSignIn(Request $request): RedirectResponse
    {
        $session = $this->provider->current($request);
        $owner = $session ? User::where('auth_provider', $session['provider'])->where('auth_subject', $session['subject'])->where('owner_slot', 1)->first() : null;
        abort_unless($owner !== null, 403);
        $request->session()->regenerate();
        $request->session()->put('owner.auth_version', $owner->auth_version);
        $request->session()->forget('owner.challenge');

        return $session['enrolled'] ? $this->continue($request) : redirect()->route('owner.enroll');
    }

    public function continue(Request $request): RedirectResponse
    {
        $intended = $request->session()->pull('url.intended');
        $parts = is_string($intended) ? parse_url($intended) : false;
        if ($parts !== false && ! isset($parts['user']) && ! isset($parts['pass'])
            && ($parts['scheme'] ?? '') === $request->getScheme()
            && ($parts['host'] ?? '') === $request->getHost()
            && ($parts['port'] ?? ($request->isSecure() ? 443 : 80)) === $request->getPort()
            && preg_match('~^/(?:|orbit|citizens|reporting|hosts/[0-9a-f-]{36})$~D', $parts['path'] ?? '')) {
            return redirect($intended);
        }

        return redirect()->route('home');
    }

    public function restart(Request $request): RedirectResponse
    {
        $intended = $request->session()->get('url.intended');
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        if (is_string($intended)) {
            $request->session()->put('url.intended', $intended);
        }

        return redirect()->route('login');
    }

    public function signOut(Request $request): RedirectResponse
    {
        $revocationFailed = false;
        try {
            if ($request->session()->has('owner.provider_cookies')) {
                $this->provider->call($request, 'sign-out');
            }
        } catch (ServiceUnavailableHttpException|ValidationException) {
            $revocationFailed = true;
        } finally {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $response = redirect()->route('login');

        return $revocationFailed
            ? $response->withErrors(['logout' => 'Signed out locally. Provider session revocation could not be confirmed.'])
            : $response;
    }

    public function enroll(Request $request): RedirectResponse
    {
        abort_if($request->attributes->get('owner.provider_session')['enrolled'] ?? false, 403);
        $input = $request->validate(['password' => ['required', 'string']]);
        $enrollment = $this->provider->call($request, 'enroll', $input);
        $request->session()->put('owner.enrollment', $enrollment);

        return redirect()->route('owner.enroll');
    }

    public function confirmEnrollment(Request $request): RedirectResponse
    {
        abort_if($request->attributes->get('owner.provider_session')['enrolled'] ?? false, 403);
        $input = $request->validate(['code' => ['required', 'regex:/^\d{6}$/D']]);
        $this->provider->call($request, 'totp', $input);
        $request->session()->regenerate();
        $request->session()->put('owner.recovery_codes', $request->session()->get('owner.enrollment.backupCodes', []));
        $request->session()->forget('owner.enrollment');

        return redirect()->route('owner.enroll');
    }
}
