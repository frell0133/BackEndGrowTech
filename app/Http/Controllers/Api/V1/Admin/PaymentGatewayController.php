<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentGateway;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PaymentGatewayController extends Controller
{
    use ApiResponse;

    public function available(Request $request)
    {
        $scope = $request->query('scope', 'order');

        $rows = PaymentGateway::query()
            ->active()
            ->supportedFor($scope)
            ->orderBy($scope === 'topup' ? 'is_default_topup' : 'is_default_order', 'desc')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (PaymentGateway $gateway) => $this->serializeGateway($gateway, false));

        return $this->ok($rows);
    }

    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $scope = trim((string) $request->query('scope', ''));
        $perPage = max(1, min(100, (int) $request->query('per_page', 20)));

        $rows = PaymentGateway::query()
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($qq) use ($q) {
                    $qq->where('code', 'like', "%{$q}%")
                        ->orWhere('name', 'like', "%{$q}%")
                        ->orWhere('provider', 'like', "%{$q}%")
                        ->orWhere('driver', 'like', "%{$q}%");
                });
            })
            ->when($scope !== '', function ($query) use ($scope) {
                if ($scope === 'topup') {
                    $query->where('supports_topup', true);
                } elseif ($scope === 'order') {
                    $query->where('supports_order', true);
                }
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate($perPage);

        $rows->getCollection()->transform(fn (PaymentGateway $gateway) => $this->serializeGateway($gateway, false));

        return $this->ok($rows);
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->rules('store'));
        $payload = $this->normalizePayload($validated);

        $gateway = PaymentGateway::create($payload);

        $this->syncDefaultFlags($gateway);
        $this->ensureDefaultForScope('order');
        $this->ensureDefaultForScope('topup');

        return $this->ok($this->serializeGateway($gateway->fresh(), true));
    }

    public function show(string $code)
    {
        $gateway = $this->findByCode($code);

        if (!$gateway) {
            return $this->fail('Payment gateway tidak ditemukan', 404);
        }

        return $this->ok($this->serializeGateway($gateway, true));
    }

    public function update(Request $request, string $code)
    {
        $gateway = $this->findByCode($code);

        if (!$gateway) {
            return $this->fail('Payment gateway tidak ditemukan', 404);
        }

        $validated = $request->validate($this->rules('update', $gateway->id));
        $payload = $this->normalizePayload($validated, $gateway);

        $gateway->fill($payload);
        $gateway->save();

        $this->syncDefaultFlags($gateway);
        $this->ensureDefaultForScope('order');
        $this->ensureDefaultForScope('topup');

        return $this->ok($this->serializeGateway($gateway->fresh(), true));
    }

    public function destroy(string $code)
    {
        $gateway = $this->findByCode($code);

        if (!$gateway) {
            return $this->fail('Payment gateway tidak ditemukan', 404);
        }

        if ($gateway->payments()->exists() || $gateway->topups()->exists()) {
            return $this->fail('Gateway sudah pernah dipakai transaksi, tidak boleh dihapus', 422);
        }

        $gateway->delete();

        $this->ensureDefaultForScope('order');
        $this->ensureDefaultForScope('topup');

        return $this->ok([
            'deleted' => true,
            'code' => $code,
        ]);
    }

    protected function rules(string $mode = 'store', ?int $ignoreId = null): array
    {
        $required = $mode === 'store' ? ['required'] : ['sometimes'];
        $optionalBool = $mode === 'store' ? ['nullable', 'boolean'] : ['sometimes', 'boolean'];
        $optionalArray = $mode === 'store' ? ['nullable', 'array'] : ['sometimes', 'array'];

        return [
            'code' => array_merge(
                $mode === 'store' ? ['nullable'] : ['sometimes'],
                ['string', 'max:100', Rule::unique('payment_gateways', 'code')->ignore($ignoreId)]
            ),
            'name' => array_merge($required, ['string', 'max:120']),
            'provider' => array_merge($required, ['string', 'max:100']),
            'driver' => array_merge($required, ['string', 'max:100']),
            'description' => $mode === 'store' ? ['nullable', 'string'] : ['sometimes', 'nullable', 'string'],
            'is_active' => $optionalBool,
            'is_default_order' => $optionalBool,
            'is_default_topup' => $optionalBool,
            'supports_order' => $optionalBool,
            'supports_topup' => $optionalBool,
            'sandbox_mode' => $optionalBool,
            'fee_type' => $mode === 'store'
                ? ['nullable', Rule::in(['percent', 'fixed'])]
                : ['sometimes', 'nullable', Rule::in(['percent', 'fixed'])],
            'fee_value' => $mode === 'store'
                ? ['nullable', 'numeric', 'min:0']
                : ['sometimes', 'nullable', 'numeric', 'min:0'],
            'sort_order' => $mode === 'store'
                ? ['nullable', 'integer']
                : ['sometimes', 'integer'],
            'config' => $optionalArray,
            'secret_config' => $optionalArray,
        ];
    }

    protected function normalizePayload(array $data, ?PaymentGateway $existing = null): array
    {
        $name = trim((string) ($data['name'] ?? $existing?->name ?? 'Gateway'));
        $provider = Str::lower(trim((string) ($data['provider'] ?? $existing?->provider ?? 'custom')));
        $driver = Str::lower(trim((string) ($data['driver'] ?? $existing?->driver ?? $provider)));
        $code = Str::lower(trim((string) ($data['code'] ?? $existing?->code ?? Str::slug($name, '-'))));

        if ($code === '') {
            $code = Str::lower(Str::slug($provider . '-' . $name, '-'));
        }

        $payload = [
            'code' => $code,
            'name' => $name,
            'provider' => $provider,
            'driver' => $driver,
            'description' => $data['description'] ?? $existing?->description,
            'is_active' => (bool) ($data['is_active'] ?? $existing?->is_active ?? false),
            'supports_order' => (bool) ($data['supports_order'] ?? $existing?->supports_order ?? true),
            'supports_topup' => (bool) ($data['supports_topup'] ?? $existing?->supports_topup ?? true),
            'sandbox_mode' => (bool) ($data['sandbox_mode'] ?? $existing?->sandbox_mode ?? true),
            'fee_type' => $data['fee_type'] ?? $existing?->fee_type,
            'fee_value' => (float) ($data['fee_value'] ?? $existing?->fee_value ?? 0),
            'sort_order' => (int) ($data['sort_order'] ?? $existing?->sort_order ?? 0),
            'config' => $data['config'] ?? ($existing?->config ?? []),
            'secret_config' => $data['secret_config'] ?? ($existing?->secret_config ?? []),
            'is_default_order' => (bool) ($data['is_default_order'] ?? $existing?->is_default_order ?? false),
            'is_default_topup' => (bool) ($data['is_default_topup'] ?? $existing?->is_default_topup ?? false),
        ];

        if (!$payload['is_active']) {
            $payload['is_default_order'] = false;
            $payload['is_default_topup'] = false;
        }

        if (!$payload['supports_order']) {
            $payload['is_default_order'] = false;
        }

        if (!$payload['supports_topup']) {
            $payload['is_default_topup'] = false;
        }

        return $payload;
    }

    protected function serializeGateway(PaymentGateway $gateway, bool $withSecret): array
    {
        $data = [
            'id' => $gateway->id,
            'code' => $gateway->code,
            'name' => $gateway->name,
            'provider' => $gateway->provider,
            'driver' => $gateway->driver,
            'description' => $gateway->description,
            'is_active' => (bool) $gateway->is_active,
            'is_default_order' => (bool) $gateway->is_default_order,
            'is_default_topup' => (bool) $gateway->is_default_topup,
            'supports_order' => (bool) $gateway->supports_order,
            'supports_topup' => (bool) $gateway->supports_topup,
            'sandbox_mode' => (bool) $gateway->sandbox_mode,
            'fee_type' => $gateway->fee_type,
            'fee_value' => (float) $gateway->fee_value,
            'sort_order' => (int) $gateway->sort_order,
            'config' => $gateway->config ?? [],
            'created_at' => $gateway->created_at,
            'updated_at' => $gateway->updated_at,
        ];

        if ($withSecret) {
            $data['secret_config'] = $gateway->secret_config ?? [];
        }

        return $data;
    }

    protected function syncDefaultFlags(PaymentGateway $gateway): void
    {
        if ($gateway->is_default_order) {
            PaymentGateway::query()
                ->where('id', '!=', $gateway->id)
                ->update(['is_default_order' => false]);
        }

        if ($gateway->is_default_topup) {
            PaymentGateway::query()
                ->where('id', '!=', $gateway->id)
                ->update(['is_default_topup' => false]);
        }
    }

    protected function ensureDefaultForScope(string $scope): void
    {
        $defaultField = $scope === 'topup' ? 'is_default_topup' : 'is_default_order';

        $exists = PaymentGateway::query()
            ->active()
            ->supportedFor($scope)
            ->where($defaultField, true)
            ->exists();

        if ($exists) {
            return;
        }

        $first = PaymentGateway::query()
            ->active()
            ->supportedFor($scope)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        if ($first) {
            $first->{$defaultField} = true;
            $first->save();
        }
    }

    protected function findByCode(string $code): ?PaymentGateway
    {
        return PaymentGateway::query()
            ->whereRaw('LOWER(code) = ?', [Str::lower($code)])
            ->first();
    }
}