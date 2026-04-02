<?php

namespace App\Services;

use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

class TrustedDeviceService
{
    public function hasValidTrustedDevice(User $user, Request $request): ?TrustedDevice
    {
        if (!$this->isEligible($user) || !$user->email_verified_at) {
            return null;
        }

        $parsed = $this->parseCookieValue((string) $request->cookie($this->cookieName()));

        if (!$parsed) {
            return null;
        }

        $device = TrustedDevice::query()
            ->where('user_id', $user->id)
            ->where('selector', $parsed['selector'])
            ->first();

        if (!$device) {
            return null;
        }

        if ($device->revoked_at || !$device->expires_at || now()->greaterThan($device->expires_at)) {
            return null;
        }

        if (!hash_equals((string) $device->token_hash, hash('sha256', $parsed['token']))) {
            return null;
        }

        if (
            $this->shouldBindUserAgent()
            && $device->user_agent_hash
            && !hash_equals((string) $device->user_agent_hash, $this->userAgentHash($request))
        ) {
            return null;
        }

        return $device;
    }

    public function attachRememberedDevice(JsonResponse $response, User $user, Request $request, ?TrustedDevice $device = null): JsonResponse
    {
        if (!$this->isEligible($user)) {
            return $response;
        }

        $selector = $device?->selector ?: Str::random(24);
        $plainToken = Str::random(64);
        $expiresAt = now()->addDays($this->rememberDays($user));

        $payload = [
            'user_id' => $user->id,
            'selector' => $selector,
            'token_hash' => hash('sha256', $plainToken),
            'device_name' => $this->resolveDeviceName($request),
            'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
            'user_agent_hash' => $this->userAgentHash($request),
            'last_ip' => $request->ip(),
            'last_used_at' => now(),
            'expires_at' => $expiresAt,
            'revoked_at' => null,
        ];

        if ($device) {
            $device->update($payload);
        } else {
            $device = TrustedDevice::create($payload);
        }

        return $response->withCookie($this->makeCookie($selector . '|' . $plainToken, $expiresAt));
    }

    public function rotateTrustedDevice(JsonResponse $response, TrustedDevice $device, Request $request): JsonResponse
    {
        return $this->attachRememberedDevice($response, $device->user, $request, $device);
    }

    public function clearTrustedDeviceCookie(JsonResponse $response): JsonResponse
    {
        return $response->withCookie($this->makeCookie('', now()->subYear()));
    }

    public function revokeAllForUser(User $user): int
    {
        return TrustedDevice::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
            ]);
    }

    private function parseCookieValue(string $value): ?array
    {
        $value = trim($value);

        if ($value === '' || !str_contains($value, '|')) {
            return null;
        }

        [$selector, $token] = explode('|', $value, 2);

        if ($selector === '' || $token === '') {
            return null;
        }

        return [
            'selector' => $selector,
            'token' => $token,
        ];
    }

    private function makeCookie(string $value, $expiresAt): SymfonyCookie
    {
        $sameSite = $this->normalizedSameSite();
        $secure = $this->cookieSecure();
        $partitioned = $this->cookiePartitioned();

        if ($partitioned) {
            $sameSite = SymfonyCookie::SAMESITE_NONE;
            $secure = true;
        }

        $cookie = SymfonyCookie::create(
            $this->cookieName(),
            $value,
            $expiresAt,
            $this->cookiePath(),
            $this->cookieDomain(),
            $secure,
            true,
            false,
            $sameSite
        );

        if ($partitioned && method_exists($cookie, 'withPartitioned')) {
            $cookie = $cookie->withPartitioned(true);
        }

        return $cookie;
    }

    private function cookieName(): string
    {
        return (string) config('trusted_device.cookie_name', 'gt_trusted_device');
    }

    private function cookiePath(): string
    {
        return (string) config('trusted_device.cookie_path', '/');
    }

    private function cookieDomain(): ?string
    {
        $value = config('trusted_device.cookie_domain');

        return $value !== null && $value !== '' ? (string) $value : null;
    }

    private function cookieSecure(): bool
    {
        $configured = config('trusted_device.secure');

        if ($configured !== null) {
            return filter_var($configured, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? (bool) $configured;
        }

        return app()->environment('production');
    }

    private function sameSite(): ?string
    {
        return config('trusted_device.same_site', app()->environment('production') ? 'none' : 'lax');
    }

    private function normalizedSameSite(): ?string
    {
        $value = Str::lower(trim((string) $this->sameSite()));

        return match ($value) {
            'lax' => SymfonyCookie::SAMESITE_LAX,
            'strict' => SymfonyCookie::SAMESITE_STRICT,
            'none' => SymfonyCookie::SAMESITE_NONE,
            '' => null,
            default => app()->environment('production')
                ? SymfonyCookie::SAMESITE_NONE
                : SymfonyCookie::SAMESITE_LAX,
        };
    }

    private function cookiePartitioned(): bool
    {
        return (bool) config('trusted_device.partitioned', false);
    }

    private function rememberDays(User $user): int
    {
        return $user->role === 'admin'
            ? (int) config('trusted_device.admin_days', 7)
            : (int) config('trusted_device.days', 30);
    }

    private function isEligible(User $user): bool
    {
        if ($user->role === 'admin') {
            return (bool) config('trusted_device.allow_admin', true);
        }

        return true;
    }

    private function shouldBindUserAgent(): bool
    {
        return (bool) config('trusted_device.bind_user_agent', true);
    }

    private function userAgentHash(Request $request): string
    {
        return hash('sha256', $this->normalizeUserAgent((string) $request->userAgent()));
    }

    private function normalizeUserAgent(string $userAgent): string
    {
        return Str::lower(trim($userAgent));
    }

    private function resolveDeviceName(Request $request): ?string
    {
        $platform = (string) $request->header('Sec-CH-UA-Platform', '');
        $ua = trim((string) $request->userAgent());

        if ($platform !== '') {
            return Str::limit($platform . ' | ' . $ua, 255, '');
        }

        return $ua !== '' ? Str::limit($ua, 255, '') : null;
    }
}
