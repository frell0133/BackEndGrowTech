<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;

class SystemAccessService
{
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

    public function get(string $key): array
    {
        $default = $this->defaults[$key] ?? [
            'enabled' => true,
            'message' => 'Layanan sedang maintenance.',
        ];

        $row = Setting::query()
            ->where('group', 'system')
            ->where('key', $key)
            ->first();

        if (!$row) {
            return $default;
        }

        $value = $row->value;

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($value)) {
            $value = [];
        }

        return [
            'enabled' => (bool) ($value['enabled'] ?? $default['enabled']),
            'message' => (string) ($value['message'] ?? $default['message']),
        ];
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
}