<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

class TailscaleIdentity
{
    public function subject(Request $request): ?string
    {
        $origin = parse_url((string) config('app.url'));
        if (config('nebuless.auth_mode') !== 'tailscale' ||
            $request->server->get('NEBULESS_TAILSCALE_INGRESS') !== '1' ||
            ! in_array($request->server->get('REMOTE_ADDR'), ['127.0.0.1', '::1'], true) ||
            ! $request->isSecure() || ! is_array($origin) || ($origin['scheme'] ?? null) !== 'https' ||
            $request->getHost() !== ($origin['host'] ?? null) || $request->getPort() !== ($origin['port'] ?? 443) ||
            $request->headers->has('Tailscale-Funnel-Request')) {
            return null;
        }
        $login = $request->header('Tailscale-User-Login');
        if ($login === null || $login === '') {
            return $this->deviceSubject($request);
        }
        if (strlen($login) > 255 || preg_match('/[\x00-\x20\x7f,]/', $login)) {
            return null;
        }

        return $login;
    }

    private function deviceSubject(Request $request): ?string
    {
        // Caddy copies Serve's overwritten source into a server-only FastCGI
        // parameter before its own proxy rewrites forwarding headers.
        $source = $request->server->get('NEBULESS_TAILSCALE_SOURCE');
        if (! is_string($source) || ! filter_var($source, FILTER_VALIDATE_IP) ||
            ! IpUtils::checkIp($source, ['100.64.0.0/10', 'fd7a:115c:a1e0::/48'])) {
            return null;
        }
        $key = 'tailscale.device.'.$source;
        if (! $request->attributes->has($key)) {
            $request->attributes->set($key, $this->lookupDevice($source));
        }

        return $request->attributes->get($key);
    }

    private function lookupDevice(string $source): ?string
    {
        try {
            $result = Process::timeout(2)->run([(string) config('nebuless.tailscale_binary'), 'whois', '--json', $source]);
            if (! $result->successful()) {
                return null;
            }
            $node = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR)['Node'] ?? null;
            if (! is_array($node) || ! is_string($node['StableID'] ?? null) ||
                ! preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/', $node['StableID']) ||
                ! is_array($node['Tags'] ?? null) || $node['Tags'] === [] || ($node['Expired'] ?? false) !== false ||
                ! is_array($node['Addresses'] ?? null)) {
                return null;
            }
            $expiry = $node['KeyExpiry'] ?? null;
            if ($expiry !== null && $expiry !== '0001-01-01T00:00:00Z' &&
                (! is_string($expiry) || strtotime($expiry) === false || strtotime($expiry) <= time())) {
                return null;
            }
            $addresses = array_filter($node['Addresses'], fn ($address) => is_string($address) &&
                str_ends_with($address, str_contains($source, ':') ? '/128' : '/32'));
            if (! IpUtils::checkIp($source, $addresses)) {
                return null;
            }

            return 'device '.$node['StableID'];
        } catch (Throwable) {
            return null;
        }
    }

    public function current(Request $request): ?User
    {
        $subject = $this->subject($request);
        if ($subject === null) {
            return null;
        }

        return User::unguarded(fn () => User::firstOrCreate(
            ['auth_provider' => 'tailscale', 'auth_subject' => $subject],
            ['name' => $subject, 'email' => hash('sha256', $subject).'@tailscale.invalid', 'owner_slot' => null, 'auth_version' => 1],
        ));
    }
}
