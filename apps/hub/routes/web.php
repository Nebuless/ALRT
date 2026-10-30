<?php

use App\Auth\ActionConfirmation;
use App\Http\Controllers\OwnerAuthentication;
use App\Http\Middleware\RequireBetterAuthMode;
use App\Livewire\HostDetail;
use App\ReadModels\ReportingPaths;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'owner', 'cache.headers:no_store;private'])->group(function (): void {
    Route::view('/', 'orbit')->name('home');
    Route::view('/orbit', 'orbit')->name('orbit');
    Route::view('/citizens', 'citizens')->name('citizens');
    Route::get('/reporting', fn (ReportingPaths $paths) => view('reporting', ['paths' => $paths->all()]))->name('reporting');
    Route::redirect('/satellites', '/reporting');
    Route::get('/hosts/{machine}', HostDetail::class)->name('hosts.show');
});
Route::view('/offline', 'offline')->name('offline');
Route::get('/service-worker.js', fn () => response(file_get_contents(resource_path('js/service-worker.js')), 200, ['Content-Type' => 'application/javascript; charset=utf-8', 'Cache-Control' => 'no-cache']));

Route::get('/login', fn () => view(config('nebuless.auth_mode') === 'tailscale' ? 'auth.tailscale' : 'auth.login'))->name('login');
Route::middleware(RequireBetterAuthMode::class)->group(function (): void {
    Route::post('/login', [OwnerAuthentication::class, 'signIn'])->middleware('throttle:owner-login')->name('login.store');
    Route::get('/two-factor-challenge', fn (Request $request) => $request->session()->get('owner.challenge') === true ? view('auth.two-factor-challenge') : redirect()->route('login'))->name('two-factor.login');
    Route::post('/two-factor-challenge', [OwnerAuthentication::class, 'challenge'])->middleware('throttle:owner-two-factor')->name('two-factor.login.store');
    Route::post('/two-factor-challenge/restart', [OwnerAuthentication::class, 'restart'])->name('two-factor.restart');
    Route::post('/logout', [OwnerAuthentication::class, 'signOut'])->name('logout');
    Route::get('/owner/enroll', fn () => view('auth.enroll', ['enrollment' => session('owner.enrollment'), 'recoveryCodes' => session()->pull('owner.recovery_codes', [])]))->middleware('auth')->name('owner.enroll');
    Route::post('/owner/enroll', [OwnerAuthentication::class, 'enroll'])->middleware(['auth', 'throttle:owner-enrollment'])->name('owner.enroll.start');
    Route::post('/owner/enroll/confirm', [OwnerAuthentication::class, 'confirmEnrollment'])->middleware(['auth', 'throttle:owner-two-factor'])->name('owner.enroll.confirm');
    Route::get('/owner/continue', [OwnerAuthentication::class, 'continue'])->middleware(['auth', 'owner'])->name('owner.continue');
});

Route::post('/owner/action-confirmation', function (Request $request, ActionConfirmation $confirmation) {
    $rules = ['target' => ['required', 'uuid']];
    if (config('nebuless.auth_mode') !== 'tailscale') {
        $rules += ['password' => ['required', 'string'], 'code' => ['required', 'string']];
    }
    $input = $request->validate($rules);

    return response()->json(['proof' => $confirmation->issue($request, $input['target'], $input['password'] ?? '', $input['code'] ?? ''), 'expires_in' => 300], 201);
})->middleware(['auth', 'owner', 'throttle:owner-action-confirmation'])->name('owner.action-confirmation');
