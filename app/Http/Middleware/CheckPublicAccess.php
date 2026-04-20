<?php

namespace App\Http\Middleware;

use App\Services\SystemAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPublicAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var SystemAccessService $access */
        $access = app(SystemAccessService::class);

        if (!$access->enabled('public_access', true)) {
            return response()->json([
                'success' => false,
                'data' => null,
                'meta' => [
                    'maintenance' => true,
                    'scope' => 'public',
                    'key' => 'public_access',
                ],
                'error' => [
                    'message' => $access->message('public_access', 'Halaman publik sedang maintenance.', true),
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
