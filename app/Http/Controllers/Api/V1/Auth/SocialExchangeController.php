<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TwoFactorService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SocialExchangeController extends Controller
{
    use ApiResponse;

    public function exchange(Request $request, TwoFactorService $twoFactor)
    {
        $validated = $request->validate([
            'code' => ['required', 'string'],
        ]);

        $payload = Cache::pull('social_exchange:' . $validated['code']);

        if (!$payload || empty($payload['user_id'])) {
            return $this->fail('Code tidak valid atau sudah kadaluarsa.', 422);
        }

        $user = User::find($payload['user_id']);

        if (!$user) {
            return $this->fail('User tidak ditemukan.', 404);
        }

        $challenge = $twoFactor->startChallenge($user, 'social', [
            'remember' => false,
            'provider' => (string) ($payload['provider'] ?? 'social'),
            'meta' => [
                'provider' => (string) ($payload['provider'] ?? 'social'),
            ],
        ]);

        if (!$challenge['ok']) {
            return $this->fail(
                $challenge['message'] ?? 'Gagal mengirim OTP login sosial.',
                $challenge['status'] ?? 500,
                $challenge['details'] ?? null
            );
        }

        return $this->ok([
            'requires_2fa' => true,
            'challenge_id' => $challenge['challenge_id'],
            'channel' => 'email',
            'expires_in' => $challenge['expires_in'],
            'email_hint' => $challenge['email_hint'],
            'user' => $user->only('id', 'name', 'email', 'role', 'tier', 'referral_code'),
        ]);
    }
}