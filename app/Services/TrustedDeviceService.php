<?php

namespace App\Services;

use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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

        if ($this->shouldBindUserAgent() && $device->user_agent_hash && !hash_equals((string) $device->user_agent_hash, $this->userAgentHash($request))) {
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
            'user_agent_hash' => $this->shouldBindUserAgent() ? $this->userAgentHash($request) : null,
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
        return $response->withCookie(cookie(
            $this->cookieName(),
            '',
            -1,
            $this->cookiePath(),
            $this->cookieDomain(),
            $this->cookieSecure(),
            true,
            false,
            $this->sameSite()
        ));
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

    private function makeCookie(string $value, $expiresAt)
    {
        $minutes = max(1, now()->diffInMinutes($expiresAt));

        return cookie(
            $this->cookieName(),
            $value,
            $minutes,
            $this->cookiePath(),
            $this->cookieDomain(),
            $this->cookieSecure(),
            true,
            false,
            $this->sameSite()
        );
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
            return (bool) $configured;
        }

        return app()->environment('production');
    }

    private function sameSite(): ?string
    {
        return config('trusted_device.same_site', app()->environment('production') ? 'none' : 'lax');
    }

    private function shouldBindUserAgent(): bool
    {
        return (bool) config('trusted_device.bind_user_agent', true);
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

    private function userAgentHash(Request $request): string
    {
        return hash('sha256', $this->normalizeUserAgent((string) $request->userAgent()));
    }

    private function normalizeUserAgent(string $userAgent): string
    {
        $normalized = Str::lower(trim($userAgent));
        $normalized = preg_replace('/\/[0-9._]+/', '', $normalized) ?: $normalized;
        $normalized = preg_replace('/(version|chrome|crios|firefox|fxios|safari|edg|opr|opera)/', '$1', $normalized) ?: $normalized;
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?: $normalized;

        return trim($normalized);
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
