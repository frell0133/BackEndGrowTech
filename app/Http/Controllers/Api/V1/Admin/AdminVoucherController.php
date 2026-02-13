<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Voucher;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class AdminVoucherController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $q = $request->query('q');

        $data = Voucher::query()
            ->when($q, fn($qq) => $qq->where('code', 'ilike', "%{$q}%"))
            ->orderByDesc('id')
            ->paginate((int) $request->query('per_page', 10));

        return $this->ok($data);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'code' => ['nullable','string','max:50','unique:vouchers,code'],
            'type' => ['required','in:fixed,percent'],
            'value' => ['required','numeric','min:0'],
            'quota' => ['nullable','integer','min:1'],
            'min_purchase' => ['nullable','numeric','min:0'],
            'expires_at' => ['nullable','date'],
            'rules' => ['nullable','array'],
            'is_active' => ['nullable','boolean'],
        ]);

        // safety: percent max 100
        if (($data['type'] ?? null) === 'percent' && ($data['value'] ?? 0) > 100) {
            return $this->fail('Value percent maksimal 100', 422);
        }

        $voucher = Voucher::create([
            'code' => $data['code'] ?? null, // kalau null -> auto generate di model
            'type' => $data['type'],
            'value' => $data['value'],
            'quota' => $data['quota'] ?? null,
            'min_purchase' => $data['min_purchase'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'rules' => $data['rules'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return $this->ok($voucher);
    }

    public function show(string $id)
    {
        $voucher = Voucher::findOrFail($id);
        return $this->ok($voucher);
    }

    public function update(Request $request, string $id)
    {
        $voucher = Voucher::findOrFail($id);

        $data = $request->validate([
            'code' => ['nullable','string','max:50','unique:vouchers,code,' . $voucher->id],
            'type' => ['sometimes','in:fixed,percent'],
            'value' => ['sometimes','numeric','min:0'],
            'quota' => ['nullable','integer','min:1'],
            'min_purchase' => ['nullable','numeric','min:0'],
            'expires_at' => ['nullable','date'],
            'rules' => ['nullable','array'],
            'is_active' => ['nullable','boolean'],
        ]);

        if (($data['type'] ?? $voucher->type) === 'percent' && (($data['value'] ?? $voucher->value) > 100)) {
            return $this->fail('Value percent maksimal 100', 422);
        }

        $voucher->fill($data);
        $voucher->save();

        return $this->ok($voucher);
    }

    public function destroy(string $id)
    {
        $voucher = Voucher::findOrFail($id);

        // biasanya soft: disable biar aman (histori order tetap konsisten)
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
