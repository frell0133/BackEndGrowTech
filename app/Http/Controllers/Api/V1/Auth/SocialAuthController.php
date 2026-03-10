<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class SocialAuthController extends Controller
{
    protected array $allowedProviders = ['google', 'discord'];

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
            $providerId = (string) $socialUser->getId();

            $name = $socialUser->getName() ?? $socialUser->getNickname() ?? 'User';
            $avatar = $socialUser->getAvatar();

            if ($provider === 'discord') {
                $raw = $socialUser->user ?? [];

                if (!$avatar && isset($raw['id'], $raw['avatar']) && $raw['avatar']) {
                    $discordId = (string) $raw['id'];
                    $hash = (string) $raw['avatar'];
                    $ext = Str::startsWith($hash, 'a_') ? 'gif' : 'png';
                    $avatar = "https://cdn.discordapp.com/avatars/{$discordId}/{$hash}.{$ext}?size=256";
                }
            }

            $user = User::where('provider', $provider)
                ->where('provider_id', $providerId)
                ->first();

            if (!$user && $email) {
                $user = User::where('email', $email)->first();
            }

            if (!$user) {
                $user = User::create([
                    'name' => $name,
                    'email' => $email ?? (Str::uuid() . "@{$provider}.local"),
                    'password' => Hash::make(Str::random(32)),
                    'role' => 'user',
                    'tier' => 'member',
                    'provider' => $provider,
                    'provider_id' => $providerId,
                    'avatar' => $avatar,
                    'avatar_path' => null,
                ]);
            } else {
                $update = [
                    'provider' => $provider,
                    'provider_id' => $providerId,
                    'name' => $name,
                ];

                if (empty($user->avatar_path)) {
                    $update['avatar'] = $avatar;
                }

                if ($email && $user->email !== $email) {
                    $update['email'] = $email;
                }

                $user->update($update);
            }

            // Buat one-time code, bukan token final
            $exchangeCode = Str::random(96);

            Cache::put(
                'social_exchange:' . $exchangeCode,
                [
                    'user_id' => $user->id,
                    'provider' => $provider,
                ],
                now()->addMinute()
            );

            $frontend = rtrim(
                env('FRONTEND_URL', 'https://frontendgrowtechtesting1-production-dfb9.up.railway.app'),
                '/'
            );

            return redirect($frontend . '/auth/callback?code=' . urlencode($exchangeCode));
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