<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\Referral;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    use ApiResponse;

    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],

            // field baru yang disarankan
            'referrer_code' => ['nullable', 'string', 'max:50'],

            // legacy support untuk FE lama kamu
            'referral_code' => ['nullable', 'string', 'max:50'],
        ]);

        $rawReferrerCode = (string) (
            $data['referrer_code']
            ?? $data['referral_code']
            ?? ''
        );

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

        return DB::transaction(function () use ($data, $referrer) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'role' => 'user',
                'tier' => 'member',
            ]);

            if ($referrer) {
                Referral::updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'referred_by' => $referrer->id,
                        'locked_at'   => now(),
                    ]
                );
            }

            $token = $user->createToken('api-token')->plainTextToken;

            return $this->ok([
                'user' => $user->only('id', 'name', 'email', 'role', 'tier', 'referral_code'),
                'referral' => [
                    'used_referrer_code' => $referrer?->referral_code,
                    'referrer' => $referrer
                        ? $referrer->only('id', 'name', 'email', 'referral_code')
                        : null,
                ],
                'token' => $token,
                'token_type' => 'Bearer',
            ], 201);
        });
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
            'remember' => ['nullable', 'boolean'],
        ]);

        $remember = (bool) ($credentials['remember'] ?? false);
        unset($credentials['remember']);

        if (!Auth::attempt($credentials, $remember)) {
            throw ValidationException::withMessages([
                'email' => ['Email atau password salah'],
            ]);
        }

        $user = Auth::user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'data' => null,
                'meta' => (object) [],
                'error' => ['message' => 'Unauthenticated'],
            ], 401);
        }

        $tokenName = $remember ? 'api-token-remember' : 'api-token';
        $token = $user->createToken($tokenName)->plainTextToken;

        return $this->ok([
            'user' => $user->only('id', 'name', 'email', 'role', 'tier', 'referral_code'),
            'token' => $token,
            'token_type' => 'Bearer',
        ]);
    }

    public function logout(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'data' => null,
                'meta' => (object) [],
                'error' => ['message' => 'Unauthenticated'],
            ], 401);
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
            return response()->json([
                'success' => false,
                'data' => null,
                'meta' => (object) [],
                'error' => ['message' => 'Unauthenticated'],
            ], 401);
        }

        return $this->ok(
            $user->only('id', 'name', 'email', 'role', 'tier', 'referral_code')
        );
    }
}