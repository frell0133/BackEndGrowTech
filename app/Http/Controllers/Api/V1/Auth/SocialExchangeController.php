<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SocialExchangeController extends Controller
{
    public function exchange(Request $request)
    {
        $validated = $request->validate([
            'code' => ['required', 'string'],
        ]);

        $cacheKey = 'social_exchange:' . $validated['code'];
        $payload = Cache::pull($cacheKey);

        if (!$payload || empty($payload['user_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'Code tidak valid atau sudah kadaluarsa.',
            ], 422);
        }

        $user = User::find($payload['user_id']);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User tidak ditemukan.',
            ], 404);
        }

        $token = $user->createToken('api-token-social')->plainTextToken;

        return response()->json([
            'success' => true,
            'data' => [
                'token' => $token,
                'user' => $user,
            ],
        ]);
    }
}