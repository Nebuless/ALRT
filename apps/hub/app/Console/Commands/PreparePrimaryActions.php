<?php

namespace App\Console\Commands;

use App\Actions\RebootState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PreparePrimaryActions extends Command
{
    protected $signature = 'fleet:prepare-primary-actions {privateFile} {keyId}';

    protected $description = 'Create or verify primary signing authority without rotating an existing identity';

    public function handle(): int
    {
        try {
            $hub = app(RebootState::class)->primary();
            $file = $this->argument('privateFile');
            $id = $this->argument('keyId');
            if (! is_string($file) || ! str_starts_with($file, '/') || ! is_string($id) || ! preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $id) || is_link($file)) {
                throw new \RuntimeException;
            }
            $configured = config('actions.private_key_file');
            if (($configured !== null && ($configured !== $file || ! is_file($file))) || (config('actions.key_id') !== null && config('actions.key_id') !== $id)) {
                throw new \RuntimeException;
            }
            if (! is_file($file)) {
                if ($hub->primary_signing_public_key !== null || DB::table('reboot_actions')->exists()) {
                    throw new \RuntimeException;
                }
                $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_ED25519]);
                if ($key === false || ! openssl_pkey_export($key, $pem)) {
                    throw new \RuntimeException;
                }
                $oldMask = umask(0077);
                try {
                    $stream = fopen($file, 'x');
                    if ($stream === false) {
                        throw new \RuntimeException;
                    }
                    try {
                        if (fwrite($stream, $pem) !== strlen($pem) || ! fflush($stream) || ! fsync($stream)) {
                            throw new \RuntimeException;
                        }
                    } finally {
                        fclose($stream);
                    }
                } finally {
                    umask($oldMask);
                }
            }
            $mode = fileperms($file);
            if ($mode === false || ($mode & 0077) !== 0 || filesize($file) > 16384) {
                throw new \RuntimeException;
            }
            $pem = file_get_contents($file);
            $key = $pem === false ? false : openssl_pkey_get_private($pem);
            $details = $key === false ? false : openssl_pkey_get_details($key);
            $raw = $details['ed25519']['pub_key'] ?? null;
            if (($details['type'] ?? null) !== OPENSSL_KEYTYPE_ED25519 || ! is_string($raw) || strlen($raw) !== 32) {
                throw new \RuntimeException;
            }
            $public = base64_encode($raw);
            if ($hub->primary_signing_public_key !== null && $hub->primary_signing_public_key !== $public) {
                throw new \RuntimeException;
            }
            $hub->update(['primary_signing_public_key' => $public]);
            $this->line(json_encode(['issuer' => $hub->hub_uuid, 'key_id' => $id, 'public_key' => $public], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Primary signing setup refused. Preserve existing key, public identity and action history; use explicit local recovery.');

            return self::FAILURE;
        }
    }
}
