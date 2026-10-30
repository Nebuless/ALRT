<?php

namespace App\Incidents;

use App\Models\HubIdentity;
use Illuminate\Support\Str;

class PeerCredentials
{
    /** @return list<array{name: string, origin: string, hub_id: string, machine_id: string, token: string, proxy_url: ?string}> */
    public function configured(): array
    {
        $path = config('incidents.collector_config');
        if (! is_string($path) || is_link($path) || ! is_file($path) || ! is_readable($path)) {
            return [];
        }
        $mode = fileperms($path);
        $size = filesize($path);
        if ($mode === false || ($mode & 0077) !== 0 || $size === false || $size > 65536) {
            return [];
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            return [];
        }
        try {
            $config = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (! is_array($config) || ! is_string($config['machine_id'] ?? null) || ! Str::isUuid($config['machine_id']) || ! is_array($config['destinations'] ?? null)) {
            return [];
        }
        $hub = HubIdentity::find(1);
        $peers = [];
        foreach ($hub->peers ?? [] as $name => $origin) {
            if (! $this->httpsOrigin($origin)) {
                continue;
            }
            foreach ($config['destinations'] as $destination) {
                if (! is_array($destination) || rtrim((string) ($destination['url'] ?? ''), '/') !== rtrim($origin, '/') ||
                    ! is_string($destination['id'] ?? null) || ! Str::isUuid($destination['id']) ||
                    ! is_string($destination['token'] ?? null) || strlen($destination['token']) !== 64 || preg_match('/\s/', $destination['token']) ||
                    $destination['id'] === $hub?->hub_uuid) {
                    continue;
                }
                $proxy = $destination['proxy_url'] ?? null;
                if ($proxy !== null && (! is_string($proxy) || ! in_array(parse_url($proxy, PHP_URL_SCHEME), ['http', 'https'], true))) {
                    continue;
                }
                $peers[] = ['name' => $name, 'origin' => rtrim($origin, '/'), 'hub_id' => $destination['id'], 'machine_id' => $config['machine_id'], 'token' => $destination['token'], 'proxy_url' => $proxy];
                break;
            }
            if (count($peers) >= 2) {
                break;
            }
        }

        return $peers;
    }

    private function httpsOrigin(string $origin): bool
    {
        $parts = parse_url($origin);

        return is_array($parts) && ($parts['scheme'] ?? null) === 'https' && isset($parts['host']) &&
            ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['query']) && ! isset($parts['fragment']) &&
            in_array($parts['path'] ?? '', ['', '/'], true);
    }
}
