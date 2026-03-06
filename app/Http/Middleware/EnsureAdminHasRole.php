<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminHasRole
{
    public function handle(Request $request, Closure $next)
    {
        $u = $request->user();

        // role:admin sudah ngecek role, tapi kita pastikan admin_role_id ada
        if (!$u || ($u->role ?? null) !== 'admin' || is_null($u->admin_role_id)) {
            return response()->json([
                'success' => false,
                'data' => null,
                'meta' => (object) [],
                'error' => ['message' => 'Forbidden', 'details' => 'Admin role not assigned'],
            ], 403);
        }

        return $next($request);
    }
}