<?php

namespace App\Actions;

class PermitSigner
{
    /** @param array<string, mixed> $permit
     * @return array{payload: string, signature: string}
     */
    public function sign(array $permit, string $privateKey): array
    {
        $payload = json_encode($permit, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (! openssl_sign($payload, $signature, $privateKey, 0)) {
            throw new ActionException('signing_unavailable', 503);
        }

        return ['payload' => base64_encode($payload), 'signature' => base64_encode($signature)];
    }
}
