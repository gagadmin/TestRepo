<?php

use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\AuditMutatingRequests;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsurePasswordIsCurrent;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureSecurityAccess;
use App\Http\Middleware\EnsureTwoFactorEnrolled;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'active' => EnsureActiveUser::class,
            'permission' => EnsurePermission::class,
            'security.access' => EnsureSecurityAccess::class,
            'mfa' => EnsureTwoFactorEnrolled::class,
            'password.current' => EnsurePasswordIsCurrent::class,
        ]);

        $middleware->web(append: [
            AddSecurityHeaders::class,
            AuditMutatingRequests::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
