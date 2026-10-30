<?php

use App\Models\HubIdentity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\IdentityEnvironment;

uses(RefreshDatabase::class, IdentityEnvironment::class);

it('configures observation without inventing an owner or replacing a hub identity', function (): void {
    $id = '11111111-1111-4111-8111-111111111111';
    $this->artisan('fleet:configure-hub', ['--id' => $id, '--name' => 'Alpha-Hub', '--role' => 'standby', '--peer' => ['Primary-Hub=https://primary.example:8444']])->assertSuccessful();
    expect(User::count())->toBe(0)->and(HubIdentity::find(1)->hub_uuid)->toBe($id);
    $this->artisan('fleet:configure-hub', ['--id' => '22222222-2222-4222-8222-222222222222', '--name' => 'Alpha-Hub', '--role' => 'standby'])->assertFailed();
    expect(HubIdentity::find(1)->hub_uuid)->toBe($id);
});
