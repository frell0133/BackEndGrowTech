<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class IsAdmin
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        // sesuaikan: contoh pakai kolom is_admin boolean
        if (!$user || !$user->is_admin) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden (admin only)',
            ], 403);
        }

        return $next($request);
    }
}
