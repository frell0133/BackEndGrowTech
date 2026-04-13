<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Voucher;
use App\Support\ApiResponse;
use App\Support\UserTierEligibility;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminVoucherController extends Controller
{
    use ApiResponse;

    private function normalizeIncomingCode(Request $request): void
    {
        $request->merge([
            'code' => Voucher::normalizeCode($request->input('code')),
        ]);
    }

    private function voucherRules(?Voucher $voucher = null): array
    {
        return [
            'code' => [
                'nullable',
                'string',
                'max:50',
                'regex:/^[A-Z0-9]+(?:-[A-Z0-9]+)*$/',
                Rule::unique('vouchers', 'code')->ignore($voucher?->id),
            ],
            'type' => ['required_without:id', 'in:fixed,percent'],
            'value' => ['required_without:id', 'numeric', 'min:0'],
            'quota' => ['nullable', 'integer', 'min:1'],
            'min_purchase' => ['nullable', 'numeric', 'min:0'],
            'expires_at' => ['nullable', 'date'],
            'rules' => ['nullable', 'array'],
            'rules.allowed_tiers' => ['nullable', 'array'],
            'rules.allowed_tiers.*' => ['string', 'in:member,reseller,vip'],
            'rules.excluded_tiers' => ['nullable', 'array'],
            'rules.excluded_tiers.*' => ['string', 'in:member,reseller,vip'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    private function voucherMessages(): array
    {
        return [
            'code.regex' => 'Format kode voucher hanya boleh huruf besar, angka, dan tanda hubung (-).',
            'code.unique' => 'Kode voucher sudah digunakan. Gunakan kode yang unik.',
        ];
    }

    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        $data = Voucher::query()
            ->when($q !== '', fn ($qq) => $qq->where('code', 'ilike', "%{$q}%"))
            ->orderByDesc('id')
            ->paginate((int) $request->query('per_page', 10));

        $data->getCollection()->transform(function (Voucher $voucher) {
            $rules = UserTierEligibility::normalizeTierRules(is_array($voucher->rules) ? $voucher->rules : []);
            $voucher->setAttribute('rules', $rules);
            $voucher->setAttribute('tier_rules', $rules);
            return $voucher;
        });

        return $this->ok($data);
    }

    public function store(Request $request)
    {
        $this->normalizeIncomingCode($request);

        $data = $request->validate($this->voucherRules(), $this->voucherMessages());

        if (($data['type'] ?? null) === 'percent' && ($data['value'] ?? 0) > 100) {
            return $this->fail('Value percent maksimal 100', 422);
        }

        $rules = UserTierEligibility::normalizeTierRules(is_array($data['rules'] ?? null) ? $data['rules'] : []);
        $data['rules'] = $rules;

        $voucher = Voucher::create([
            'code' => $data['code'] ?? null,
            'type' => $data['type'],
            'value' => $data['value'],
            'quota' => $data['quota'] ?? null,
            'min_purchase' => $data['min_purchase'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'rules' => $data['rules'],
            'is_active' => $data['is_active'] ?? true,
        ]);

        $voucher->setAttribute('rules', $rules);
        $voucher->setAttribute('tier_rules', $rules);

        return $this->ok($voucher);
    }

    public function show(string $id)
    {
        $voucher = Voucher::findOrFail($id);
        $rules = UserTierEligibility::normalizeTierRules($voucher->rules ?? []);
        $voucher->setAttribute('rules', $rules);
        $voucher->setAttribute('tier_rules', $rules);

        return $this->ok($voucher);
    }

    public function update(Request $request, string $id)
    {
        $voucher = Voucher::findOrFail($id);

        $this->normalizeIncomingCode($request);

        $rules = $this->voucherRules($voucher);
        $rules['type'] = ['sometimes', 'in:fixed,percent'];
        $rules['value'] = ['sometimes', 'numeric', 'min:0'];

        $data = $request->validate($rules, $this->voucherMessages());

        if (($data['type'] ?? $voucher->type) === 'percent' && (($data['value'] ?? $voucher->value) > 100)) {
            return $this->fail('Value percent maksimal 100', 422);
        }

        if (array_key_exists('rules', $data)) {
            $data['rules'] = UserTierEligibility::normalizeTierRules(
                is_array($data['rules']) ? $data['rules'] : []
            );
        }

        $voucher->fill($data);
        $voucher->save();

        $normalizedRules = UserTierEligibility::normalizeTierRules($voucher->rules ?? []);
        $voucher->setAttribute('rules', $normalizedRules);
        $voucher->setAttribute('tier_rules', $normalizedRules);

        return $this->ok($voucher);
    }

    public function destroy(string $id)
    {
        $voucher = Voucher::findOrFail($id);
        $voucher->is_active = false;
        $voucher->save();

        return $this->ok(['message' => 'Voucher dinonaktifkan']);
    }

    public function usage(string $id)
    {
        $voucher = Voucher::withCount('orders')->findOrFail($id);
        $rules = UserTierEligibility::normalizeTierRules($voucher->rules ?? []);

        return $this->ok([
            'id' => $voucher->id,
            'code' => $voucher->code,
            'orders_count' => $voucher->orders_count,
            'tier_rules' => $rules,
        ]);
    }
}