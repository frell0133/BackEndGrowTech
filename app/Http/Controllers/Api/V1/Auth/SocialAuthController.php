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

            $email      = $socialUser->getEmail();
            $providerId = (string) $socialUser->getId();

            // 1) Nama user
            $name = $socialUser->getName()
                ?? $socialUser->getNickname()
                ?? 'User';

            // 2) Avatar dari provider (Google biasanya OK)
            $avatar = $socialUser->getAvatar();

            // ✅ Discord: build CDN URL dari raw payload kalau perlu
            if ($provider === 'discord') {
                $raw = $socialUser->user ?? [];

                // Kalau getAvatar() sudah URL, biarkan.
                // Kalau kosong, coba build sendiri
                if (!$avatar && isset($raw['id'], $raw['avatar']) && $raw['avatar']) {
                    $discordId = (string) $raw['id'];
                    $hash      = (string) $raw['avatar'];
                    $ext       = Str::startsWith($hash, 'a_') ? 'gif' : 'png';

                    $avatar = "https://cdn.discordapp.com/avatars/{$discordId}/{$hash}.{$ext}?size=256";
                }
            }

            // 3) Cari user (provider_id > email)
            $user = User::where('provider', $provider)
                ->where('provider_id', $providerId)
                ->first();

            if (!$user && $email) {
                $user = User::where('email', $email)->first();
            }

            // 4) Create user baru
            if (!$user) {
                $user = User::create([
                    'name' => $name,
                    'email' => $email ?? (Str::uuid() . "@{$provider}.local"),
                    'password' => Hash::make(Str::random(32)),
                    'role' => 'user',
                    'provider' => $provider,
                    'provider_id' => $providerId,
                    'avatar' => $avatar,     // ✅ simpan avatar provider
                    'avatar_path' => null,   // ✅ default
                ]);
            } else {
                // 5) Update user existing
                $update = [
                    'provider' => $provider,
                    'provider_id' => $providerId,
                    'name' => $name,
                ];

                // 🔐 Jangan override kalau user sudah upload avatar custom
                if (empty($user->avatar_path)) {
                    $update['avatar'] = $avatar;
                }

                if ($email && $user->email !== $email) {
                    $update['email'] = $email;
                }

                $user->update($update);
            }

            // 6) Token Sanctum
            $token = $user->createToken('api-token-social')->plainTextToken;

            // 7) Redirect ke FE
            $frontendDefault = rtrim(
                env('FRONTEND_URL', 'https://frontendgrowtechtesting1-production.up.railway.app'),
                '/'
            );

            $frontendLocal = rtrim(
                env('FRONTEND_URL_LOCAL', $frontendDefault),
                '/'
            );

            $frontend = $provider === 'google'
                ? $frontendLocal
                : $frontendDefault;

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
