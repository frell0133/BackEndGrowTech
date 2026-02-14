<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ReferralAttachGuard
{
    public function handle(Request $request, Closure $next)
    {
        $ip = (string) $request->ip();
        $ua = (string) $request->userAgent();

        // device fingerprint simple (IP + UA)
        $deviceHash = hash('sha256', $ip . '|' . $ua);

        // rate limit keys
        $keyIp = "ref_attach:ip:{$ip}";
        $keyDevice = "ref_attach:dev:{$deviceHash}";

        $max = 10;           // 10 attempts
        $ttlSeconds = 600;   // 10 minutes

        $ipCount = (int) Cache::get($keyIp, 0);
        $devCount = (int) Cache::get($keyDevice, 0);

        if ($ipCount >= $max || $devCount >= $max) {
            return response()->json([
                'success' => false,
                'data' => null,
                'meta' => (object) [],
                'error' => [
                    'message' => 'Terlalu banyak percobaan attach referral. Coba lagi beberapa menit lagi.',
                    'details' => [
                        'ip' => $ipCount,
                        'device' => $devCount,
                        'limit' => $max,
                        'window_seconds' => $ttlSeconds,
                    ],
                ],
            ], 429);
        }

        // increment
        Cache::put($keyIp, $ipCount + 1, $ttlSeconds);
        Cache::put($keyDevice, $devCount + 1, $ttlSeconds);

        return $next($request);
    }
}
