<?php

namespace App\Actions;

use App\Models\Machine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AuthorizeReboot
{
    public function __construct(private readonly RebootState $state) {}

    public function signingReady(): bool
    {
        $pem = config('actions.private_key');
        if (! defined('OPENSSL_KEYTYPE_ED25519') || ! is_string($pem) || ! is_string(config('actions.key_id')) || config('actions.key_id') === '') {
            return false;
        }
        $key = @openssl_pkey_get_private($pem);

        return $key !== false && (openssl_pkey_get_details($key)['type'] ?? null) === OPENSSL_KEYTYPE_ED25519;
    }

    /** @return array{payload: string, signature: string} */
    public function permit(Machine $machine, string $id, string $boot, string $nonce): array
    {
        return $this->state->transaction(function () use ($machine, $id, $boot, $nonce): array {
            $this->state->synchronize($machine);
            $this->state->targetBoot($machine, $boot);
            $action = $this->state->action($machine, $id);
            if (! $this->state->capability($machine)['available'] || $action->boot_id !== $boot || $action->result !== null || ! in_array($action->state, ['picked_up', 'authorized'], true) || ! $action->cancel_until || Carbon::parse($action->cancel_until)->gt(now()) || Carbon::parse($action->expires_at)->lte(now()) || ! preg_match('/^[a-f0-9]{64}$/D', $nonce)) {
                throw new ActionException('permit_refused');
            }
            $expires = min(now()->getTimestamp() + 10, Carbon::parse($action->expires_at)->getTimestamp());
            $permit = ['version' => 1, 'action_id' => $id, 'machine_id' => $machine->id, 'boot_id' => $boot, 'nonce' => $nonce, 'issuer' => $this->state->primary()->hub_uuid, 'key_id' => config('actions.key_id'), 'issued_at' => now()->getTimestamp(), 'expires_at' => $expires, 'action_expires_at' => Carbon::parse($action->expires_at)->getTimestamp()];
            $envelope = app(PermitSigner::class)->sign($permit, config('actions.private_key'));
            DB::table('reboot_actions')->where('id', $id)->whereIn('state', ['picked_up', 'authorized'])->update(['state' => 'authorized', 'authorized_at' => $action->authorized_at ?? now()->toIso8601String(), 'last_permit_expires_at' => Carbon::createFromTimestampUTC($expires)->toIso8601String()]);

            return $envelope;
        });
    }
}
