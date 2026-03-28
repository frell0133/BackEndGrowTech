<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class SystemAccessService
{
    private const CACHE_VERSION_KEY = 'system_access:version';
    private const CACHE_KEY_PREFIX = 'system_access:payload';
    private const CACHE_TTL_SECONDS = 600;

    /**
     * Default toggle agar sistem tetap normal
     * walaupun setting belum pernah disimpan.
     */
    private array $defaults = [
        'public_access' => [
            'enabled' => true,
            'message' => 'Website sedang maintenance.',
        ],
        'user_auth_access' => [
            'enabled' => true,
            'message' => 'Login dan registrasi user sedang maintenance.',
        ],
        'user_area_access' => [
            'enabled' => true,
            'message' => 'Area user sedang maintenance.',
        ],
        'catalog_access' => [
            'enabled' => true,
            'message' => 'Katalog sedang maintenance.',
        ],
        'checkout_access' => [
            'enabled' => true,
            'message' => 'Checkout sedang maintenance.',
        ],
        'topup_access' => [
            'enabled' => true,
            'message' => 'Top up wallet sedang maintenance.',
        ],
    ];

    private ?array $resolved = null;

    public static function bumpCacheVersion(): void
    {
        if (!Cache::has(self::CACHE_VERSION_KEY)) {
            Cache::forever(self::CACHE_VERSION_KEY, 1);
        }

        Cache::increment(self::CACHE_VERSION_KEY);
    }

    public function all(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $version = $this->currentVersion();
        $cacheKey = self::CACHE_KEY_PREFIX . ':v' . $version;

        $this->resolved = Cache::remember(
            $cacheKey,
            now()->addSeconds(self::CACHE_TTL_SECONDS),
            fn () => $this->loadResolvedSettings()
        );

        return $this->resolved;
    }

    public function getMany(array $keys): array
    {
        $all = $this->all();
        $result = [];

        foreach ($keys as $key) {
            $default = $this->defaults[$key] ?? [
                'enabled' => true,
                'message' => 'Layanan sedang maintenance.',
            ];

            $result[$key] = $all[$key] ?? $default;
        }

        return $result;
    }

    public function featurePayload(array $keys): array
    {
        return $this->getMany($keys);
    }

    public function get(string $key): array
    {
        return $this->getMany([$key])[$key];
    }

    public function enabled(string $key): bool
    {
        return (bool) ($this->get($key)['enabled'] ?? true);
    }

    public function message(string $key, ?string $fallback = null): string
    {
        return (string) ($this->get($key)['message'] ?? ($fallback ?: 'Layanan sedang maintenance.'));
    }

    public function isAdmin(?User $user): bool
    {
        return $user && strtolower((string) $user->role) === 'admin';
    }

    public function canUserAuthenticate(?User $user): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        return $this->enabled('user_auth_access');
    }

    public function canAccessUserArea(?User $user): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        return $this->enabled('user_area_access');
    }

    public function canUseFeature(?User $user, string $featureKey): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        return $this->enabled($featureKey);
    }

    private function currentVersion(): int
    {
        $value = Cache::get(self::CACHE_VERSION_KEY);

        if (!$value) {
            Cache::forever(self::CACHE_VERSION_KEY, 1);
            return 1;
        }

        return (int) $value;
    }

    private function loadResolvedSettings(): array
    {
        $resolved = $this->defaults;

        $rows = Setting::query()
            ->where('group', 'system')
            ->get(['key', 'value']);

        foreach ($rows as $row) {
            $key = (string) $row->key;
            $default = $this->defaults[$key] ?? [
                'enabled' => true,
                'message' => 'Layanan sedang maintenance.',
            ];

            $value = $row->value;

            if (is_string($value)) {
                $decoded = json_decode($value, true);
                $value = is_array($decoded) ? $decoded : [];
            }

            if (!is_array($value)) {
                $value = [];
            }

            $resolved[$key] = [
                'enabled' => (bool) ($value['enabled'] ?? $default['enabled']),
                'message' => (string) ($value['message'] ?? $default['message']),
            ];
        }

        return $resolved;
    }
}
