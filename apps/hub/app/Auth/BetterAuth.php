<?php

namespace App\Auth;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class BetterAuth
{
    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function call(Request $request, string $operation, array $body = []): array
    {
        $url = config('better-auth.bridge_url');
        $key = config('better-auth.bridge_key');
        if (! is_string($url) || ! preg_match('~^http://127\\.0\\.0\\.1:[0-9]+$~D', $url) || ! is_string($key) || strlen($key) < 32) {
            throw new ServiceUnavailableHttpException(null, 'Owner authentication is unavailable.');
        }
        try {
            $response = Http::baseUrl($url)->withToken($key)->acceptJson()->asJson()->connectTimeout(1)->timeout(5)->withoutRedirecting()
                ->withOptions(['proxy' => ''])
                ->post('/'.$operation, ['cookies' => (object) $request->session()->get('owner.provider_cookies', []), ...$body]);
        } catch (ConnectionException $exception) {
            throw new ServiceUnavailableHttpException(null, 'Owner authentication is unavailable.', $exception);
        }
        if ($response->serverError() || in_array($response->status(), [401, 404], true)) {
            throw new ServiceUnavailableHttpException(null, 'Owner authentication is unavailable.');
        }
        if (! $response->successful() || ! is_array($response->json())) {
            throw ValidationException::withMessages(['confirmation' => 'Confirm your password and a fresh authenticator code.']);
        }
        $result = $response->json();
        if (isset($result['cookies']) && is_array($result['cookies'])) {
            $request->session()->put('owner.provider_cookies', $result['cookies']);
        }
        unset($result['cookies']);

        return $result;
    }

    /** @return array{provider: string, subject: string, id: string, email: string, enrolled: bool}|null */
    public function current(Request $request): ?array
    {
        if (! $request->session()->has('owner.provider_cookies')) {
            return null;
        }

        $session = $this->call($request, 'session')['session'] ?? null;
        if ($session === null) {
            return null;
        }
        if (! is_array($session) || ($session['provider'] ?? null) !== 'better-auth' ||
            ! is_string($session['subject'] ?? null) || ! is_string($session['id'] ?? null) ||
            ! is_string($session['email'] ?? null) || ! is_bool($session['enrolled'] ?? null)) {
            throw new ServiceUnavailableHttpException(null, 'Owner authentication is unavailable.');
        }

        return ['provider' => $session['provider'], 'subject' => $session['subject'], 'id' => $session['id'], 'email' => $session['email'], 'enrolled' => $session['enrolled']];
    }
}
