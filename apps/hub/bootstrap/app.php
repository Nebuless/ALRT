<?php

use App\Http\Middleware\AuthenticateCollector;
use App\Http\Middleware\EnsureOwnerSessionCurrent;
use App\Http\Middleware\RequireOwner;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [EnsureOwnerSessionCurrent::class]);
        $middleware->prependToPriorityList(AuthenticatesRequests::class, EnsureOwnerSessionCurrent::class);
        $middleware->alias([
            'owner' => RequireOwner::class,
            'collector' => AuthenticateCollector::class,
        ]);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo('/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['code', 'recovery_code', 'proof', 'token', 'telegram_bot_token']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
