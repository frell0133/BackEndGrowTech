<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next)
    {
        $u = $request->user();

        if (!$u || !$u->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'data' => null,
                'meta' => (object) [],
                'error' => [
                    'message' => 'Forbidden',
                    'details' => 'Only owner/super admin can manage RBAC',
                ],
            ], 403);
        }

        return $next($request);
    }
}