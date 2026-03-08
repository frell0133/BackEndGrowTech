<?php

use App\Http\Middleware\AdminActionAuditMiddleware;
use App\Http\Middleware\AllowPrivateNetworkAccess;
use Illuminate\Auth\AuthenticationException;
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
        $middleware->append(AllowPrivateNetworkAccess::class);
        $middleware->append(AdminActionAuditMiddleware::class);

        $middleware->alias([
            'role' => \App\Http\Middleware\RoleMiddleware::class,
            'referral.attach.guard' => \App\Http\Middleware\ReferralAttachGuard::class,
            'admin' => \App\Http\Middleware\EnsureAdminHasRole::class,
            'admin.can' => \App\Http\Middleware\AdminCan::class,
            'admin.super' => \App\Http\Middleware\EnsureSuperAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return response()->json([
                'success' => false,
                'data' => null,
                'meta' => (object) [],
                'error' => [
                    'message' => 'Unauthenticated',
                    'details' => null,
                ],
            ], 401);
        });
    })
    ->create();