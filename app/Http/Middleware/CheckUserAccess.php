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

        if (!$access->canAccessUserArea($user)) {
            return response()->json([
                'success' => false,
                'data' => null,
                'meta' => [
                    'maintenance' => true,
                    'scope' => 'user',
                    'key' => 'user_area_access',
                ],
                'error' => [
                    'message' => $access->message('user_area_access', 'Area user sedang maintenance.'),
                    'details' => null,
                ],
            ], 503);
        }

        return $next($request);
    }
}