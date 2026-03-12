<?php

namespace App\Services\Payments;

use App\Models\PaymentGateway;
use App\Services\Payments\Contracts\PaymentGatewayDriver;
use App\Services\Payments\Drivers\DuitkuGatewayDriver;
use App\Services\Payments\Drivers\MidtransGatewayDriver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentGatewayManager
{
    public function driverFor(PaymentGateway $gateway): PaymentGatewayDriver
    {
        $driver = Str::lower((string) ($gateway->driver ?: $gateway->provider ?: $gateway->code));

        return match ($driver) {
            'midtrans' => new MidtransGatewayDriver(),
            'duitku' => new DuitkuGatewayDriver(),
            default => throw ValidationException::withMessages([
                'gateway_code' => "Driver gateway [{$driver}] belum diimplementasikan di backend.",
            ]),
        };
    }

    public function defaultForScope(string $scope): ?PaymentGateway
    {
        $defaultField = $scope === 'topup' ? 'is_default_topup' : 'is_default_order';

        $base = PaymentGateway::query()
            ->active()
            ->supportedFor($scope)
            ->orderBy($defaultField, 'desc')
            ->orderBy('sort_order')
            ->orderBy('id');

        return $base->first();
    }

    public function resolveActiveByCodeOrAlias(string $value, string $scope): ?PaymentGateway
    {
        return $this->baseAliasQuery($value)
            ->active()
            ->supportedFor($scope)
            ->orderBy($scope === 'topup' ? 'is_default_topup' : 'is_default_order', 'desc')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();
    }

    public function resolveAnyByCodeOrAlias(string $value): ?PaymentGateway
    {
        return $this->baseAliasQuery($value)
            ->orderBy('is_active', 'desc')
            ->orderBy('is_default_order', 'desc')
            ->orderBy('is_default_topup', 'desc')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();
    }

    protected function baseAliasQuery(string $value): Builder
    {
        $value = Str::lower(trim($value));

        return PaymentGateway::query()->where(function (Builder $q) use ($value) {
            $q->whereRaw('LOWER(code) = ?', [$value])
                ->orWhereRaw('LOWER(provider) = ?', [$value])
                ->orWhereRaw('LOWER(driver) = ?', [$value]);
        });
    }
}