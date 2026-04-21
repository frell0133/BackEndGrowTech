<?php

namespace App\Http\Middleware;

use App\Services\SystemAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckFeatureAccess
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        /** @var SystemAccessService $access */
        $access = app(SystemAccessService::class);

        $map = [
            'catalog' => 'catalog_access',
            'checkout' => 'checkout_access',
            'topup' => 'topup_access',
        ];

        $key = $map[$feature] ?? $feature;

        if (!$access->canUseFeature($request->user(), $key)) {
            return response()->json([
                'success' => false,
                'data' => null,
                'meta' => [
                    'maintenance' => true,
                    'scope' => $feature,
                    'feature' => $feature,
                    'key' => $key,
                ],
                'error' => [
                    'message' => $access->message($key, 'Fitur sedang maintenance.', true),
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
