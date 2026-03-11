<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuthChallenge;
use App\Models\User;
use App\Services\TwoFactorService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class TwoFactorController extends Controller
{
    use ApiResponse;

    public function verify(Request $request, TwoFactorService $twoFactor)
    {
        $data = $request->validate([
            'challenge_id' => ['required', 'string'],
            'code' => ['required', 'digits:6'],
        ]);

        $result = $twoFactor->verifyChallenge(
            (string) $data['challenge_id'],
            (string) $data['code']
        );

        if (!$result['ok']) {
            return $this->fail(
                $result['message'] ?? 'OTP tidak valid.',
                $result['status'] ?? 422,
                $result['details'] ?? null
            );
        }

        /** @var AuthChallenge $challenge */
        $challenge = $result['challenge'];
        /** @var User|null $user */
        $user = $challenge->user;

        if (!$user) {
            return $this->fail('User challenge tidak ditemukan.', 404);
        }

        if (!$user->email_verified_at && $this->isDeliverableEmail((string) $user->email)) {
            $user->forceFill([
                'email_verified_at' => now(),
            ])->save();
        }

        $tokenName = $this->tokenNameFromChallenge($challenge);
        $token = $user->createToken($tokenName)->plainTextToken;

        return $this->ok([
            'user' => $this->serializeUser($user->fresh()),
            'token' => $token,
            'token_type' => 'Bearer',
        ]);
    }

    public function resend(Request $request, TwoFactorService $twoFactor)
    {
        $data = $request->validate([
            'challenge_id' => ['required', 'string'],
        ]);

        $result = $twoFactor->resendChallenge((string) $data['challenge_id']);

        if (!$result['ok']) {
            return $this->fail(
                $result['message'] ?? 'Gagal mengirim ulang OTP.',
                $result['status'] ?? 422,
                $result['details'] ?? null
            );
        }

        return $this->ok([
            'challenge_id' => $result['challenge_id'],
            'expires_in' => $result['expires_in'],
            'email_hint' => $result['email_hint'],
            'message' => $result['message'] ?? 'OTP berhasil dikirim ulang.',
        ]);
    }

    private function tokenNameFromChallenge(AuthChallenge $challenge): string
    {
        if ($challenge->purpose === 'social') {
            return 'api-token-social';
        }

        return $challenge->remember ? 'api-token-remember' : 'api-token';
    }

    private function isDeliverableEmail(string $email): bool
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        return !str_ends_with(strtolower($email), '.local');
    }

    private function serializeUser(User $user): array
    {
        return $user->only('id', 'name', 'email', 'role', 'tier', 'referral_code');
    }
}