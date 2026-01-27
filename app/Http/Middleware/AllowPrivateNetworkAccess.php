<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AllowPrivateNetworkAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Header wajib untuk Chrome Private Network Access
        $response->headers->set('Access-Control-Allow-Private-Network', 'true');

        // Optional tapi aman
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');

        return $response;
    }
}
