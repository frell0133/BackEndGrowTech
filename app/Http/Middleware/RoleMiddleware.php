<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'data' => null,
                'meta' => (object) [],
                'error' => ['message' => 'Unauthenticated', 'details' => null],
            ], 401);
        }

        // role bisa: admin / superadmin (atau sesuai enum kamu)
        if (($user->role ?? null) !== $role) {
            return response()->json([
                'success' => false,
                'data' => null,
                'meta' => (object) [],
                'error' => ['message' => 'Forbidden', 'details' => 'Insufficient role'],
            ], 403);
        }

        return $next($request);
    }
}
