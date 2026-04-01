<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\Referral;
use App\Models\User;
use App\Services\SystemAccessService;
use App\Services\TrustedDeviceService;
use App\Services\TwoFactorService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    use ApiResponse;

    public function register(Request $request, TwoFactorService $twoFactor, SystemAccessService $access)
    {
        if (!$access->enabled('user_auth_access')) {
            return $this->fail(
                $access->message('user_auth_access', 'Registrasi user sedang maintenance.'),
                503,
                ['maintenance' => true, 'key' => 'user_auth_access']
            );
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'referrer_code' => ['nullable', 'string', 'max:50'],
            'referral_code' => ['nullable', 'string', 'max:50'],
            'remember' => ['nullable', 'boolean'],
        ]);

        $remember = (bool) ($data['remember'] ?? true);
        $rawReferrerCode = (string) ($data['referrer_code'] ?? $data['referral_code'] ?? '');
        $referrerCode = strtoupper(trim($rawReferrerCode));
        $referrer = null;

        if ($referrerCode !== '') {
            $referrer = User::query()
                ->where('referral_code', $referrerCode)
                ->first();

            if (!$referrer) {
                return $this->fail('Referral code tidak valid', 422);
            }
        }

        $user = DB::transaction(function () use ($data, $referrer) {
            $user = User::create([
                'name' => $data['name'],
                'email' => strtolower(trim($data['email'])),
                'password' => Hash::make($data['password']),
                'role' => 'user',
                'tier' => 'member',
            ]);

            if ($referrer) {
                Referral::updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'referred_by' => $referrer->id,
                        'locked_at' => now(),
                    ]
                );
            }

            return $user;
        });

        $challenge = $twoFactor->startChallenge($user, 'register', [
            'remember' => $remember,
            'provider' => 'manual',
        ]);

        if (!$challenge['ok']) {
            $user->delete();

            return $this->fail(
                $challenge['message'] ?? 'Gagal mengirim OTP registrasi.',
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
            'remember' => $remember,
            'user' => $this->serializeUser($user),
            'referral' => [
                'used_referrer_code' => $referrer?->referral_code,
                'referrer' => $referrer ? $referrer->only('id', 'name', 'email', 'referral_code') : null,
            ],
        ], 201);
    }

    public function login(
        Request $request,
        TwoFactorService $twoFactor,
        SystemAccessService $access,
        TrustedDeviceService $trustedDeviceService
    ) {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
            'remember' => ['nullable', 'boolean'],
        ]);

        $remember = (bool) ($credentials['remember'] ?? true);

        $user = User::query()
            ->where('email', strtolower(trim((string) $credentials['email'])))
            ->first();

        if (!$user || !Hash::check((string) $credentials['password'], (string) $user->password)) {
            return $this->fail('Email atau password salah', 422);
        }

        if (!$access->canUserAuthenticate($user)) {
            return $this->fail(
                $access->message('user_auth_access', 'Login user sedang maintenance.'),
                503,
                ['maintenance' => true, 'key' => 'user_auth_access']
            );
        }

        $trustedDevice = $trustedDeviceService->hasValidTrustedDevice($user, $request);

        if ($trustedDevice) {
            $token = $user->createToken('api-token-trusted-device')->plainTextToken;

            $response = $this->ok([
                'requires_2fa' => false,
                'trusted_device' => true,
                'user' => $this->serializeUser($user),
                'token' => $token,
                'token_type' => 'Bearer',
            ]);

            return $trustedDeviceService->rotateTrustedDevice($response, $trustedDevice, $request);
        }

        $challenge = $twoFactor->startChallenge($user, 'login', [
            'remember' => $remember,
            'provider' => 'manual',
        ]);

        if (!$challenge['ok']) {
            return $this->fail(
                $challenge['message'] ?? 'Gagal mengirim OTP login.',
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
            'remember' => $remember,
            'user' => $this->serializeUser($user),
        ]);
    }

    public function logout(Request $request, TrustedDeviceService $trustedDeviceService)
    {
        $user = $request->user();

        if (!$user) {
            return $this->fail('Unauthenticated', 401);
        }

        $token = $user->currentAccessToken();

        if ($token) {
            $token->delete();
        }

        $response = $this->ok(['message' => 'Logged out']);

        if ((bool) $request->boolean('forget_trusted_device')) {
            $trustedDeviceService->revokeAllForUser($user);
            return $trustedDeviceService->clearTrustedDeviceCookie($response);
        }

        return $response;
    }

    public function me(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return $this->fail('Unauthenticated', 401);
        }

        return $this->ok($this->serializeUser($user));
    }

    private function serializeUser(User $user): array
    {
        return $user->only('id', 'name', 'email', 'role', 'tier', 'referral_code');
    }
}
