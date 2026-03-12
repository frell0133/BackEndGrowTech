<?php

namespace App\Services\Payments;

use App\Models\PaymentGateway;
use App\Services\Payments\Contracts\PaymentGatewayDriver;
use App\Services\Payments\Drivers\DuitkuGatewayDriver;
use App\Services\Payments\Drivers\MidtransGatewayDriver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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

        return PaymentGateway::query()
            ->active()
            ->supportedFor($scope)
            ->orderBy($defaultField, 'desc')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();
    }

    public function resolveActiveByCodeOrAlias(string $value, string $scope): ?PaymentGateway
    {
        $value = Str::lower(trim($value));
        if ($value === '') {
            return null;
        }

        $exact = PaymentGateway::query()
            ->whereRaw('LOWER(code) = ?', [$value])
            ->active()
            ->supportedFor($scope)
            ->first();

        if ($exact) {
            return $exact;
        }

        return $this->aliasCandidates($value)
            ->active()
            ->supportedFor($scope)
            ->orderBy($scope === 'topup' ? 'is_default_topup' : 'is_default_order', 'desc')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();
    }

    public function resolveAnyByCodeOrAlias(string $value): ?PaymentGateway
    {
        $value = Str::lower(trim($value));
        if ($value === '') {
            return null;
        }

        $exact = $this->resolveByCode($value);
        if ($exact) {
            return $exact;
        }

        return $this->selectPreferredAliasMatch($this->aliasCandidates($value)->get());
    }

    public function resolveWebhookGateway(string $value): ?PaymentGateway
    {
        $value = Str::lower(trim($value));
        if ($value === '') {
            return null;
        }

        $exact = $this->resolveByCode($value);
        if ($exact) {
            return $exact;
        }

        $matches = $this->aliasCandidates($value)
            ->active()
            ->orderBy('is_default_order', 'desc')
            ->orderBy('is_default_topup', 'desc')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return $this->selectPreferredAliasMatch($matches);
    }

    protected function resolveByCode(string $value): ?PaymentGateway
    {
        return PaymentGateway::query()
            ->whereRaw('LOWER(code) = ?', [$value])
            ->orderBy('is_active', 'desc')
            ->orderBy('id')
            ->first();
    }

    protected function aliasCandidates(string $value): Builder
    {
        return PaymentGateway::query()->where(function (Builder $q) use ($value) {
            $q->whereRaw('LOWER(provider) = ?', [$value])
                ->orWhereRaw('LOWER(driver) = ?', [$value]);
        });
    }

    protected function selectPreferredAliasMatch(Collection $matches): ?PaymentGateway
    {
        if ($matches->isEmpty()) {
            return null;
        }

        return $matches->firstWhere('is_default_order', true)
            ?: $matches->firstWhere('is_default_topup', true)
            ?: $matches->firstWhere('is_active', true)
            ?: $matches->first();
    }
}
