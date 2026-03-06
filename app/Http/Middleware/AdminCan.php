<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminCan
{
    public function handle(Request $request, Closure $next, string $permKey)
    {
        $u = $request->user();

        if (!$u || !$u->canAdmin($permKey)) {
            return response()->json([
                'success' => false,
                'data' => null,
                'meta' => (object) [],
                'error' => ['message' => 'Forbidden', 'details' => "Missing permission: {$permKey}"],
            ], 403);
        }

        return $next($request);
    }
}