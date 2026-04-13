<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Voucher;
use App\Support\ApiResponse;
use App\Support\UserTierEligibility;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class UserVoucherController extends Controller
{
    use ApiResponse;

    public function validateCode(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return $this->fail('Unauthenticated', 401);
        }

        $v = $request->validate([
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9]+(?:-[A-Z0-9]+)*$/'],
            'subtotal' => ['required', 'numeric', 'min:0'],
        ], [
            'code.regex' => 'Format kode voucher tidak valid. Gunakan huruf besar, angka, dan tanda hubung (-).',
        ]);

        $code = trim((string) $v['code']);
        $subtotal = (float) $v['subtotal'];
        $tierKey = UserTierEligibility::normalizeTier($user->tier ?? 'member');

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

        if (!UserTierEligibility::voucherAllowed($voucher, $tierKey)) {
            return $this->fail(UserTierEligibility::voucherMessage($voucher, $tierKey), 422, [
                'tier' => $tierKey,
                'rules' => UserTierEligibility::tierSummaryFromRules($voucher->rules ?? []),
            ]);
        }

        if ($voucher->min_purchase !== null && $subtotal < (float) $voucher->min_purchase) {
            return $this->fail('Subtotal belum memenuhi minimal pembelian voucher', 422, [
                'min_purchase' => (float) $voucher->min_purchase,
            ]);
        }

        if ($voucher->quota !== null) {
            $used = $voucher->orders()->count();
            if ($used >= (int) $voucher->quota) {
                return $this->fail('Kuota voucher sudah habis', 422, [
                    'quota' => (int) $voucher->quota,
                    'used' => $used,
                ]);
            }
        }

        $discount = 0.0;
        if ($voucher->type === 'percent') {
            $discount = floor($subtotal * ((float) $voucher->value / 100));
        } else {
            $discount = (float) $voucher->value;
        }

        if ($discount > $subtotal) {
            $discount = $subtotal;
        }

        return $this->ok([
            'valid' => true,
            'code' => $voucher->code,
            'type' => $voucher->type,
            'value' => (float) $voucher->value,
            'discount_amount' => (float) $discount,
            'subtotal' => (float) $subtotal,
            'grand_total' => (float) max(0, $subtotal - $discount),
            'min_purchase' => $voucher->min_purchase ? (float) $voucher->min_purchase : null,
            'expires_at' => $voucher->expires_at,
            'quota' => $voucher->quota ? (int) $voucher->quota : null,
            'tier_rules' => UserTierEligibility::tierSummaryFromRules($voucher->rules ?? []),
        ]);
    }
}