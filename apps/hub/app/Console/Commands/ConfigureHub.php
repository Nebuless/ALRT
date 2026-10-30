<?php

namespace App\Console\Commands;

use App\Models\HubIdentity;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ConfigureHub extends Command
{
    protected $signature = 'fleet:configure-hub {--id=} {--name=} {--role=} {--peer=*}';

    protected $description = 'Configure observation identity independently of private owner enrollment';

    public function handle(): int
    {
        $id = $this->option('id');
        $name = $this->option('name');
        $role = $this->option('role');
        $existing = HubIdentity::find(1);
        if (! is_string($id) || ! Str::isUuid($id) || ! in_array($name, ['Alpha-Hub', 'Beta-Restricted', 'Primary-Hub'], true) ||
            $role !== ($name === 'Primary-Hub' ? 'primary' : 'standby') || ($existing && ($existing->hub_uuid !== $id || $existing->name !== $name || $existing->role !== $role))) {
            $this->error('Use the enrolled fleet identity. An existing hub identity cannot be replaced.');

            return self::FAILURE;
        }
        $peers = [];
        $peerOptions = $this->option('peer');
        if (! is_array($peerOptions)) {
            return self::FAILURE;
        }
        foreach ($peerOptions as $peer) {
            if (! is_string($peer)) {
                return self::FAILURE;
            }
            $pair = explode('=', $peer, 2);
            $url = parse_url($pair[1] ?? '');
            if (count($pair) !== 2 || ! in_array($pair[0], ['Alpha-Hub', 'Beta-Restricted', 'Primary-Hub'], true) || $pair[0] === $name || ($url['scheme'] ?? '') !== 'https' || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) || ! in_array($url['path'] ?? '', ['', '/'], true)) {
                $this->error('Peers must use another fleet name and its HTTPS origin.');

                return self::FAILURE;
            }
            $peers[$pair[0]] = rtrim($pair[1], '/');
        }
        HubIdentity::updateOrCreate(['id' => 1], ['hub_uuid' => $id, 'name' => $name, 'role' => $role, 'peers' => $peers]);
        $this->info('Observation identity configured. Owner enrollment remains a private local operation.');

        return self::SUCCESS;
    }
}
