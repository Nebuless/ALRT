<?php
// Test-only ephemeral key. Stdout contains public verification material only.
require __DIR__.'/../../../apps/hub/vendor/autoload.php';
$key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_ED25519]);
openssl_pkey_export($key, $private);
$details = openssl_pkey_get_details($key);
$publicDer = base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $details['key']));
$permit = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$envelope = (new App\Actions\PermitSigner)->sign($permit, $private);
echo json_encode(['envelope' => $envelope, 'public_key' => base64_encode(substr($publicDer, -32))], JSON_THROW_ON_ERROR);
