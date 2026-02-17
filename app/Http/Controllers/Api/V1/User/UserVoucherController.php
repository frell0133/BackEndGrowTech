<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Voucher;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class UserVoucherController extends Controller
{
    use ApiResponse;

    /**
     * POST /api/v1/vouchers/validate
     * Body: { "code": "NATAL2026", "subtotal": 150000 }
     */
    public function validateCode(Request $request)
    {
        $v = $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'subtotal' => ['required', 'numeric', 'min:0'],
        ]);

        $code = strtoupper(trim($v['code']));
        $subtotal = (float) $v['subtotal'];

        $voucher = Voucher::query()->where('code', $code)->first();
        if (!$voucher) {
            return $this->fail('Voucher tidak ditemukan', 404);
        }

        if (!$voucher->is_active) {
            return $this->fail('Voucher tidak aktif', 422);
        }

        if ($voucher->expires_at && Carbon::parse($voucher->expires_at)->isPast()) {
            return $this->fail('Voucher sudah kedaluwarsa', 422);
        }

        if ($voucher->min_purchase !== null && $subtotal < (float) $voucher->min_purchase) {
            return $this->fail('Subtotal belum memenuhi minimal pembelian voucher', 422, [
                'min_purchase' => (float) $voucher->min_purchase,
            ]);
        }

        // quota optional: hitung dari pivot order_vouchers
        if ($voucher->quota !== null) {
            $used = $voucher->orders()->count();
            if ($used >= (int) $voucher->quota) {
                return $this->fail('Kuota voucher sudah habis', 422, [
                    'quota' => (int) $voucher->quota,
                    'used' => $used,
                ]);
            }
        }

        // preview diskon
        $discount = 0.0;
        if ($voucher->type === 'percent') {
            $discount = floor($subtotal * ((float) $voucher->value / 100));
        } else { // fixed
            $discount = (float) $voucher->value;
        }

        if ($discount > $subtotal) $discount = $subtotal;

        return $this->ok([
            'valid' => true,
            'code' => $voucher->code,
            'type' => $voucher->type, // fixed|percent
            'value' => (float) $voucher->value,
            'discount_amount' => (float) $discount,
            'subtotal' => (float) $subtotal,
            'grand_total' => (float) max(0, $subtotal - $discount),
            'min_purchase' => $voucher->min_purchase ? (float) $voucher->min_purchase : null,
            'expires_at' => $voucher->expires_at,
            'quota' => $voucher->quota ? (int) $voucher->quota : null,
        ]);
    }
}
