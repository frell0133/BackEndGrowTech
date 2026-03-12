<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentGateway extends Model
{
    protected $fillable = [
        'code',
        'provider',
        'driver',
        'name',
        'description',
        'is_active',
        'is_default_order',
        'is_default_topup',
        'supports_order',
        'supports_topup',
        'sandbox_mode',
        'fee_type',
        'fee_value',
        'sort_order',
        'config',
        'secret_config',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default_order' => 'boolean',
        'is_default_topup' => 'boolean',
        'supports_order' => 'boolean',
        'supports_topup' => 'boolean',
        'sandbox_mode' => 'boolean',
        'fee_value' => 'decimal:2',
        'sort_order' => 'integer',
        'config' => 'array',
        'secret_config' => 'encrypted:array',
    ];

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'gateway_code', 'code');
    }

    public function topups(): HasMany
    {
        return $this->hasMany(WalletTopup::class, 'gateway_code', 'code');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeSupportedFor(Builder $query, string $scope): Builder
    {
        return match ($scope) {
            'topup' => $query->where('supports_topup', true),
            default => $query->where('supports_order', true),
        };
    }

    public function resolvedConfig(): array
    {
        $public = is_array($this->config) ? $this->config : [];
        $secret = is_array($this->secret_config) ? $this->secret_config : [];

        return array_filter(
            array_merge($public, $secret),
            fn ($value) => $value !== null && $value !== ''
        );
    }
}