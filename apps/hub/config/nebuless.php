<?php

return [
    'auth_mode' => env('NEBULESS_AUTH_MODE', 'tailscale'),
    'tailscale_binary' => env('NEBULESS_TAILSCALE_BINARY', '/usr/bin/tailscale'),
    'hub_name' => env('NEBULESS_HUB_NAME', 'Unprovisioned hub'),
    'role' => env('NEBULESS_HUB_ROLE', 'standby'),
];
