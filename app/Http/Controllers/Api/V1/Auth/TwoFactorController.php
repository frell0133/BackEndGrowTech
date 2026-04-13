<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuthChallenge;
use App\Models\User;
use App\Services\SystemAccessService;
use App\Services\TrustedDeviceService;
use App\Services\TwoFactorService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class TwoFactorController extends Controller
{
    use ApiResponse;

    public function verify(
        Request $request,
        TwoFactorService $twoFactor,
        SystemAccessService $access,
        TrustedDeviceService $trustedDeviceService
    ) {
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

        if (!$access->canUserAuthenticate($user)) {
            return $this->fail(
                $access->message('user_auth_access', 'Login user sedang maintenance.'),
                503,
                ['maintenance' => true, 'key' => 'user_auth_access']
            );
        }

        if (!$user->email_verified_at && $this->isDeliverableEmail((string) $user->email)) {
            $user->forceFill([
                'email_verified_at' => now(),
            ])->save();
        }

        $tokenName = $this->tokenNameFromChallenge($challenge);
        $token = $user->createToken($tokenName)->plainTextToken;

        $payload = [
            'user' => $this->serializeUser($user->fresh()),
            'token' => $token,
            'token_type' => 'Bearer',
            'trusted_device' => (bool) $challenge->remember,
        ];

        $response = $this->ok($payload);

        if ((bool) $challenge->remember) {
            $issued = $trustedDeviceService->issueRememberedDevicePayload($user->fresh(), $request);

            $response = $this->ok(array_merge($payload, [
                'trusted_device_credential' => $issued['credential'],
                'trusted_device_expires_at' => $issued['expires_at'],
            ]));

            return $response->withCookie(cookie(
                config('trusted_device.cookie_name', 'gt_trusted_device'),
                $issued['credential'],
                max(1, now()->diffInMinutes($issued['device']->expires_at)),
                config('trusted_device.cookie_path', '/'),
                config('trusted_device.cookie_domain'),
                (bool) config('trusted_device.secure', app()->environment('production')),
                true,
                false,
                config('trusted_device.same_site', app()->environment('production') ? 'none' : 'lax')
            ));
        }

        return $trustedDeviceService->clearTrustedDeviceCookie($response);
    }

    public function resend(Request $request, TwoFactorService $twoFactor, SystemAccessService $access)
    {
        $data = $request->validate([
            'challenge_id' => ['required', 'string'],
        ]);

        $challenge = AuthChallenge::query()
            ->with('user')
            ->where('challenge_id', (string) $data['challenge_id'])
            ->first();

        if ($challenge && $challenge->user && !$access->canUserAuthenticate($challenge->user)) {
            return $this->fail(
                $access->message('user_auth_access', 'Pengiriman ulang OTP sedang dinonaktifkan sementara.'),
                503,
                ['maintenance' => true, 'key' => 'user_auth_access']
            );
        }

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