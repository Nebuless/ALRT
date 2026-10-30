<?php

use App\Actions\RebootState;
use App\Models\HubIdentity;
use App\Models\Machine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\IdentityEnvironment;

uses(RefreshDatabase::class, IdentityEnvironment::class);

beforeEach(function (): void {
    HubIdentity::create(['id' => 1, 'hub_uuid' => '11111111-1111-4111-8111-111111111111', 'name' => 'Primary-Hub', 'role' => 'primary', 'peers' => []]);
    $this->keyDirectory = sys_get_temp_dir().'/nebuless-signing-install-'.bin2hex(random_bytes(8));
    mkdir($this->keyDirectory, 0700);
});

afterEach(function (): void {
    foreach (glob($this->keyDirectory.'/*') as $file) {
        unlink($file);
    }
    rmdir($this->keyDirectory);
});

it('prepares one primary signer and refuses to replace missing prior authority', function (): void {
    $file = $this->keyDirectory.'/action-signing.pem';
    $this->artisan('fleet:prepare-primary-actions', ['privateFile' => $file, 'keyId' => 'primary-install'])->assertSuccessful();
    $original = file_get_contents($file);
    expect(fileperms($file) & 0777)->toBe(0600);
    config(['actions.private_key_file' => $file, 'actions.key_id' => 'primary-install']);
    $this->artisan('fleet:prepare-primary-actions', ['privateFile' => $file, 'keyId' => 'primary-install'])->assertSuccessful();
    expect(file_get_contents($file))->toBe($original);
    $public = HubIdentity::find(1)->primary_signing_public_key;
    unlink($file);
    $this->artisan('fleet:prepare-primary-actions', ['privateFile' => $file, 'keyId' => 'primary-install'])->assertFailed();
    expect(is_file($file))->toBeFalse()->and(HubIdentity::find(1)->primary_signing_public_key)->toBe($public);
});

it('records local target enrollment while actual helper capability stays unavailable', function (): void {
    $machine = Machine::create(['id' => '22222222-2222-4222-8222-222222222222', 'name' => 'Alpha-Hub', 'data_policy' => Machine::TITLES]);
    $this->artisan('fleet:enroll-reboot', ['machine' => $machine->id])->assertSuccessful();
    expect($machine->fresh()->reboot_enabled)->toBeTrue()->and(app(RebootState::class)->capability($machine)['available'])->toBeFalse();
    $this->artisan('fleet:enroll-reboot', ['machine' => $machine->id, '--revoke' => true])->assertSuccessful();
    expect($machine->fresh()->reboot_enabled)->toBeFalse();
    $primary = Machine::create(['id' => '33333333-3333-4333-8333-333333333333', 'name' => 'Primary-Hub', 'data_policy' => Machine::TITLES]);
    $this->artisan('fleet:enroll-reboot', ['machine' => $primary->id])->assertFailed();
    HubIdentity::find(1)->update(['name' => 'Beta-Restricted', 'role' => 'standby']);
    $this->artisan('fleet:enroll-reboot', ['machine' => $machine->id])->assertFailed();
});
