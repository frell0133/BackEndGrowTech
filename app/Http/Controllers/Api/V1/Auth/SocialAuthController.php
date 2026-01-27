<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class SocialAuthController extends Controller
{
    private array $allowedProviders = ['google', 'discord'];

    public function redirect(string $provider)
    {
        abort_unless(in_array($provider, $this->allowedProviders, true), 404);

        return Socialite::driver($provider)
            ->stateless()
            ->redirect();
    }

    public function callback(string $provider)
    {
        abort_unless(in_array($provider, $this->allowedProviders, true), 404);

        try {
            $socialUser = Socialite::driver($provider)->stateless()->user();

            $email = $socialUser->getEmail();
            $providerId = $socialUser->getId();

            // Identity utama: provider + provider_id
            $user = User::where('provider', $provider)
                ->where('provider_id', $providerId)
                ->first();

            // Fallback kalau ada email
            if (!$user && $email) {
                $user = User::where('email', $email)->first();
            }

            if (!$user) {
                $user = User::create([
                    'name' => $socialUser->getName() ?? $socialUser->getNickname() ?? 'User',
                    'email' => $email ?? (Str::uuid()->toString() . '@' . $provider . '.local'),
                    'password' => Hash::make(Str::random(32)),
                    'role' => 'user',
                    'provider' => $provider,
                    'provider_id' => $providerId,
                    'avatar' => $socialUser->getAvatar(),
                ]);
            } else {
                $user->update([
                    'provider' => $provider,
                    'provider_id' => $providerId,
                    'avatar' => $socialUser->getAvatar(),
                ]);
            }

            $token = $user->createToken('api-token-social')->plainTextToken;

            // ✅ Google sering harus balik ke localhost/public domain, bukan IP 10.x
            $frontendDefault = rtrim(env('FRONTEND_URL', 'http://10.45.196.166:3000'), '/');
            $frontendLocal = rtrim(env('FRONTEND_URL_LOCAL', 'http://localhost:3000'), '/');

            $frontend = $provider === 'google' ? $frontendLocal : $frontendDefault;

            return redirect($frontend . '/auth/callback?token=' . urlencode($token));
        } catch (Throwable $e) {
            Log::error('Social login failed', [
                'provider' => $provider,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Social login failed',
                'provider' => $provider,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
