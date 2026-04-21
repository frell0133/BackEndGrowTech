<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use App\Support\RuntimeCache;

class SystemAccessService
{
    private const CACHE_VERSION_KEY = 'system_access:version';

    /**
     * Maintenance toggle harus terasa real-time.
     * Karena itu data dibaca fresh dari database pada setiap request,
     * lalu hanya di-memo di lifecycle request yang sama.
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
        if (!RuntimeCache::has(self::CACHE_VERSION_KEY)) {
            RuntimeCache::forever(self::CACHE_VERSION_KEY, 1);
        }

        RuntimeCache::increment(self::CACHE_VERSION_KEY);
        RuntimeCache::flushMemo();
    }

    public function all(bool $fresh = false): array
    {
        if (!$fresh && $this->resolved !== null) {
            return $this->resolved;
        }

        $this->resolved = $this->loadResolvedSettings();

        return $this->resolved;
    }

    public function getMany(array $keys, bool $fresh = false): array
    {
        $all = $this->all($fresh);
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

    public function featurePayload(array $keys, bool $fresh = false): array
    {
        return $this->getMany($keys, $fresh);
    }

    public function get(string $key, bool $fresh = false): array
    {
        return $this->getMany([$key], $fresh)[$key];
    }

    public function enabled(string $key, bool $fresh = false): bool
    {
        return (bool) ($this->get($key, $fresh)['enabled'] ?? true);
    }

    public function message(string $key, ?string $fallback = null, bool $fresh = false): string
    {
        return (string) ($this->get($key, $fresh)['message'] ?? ($fallback ?: 'Layanan sedang maintenance.'));
    }

    public function isAdmin(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        if (method_exists($user, 'isAdmin')) {
            try {
                return (bool) $user->isAdmin();
            } catch (\Throwable $e) {
                // fallback below
            }
        }

        return strtolower((string) $user->role) === 'admin'
            && !is_null($user->admin_role_id ?? null);
    }

    public function canUserAuthenticate(?User $user): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        return $this->enabled('user_auth_access', true);
    }

    public function canAccessUserArea(?User $user): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        return $this->enabled('user_area_access', true);
    }

    public function canUseFeature(?User $user, string $featureKey): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        return $this->enabled($featureKey, true);
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
