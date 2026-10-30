<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BetterAuthService;
use Tests\Support\IdentityEnvironment;

uses(RefreshDatabase::class, IdentityEnvironment::class, BetterAuthService::class);

it('restores only an authorized same-origin owner GET after renewed sign in', function () {
    [$owner, $enrollment] = $this->signInBetterOwner();
    $this->post('/logout');
    $this->get('/orbit?host=33333333-3333-4333-8333-333333333333')->assertRedirect('/login');
    $this->post('/login', ['email' => $owner->email, 'password' => 'correct-password'])->assertRedirect('/two-factor-challenge');
    $this->post('/two-factor-challenge', ['code' => $this->betterTotp($enrollment['totpURI'])])
        ->assertRedirect('/orbit?host=33333333-3333-4333-8333-333333333333');
    expect(session()->has('url.intended'))->toBeFalse();
});

it('rejects stale challenge entry and restarts without retaining provider credentials', function () {
    $this->provisionBetterOwner();
    $this->get('/two-factor-challenge')->assertRedirect('/login');
    $this->withSession(['owner.challenge' => true, 'owner.provider_cookies' => ['private'], 'url.intended' => 'http://localhost/citizens'])
        ->post('/two-factor-challenge/restart')->assertRedirect('/login');
    expect(session()->has('owner.challenge'))->toBeFalse()
        ->and(session()->has('owner.provider_cookies'))->toBeFalse()
        ->and(session('url.intended'))->toBe('http://localhost/citizens');
    $this->post('/two-factor-challenge', ['code' => '123456'])->assertForbidden();
});

it('keeps intended entry behind enrollment and exposes recovery codes before continuing', function () {
    $owner = $this->provisionBetterOwner();
    $this->get('/citizens')->assertRedirect('/login');
    $this->post('/login', ['email' => $owner->email, 'password' => 'correct-password'])->assertRedirect('/owner/enroll');
    $this->get('/owner/continue')->assertRedirect('/owner/enroll');
    $this->post('/owner/enroll', ['password' => 'correct-password'])->assertRedirect('/owner/enroll');
    $enrollment = session('owner.enrollment');
    $this->post('/owner/enroll/confirm', ['code' => $this->betterTotp($enrollment['totpURI'])])->assertRedirect('/owner/enroll');
    $this->get('/owner/enroll')->assertOk()->assertSee('Recovery codes');
    $this->get('/owner/continue')->assertRedirect('/citizens');
});

it('never follows external or mutation return destinations', function (string $destination) {
    $this->signInBetterOwner();
    $this->withSession(['url.intended' => $destination])->get('/owner/continue')->assertRedirect('/');
})->with(['https://example.test/orbit', '//example.test/citizens', 'http://localhost/owner/action-confirmation', 'http://user@localhost/orbit']);
