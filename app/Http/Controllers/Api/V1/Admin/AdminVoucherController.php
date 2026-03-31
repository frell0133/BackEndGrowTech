<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Voucher;
use App\Support\ApiResponse;
use App\Support\UserTierEligibility;
use Illuminate\Http\Request;

class AdminVoucherController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        $data = Voucher::query()
            ->when($q !== '', fn ($qq) => $qq->where('code', 'ilike', "%{$q}%"))
            ->orderByDesc('id')
            ->paginate((int) $request->query('per_page', 10));

        $data->getCollection()->transform(function (Voucher $voucher) {
            $rules = UserTierEligibility::tierSummaryFromRules(is_array($voucher->rules) ? $voucher->rules : []);
            $voucher->setAttribute('tier_rules', $rules);
            return $voucher;
        });

        return $this->ok($data);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:50', 'unique:vouchers,code'],
            'type' => ['required', 'in:fixed,percent'],
            'value' => ['required', 'numeric', 'min:0'],
            'quota' => ['nullable', 'integer', 'min:1'],
            'min_purchase' => ['nullable', 'numeric', 'min:0'],
            'expires_at' => ['nullable', 'date'],
            'rules' => ['nullable', 'array'],
            'rules.allowed_tiers' => ['nullable', 'array'],
            'rules.allowed_tiers.*' => ['string', 'in:member,reseller,vip'],
            'rules.excluded_tiers' => ['nullable', 'array'],
            'rules.excluded_tiers.*' => ['string', 'in:member,reseller,vip'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if (($data['type'] ?? null) === 'percent' && ($data['value'] ?? 0) > 100) {
            return $this->fail('Value percent maksimal 100', 422);
        }

        $rules = is_array($data['rules'] ?? null) ? $data['rules'] : [];
        $data['rules'] = array_merge($rules, UserTierEligibility::tierSummaryFromRules($rules));

        $voucher = Voucher::create([
            'code' => $data['code'] ?? null,
            'type' => $data['type'],
            'value' => $data['value'],
            'quota' => $data['quota'] ?? null,
            'min_purchase' => $data['min_purchase'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'rules' => $data['rules'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);

        $voucher->setAttribute('tier_rules', UserTierEligibility::tierSummaryFromRules($voucher->rules ?? []));

        return $this->ok($voucher);
    }

    public function show(string $id)
    {
        $voucher = Voucher::findOrFail($id);
        $voucher->setAttribute('tier_rules', UserTierEligibility::tierSummaryFromRules($voucher->rules ?? []));
        return $this->ok($voucher);
    }

    public function update(Request $request, string $id)
    {
        $voucher = Voucher::findOrFail($id);

        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:50', 'unique:vouchers,code,' . $voucher->id],
            'type' => ['sometimes', 'in:fixed,percent'],
            'value' => ['sometimes', 'numeric', 'min:0'],
            'quota' => ['nullable', 'integer', 'min:1'],
            'min_purchase' => ['nullable', 'numeric', 'min:0'],
            'expires_at' => ['nullable', 'date'],
            'rules' => ['nullable', 'array'],
            'rules.allowed_tiers' => ['nullable', 'array'],
            'rules.allowed_tiers.*' => ['string', 'in:member,reseller,vip'],
            'rules.excluded_tiers' => ['nullable', 'array'],
            'rules.excluded_tiers.*' => ['string', 'in:member,reseller,vip'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if (($data['type'] ?? $voucher->type) === 'percent' && (($data['value'] ?? $voucher->value) > 100)) {
            return $this->fail('Value percent maksimal 100', 422);
        }

        if (array_key_exists('rules', $data)) {
            $rules = is_array($data['rules']) ? $data['rules'] : [];
            $data['rules'] = array_merge($rules, UserTierEligibility::tierSummaryFromRules($rules));
        }

        $voucher->fill($data);
        $voucher->save();
        $voucher->setAttribute('tier_rules', UserTierEligibility::tierSummaryFromRules($voucher->rules ?? []));

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

        return $this->ok([
            'id' => $voucher->id,
            'code' => $voucher->code,
            'orders_count' => $voucher->orders_count,
        ]);
    }
}
