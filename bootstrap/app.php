<?php

use App\Http\Middleware\PrivateResponse;
use App\Http\Middleware\RequireAccess;
use App\Http\Middleware\RequireModule;
use App\Http\Middleware\ResolveBanjar;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(PrivateResponse::class);
        $middleware->web(prepend: [ResolveBanjar::class]);
        $middleware->validateCsrfTokens(except: ['webhooks/midtrans']);
        $middleware->alias([
            'access' => RequireAccess::class,
            'module' => RequireModule::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['admin_password', 'admin_password_confirmation', 'nik', 'family_number', 'value', 'purpose', 'review_note', 'reason', 'reference', 'account_number', 'bank_reference', 'note']);
    })->create();
