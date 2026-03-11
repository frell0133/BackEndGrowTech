<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\Referral;
use App\Models\User;
use App\Services\TwoFactorService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    use ApiResponse;

    public function register(Request $request, TwoFactorService $twoFactor)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'referrer_code' => ['nullable', 'string', 'max:50'],
            'referral_code' => ['nullable', 'string', 'max:50'], // legacy FE support
        ]);

        $rawReferrerCode = (string) ($data['referrer_code'] ?? $data['referral_code'] ?? '');
        $referrerCode = User::normalizeReferralCode($rawReferrerCode);
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
            'remember' => false,
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
            'user' => $this->serializeUser($user),
            'referral' => [
                'used_referrer_code' => $referrer?->referral_code,
                'referrer' => $referrer ? $referrer->only('id', 'name', 'email', 'referral_code') : null,
            ],
        ], 201);
    }

    public function login(Request $request, TwoFactorService $twoFactor)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
            'remember' => ['nullable', 'boolean'],
        ]);

        $remember = (bool) ($credentials['remember'] ?? false);

        $user = User::query()
            ->where('email', strtolower(trim((string) $credentials['email'])))
            ->first();

        if (!$user || !Hash::check((string) $credentials['password'], (string) $user->password)) {
            return $this->fail('Email atau password salah', 422);
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
            'user' => $this->serializeUser($user),
        ]);
    }

    public function logout(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return $this->fail('Unauthenticated', 401);
        }

        $token = $user->currentAccessToken();

        if ($token) {
            $token->delete();
        }

        return $this->ok(['message' => 'Logged out']);
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