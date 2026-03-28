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
    private array $allowedProviders = ['google', 'discord'];

    public function redirect(string $provider)
    {
        abort_unless(in_array($provider, $this->allowedProviders, true), 404);

        return $this->driver($provider)->redirect();
    }

    public function callback(string $provider)
    {
        abort_unless(in_array($provider, $this->allowedProviders, true), 404);

        try {
            $socialUser = $this->driver($provider)->user();

            $email = strtolower(trim((string) ($socialUser->getEmail() ?? '')));
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

            $user = User::query()
                ->where('provider', $provider)
                ->where('provider_id', $providerId)
                ->first();

            if (!$user && $email !== '') {
                $user = User::query()->where('email', $email)->first();
            }

            if (!$user && $email === '') {
                Log::warning('Social login email missing', [
                    'provider' => $provider,
                    'provider_id' => $providerId,
                ]);

                return redirect($this->frontendUrl() . '/auth/callback?error=email_required');
            }

            if (!$user) {
                $user = User::create([
                    'name' => $name,
                    'email' => $email,
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

                if ($email !== '' && $user->email !== $email) {
                    $update['email'] = $email;
                }

                $user->update($update);
                $user->refresh();
            }

            $effectiveEmail = strtolower(trim((string) ($user->email ?? '')));

            if ($effectiveEmail === '' || str_ends_with($effectiveEmail, '.local')) {
                Log::warning('Social login cannot continue because email is not deliverable', [
                    'provider' => $provider,
                    'user_id' => $user->id,
                    'email' => $user->email,
                ]);

                return redirect($this->frontendUrl() . '/auth/callback?error=email_required');
            }

            $exchangeCode = Str::random(96);

            Cache::put(
                'social_exchange:' . $exchangeCode,
                [
                    'user_id' => $user->id,
                    'provider' => $provider,
                ],
                now()->addMinutes(5)
            );

            return redirect($this->frontendUrl() . '/auth/callback?code=' . urlencode($exchangeCode));
        } catch (Throwable $e) {
            Log::error('Social login failed', [
                'provider' => $provider,
                'message' => $e->getMessage(),
            ]);

            return redirect($this->frontendUrl() . '/auth/callback?error=social_login_failed');
        }
    }

    private function driver(string $provider)
    {
        $driver = Socialite::driver($provider)->stateless();

        return match ($provider) {
            'google' => $driver->scopes(['openid', 'profile', 'email']),
            'discord' => $driver->scopes(['identify', 'email']),
            default => $driver,
        };
    }

    private function frontendUrl(): string
    {
        return rtrim(
            (string) (config('app.frontend_url') ?: env('FRONTEND_URL', 'https://frontendgrowtechtesting1-production-6d21.up.railway.app/')),
            '/'
        );
    }
}