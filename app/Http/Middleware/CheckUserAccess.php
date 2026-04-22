<?php

namespace App\Http\Middleware;

use App\Services\SystemAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckUserAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var SystemAccessService $access */
        $access = app(SystemAccessService::class);

        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'data' => null,
                'meta' => (object) [],
                'error' => [
                    'message' => 'Unauthenticated',
                    'details' => null,
                ],
            ], 401);
        }

        if (!$access->canAccessUserArea($user)) {
            return response()->json([
                'success' => false,
                'data' => null,
                'meta' => [
                    'maintenance' => true,
                    'scope' => 'auth',
                    'key' => 'user_auth_access',
                ],
                'error' => [
                    'message' => $access->message('user_auth_access', 'Login dan area user sedang maintenance.', true),
                    'details' => null,
                ],
            ], 503, [
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ]);
        }

        return $next($request);
    }
}
