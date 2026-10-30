<?php

namespace App\Console\Commands;

use App\Models\HubIdentity;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\Process\Process;

class ProvisionOwner extends Command
{
    protected $signature = 'fleet:provision-owner {email} {--recover : Replace owner password and restart TOTP enrollment locally} {--hub-id=} {--hub-name=} {--role=} {--peer=* : Peer name=https://origin} {--telegram-chat=} {--telegram : Prompt for the Telegram bot token} {--primary-signing-key= : Pinned Primary signing public key}';

    protected $description = 'Provision this independent hub and its single owner using local terminal access';

    public function handle(): int
    {
        $owner = User::where('owner_slot', 1)->first();
        if ($owner && ! $this->option('recover')) {
            $this->error('An owner already exists. Use --recover from the local trusted terminal.');

            return self::FAILURE;
        }
        $email = $this->argument('email');
        if (! is_string($email)) {
            $this->error('Use a valid owner email.');

            return self::FAILURE;
        }
        $password = $this->secret('New owner password (at least 12 characters)');
        $confirmation = $this->secret('Confirm new owner password');
        $hub = HubIdentity::find(1);
        $settings = [
            'email' => strtolower($email),
            'password' => $password,
            'password_confirmation' => $confirmation,
            'hub_uuid' => $this->option('hub-id') ?: $hub?->hub_uuid ?: (string) Str::uuid(),
            'name' => $this->option('hub-name') ?: $hub?->name ?: config('nebuless.hub_name'),
            'role' => $this->option('role') ?: $hub?->role ?: config('nebuless.role'),
        ];
        $validator = Validator::make($settings, [
            'email' => ['required', 'email'], 'password' => ['required', 'string', 'min:12', 'confirmed'],
            'hub_uuid' => ['required', 'uuid'], 'name' => ['required', 'string', 'max:255'],
            'role' => ['required', Rule::in(['primary', 'standby'])],
        ]);
        if ($validator->fails()) {
            $this->error('Invalid owner or hub settings. Use a valid email, matching passwords of at least 12 characters, UUID and primary/standby role.');

            return self::FAILURE;
        }
        $peers = $hub->peers ?? [];
        $peerOptions = $this->option('peer');
        if (! is_array($peerOptions)) {
            $this->error('Supply each peer with its own --peer option.');

            return self::FAILURE;
        }
        foreach ($peerOptions as $peer) {
            if (! is_string($peer)) {
                $this->error('Peers must use name=https://origin without credentials.');

                return self::FAILURE;
            }
            $parts = explode('=', $peer, 2);
            $url = parse_url($parts[1] ?? '');
            if (count($parts) !== 2 || $parts[0] === '' || ($url['scheme'] ?? '') !== 'https' || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])) {
                $this->error('Peers must use name=https://origin without credentials.');

                return self::FAILURE;
            }
            $peers[$parts[0]] = rtrim($parts[1], '/');
        }
        $telegramToken = $this->option('telegram') ? $this->secret('Telegram bot token') : $hub?->telegram_bot_token;
        $process = new Process([config('better-auth.node'), base_path('../auth/src/owner.mjs')], env: [
            'AUTH_DATABASE' => config('better-auth.database'), 'AUTH_SECRET' => config('better-auth.secret'), 'AUTH_ORIGIN' => config('better-auth.origin'),
        ]);
        $process->setInput(json_encode(['email' => $settings['email'], 'password' => $settings['password'], 'recover' => (bool) $this->option('recover')], JSON_THROW_ON_ERROR));
        $process->setTimeout(30);
        $process->run();
        if (! $process->isSuccessful()) {
            $this->error('Local Better Auth provisioning failed. Check auth configuration and existing owner state.');

            return self::FAILURE;
        }
        $identity = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
        DB::transaction(function () use ($owner, $settings, $peers, $telegramToken, $hub, $identity): void {
            $user = $owner ?? new User;
            $user->forceFill([
                'name' => 'Owner', 'email' => $settings['email'], 'auth_provider' => $identity['provider'], 'auth_subject' => $identity['subject'],
                'owner_slot' => 1, 'auth_version' => ($owner->auth_version ?? 0) + 1,
            ])->save();
            DB::table('action_confirmation_proofs')->where('user_id', $user->id)->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            HubIdentity::updateOrCreate(['id' => 1], [
                'hub_uuid' => $settings['hub_uuid'], 'name' => $settings['name'], 'role' => $settings['role'], 'peers' => $peers,
                'telegram_bot_token' => $telegramToken,
                'telegram_chat_id' => $this->option('telegram-chat') ?: $hub?->telegram_chat_id,
                'primary_signing_public_key' => $this->option('primary-signing-key') ?: $hub?->primary_signing_public_key,
            ]);
        });
        $this->info('Owner provisioned locally. Sign in on this hub and complete authenticator enrollment before viewing the fleet.');

        return self::SUCCESS;
    }
}
