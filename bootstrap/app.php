<?php

use Illuminate\Foundation\Application;
use App\Http\Middleware\AllowPrivateNetworkAccess;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {

        // PURE API: jangan pakai Sanctum SPA stateful middleware
        // $middleware->api(prepend: [
        //     \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        // ]);

        // Tambahkan middleware PNA (Private Network Access)
        $middleware->append(AllowPrivateNetworkAccess::class);

        // Alias middleware custom
        $middleware->alias([
            'role' => \App\Http\Middleware\RoleMiddleware::class,
            'referral.attach.guard' => \App\Http\Middleware\ReferralAttachGuard::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {

        /**
         * ✅ FIX UTAMA:
         * Kalau auth:sanctum gagal (unauthenticated),
         * jangan redirect ke route('login'), tapi balikin JSON 401.
         */
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
    
