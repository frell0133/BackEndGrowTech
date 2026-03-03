<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Referral;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

use App\Models\ReferralSetting;
use App\Models\ReferralTransaction;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;

class UserReferralController extends Controller
{
    use ApiResponse;

    public function dashboard(Request $request)
    {
        $user = $request->user();
        if (!$user) return $this->fail('Unauthenticated', 401);

        $ref = Referral::query()
            ->with(['referrer:id,name,email,referral_code'])
            ->where('user_id', $user->id)
            ->first();

        return $this->ok([
            'my_referral_code' => $user->referral_code,
            'relation' => $ref,
        ]);
    }

    public function attach(Request $request)
    {
        $user = $request->user();
        if (!$user) return $this->fail('Unauthenticated', 401);

        $data = $request->validate([
            'code' => ['required','string','max:50'],
        ]);

        $code = strtoupper(trim($data['code']));

        $referrer = User::query()
            ->where('referral_code', $code)
            ->first();

        if (!$referrer) return $this->fail('Referral code tidak valid', 422);
        if ($referrer->id === $user->id) return $this->fail('Tidak bisa pakai referral code sendiri', 422);

        return DB::transaction(function () use ($user, $referrer) {

            $existing = Referral::query()->where('user_id', $user->id)->lockForUpdate()->first();
            if ($existing && $existing->locked_at) {
                return $this->fail('Referral sudah terkunci (hanya bisa sekali)', 409);
            }

            $ref = Referral::updateOrCreate(
                ['user_id' => $user->id],
                ['referred_by' => $referrer->id, 'locked_at' => now()]
            );

            return $this->ok([
                'message' => 'Referral berhasil dipasang',
                'referral' => $ref,
                'referrer' => $referrer->only('id','name','email','referral_code'),
            ]);
        });
    }

    // ==============================
    // ✅ Opsi A helpers: subtotal dari cart (server-side)
    // ==============================

    private function cartForUser(int $userId): Cart
    {
        return Cart::firstOrCreate(['user_id' => $userId]);
    }

    private function resolveUnitPrice(Product $product, string $tierKey): int
    {
        $tier = (array) ($product->tier_pricing ?? []);
        $unitPrice = 0;

        if (!empty($tier)) {
            $unitPrice = (int) ($tier[$tierKey] ?? 0);
            if ($unitPrice <= 0) $unitPrice = (int) ($tier['member'] ?? 0);

            if ($unitPrice <= 0) {
                $vals = array_values($tier);
                $unitPrice = (int) ($vals[0] ?? 0);
            }
        }

        if ($unitPrice <= 0) {
            $unitPrice = (int) ($product->price ?? 0);
        }

        return (int) $unitPrice;
    }

    private function computeCartSubtotal(User $user): int
    {
        $cart = $this->cartForUser((int) $user->id);
        $tierKey = (string) ($user->tier ?? 'member');

        $items = CartItem::query()
            ->where('cart_id', $cart->id)
            ->with(['product'])
            ->get();

        $subtotal = 0;

        foreach ($items as $item) {
            $p = $item->product;
            if (!$p) continue;
            if (!$p->is_active || !$p->is_published) continue;

            $qty = (int) ($item->qty ?? 1);
            $unit = $this->resolveUnitPrice($p, $tierKey);
            $subtotal += ($unit * $qty);
        }

        return (int) max(0, $subtotal);
    }

    public function previewDiscount(Request $request)
    {
        $user = $request->user();
        if (!$user) return $this->fail('Unauthenticated', 401);

        // ✅ amount sekarang OPTIONAL (kalau kosong -> ambil dari cart)
        $data = $request->validate([
            'amount' => ['nullable','integer','min:0'],
        ]);

        $amount = isset($data['amount']) ? (int) $data['amount'] : null;

        // ✅ Opsi A: default hitung subtotal dari cart server-side
        $source = 'request';
        if ($amount === null) {
            $amount = $this->computeCartSubtotal($user);
            $source = 'cart';
        }

        $settings = ReferralSetting::current();
        if (!$settings->isActiveNow()) {
            return $this->ok([
                'eligible' => false,
                'reason' => 'Referral campaign tidak aktif / sudah expired',
                'amount' => $amount,
                'amount_source' => $source, // ✅ info source
                'discount_amount' => 0,
                'final_amount' => $amount,
                'settings' => $settings,
            ]);
        }

        $relation = Referral::query()
            ->where('user_id', $user->id)
            ->first();

        if (!$relation || !$relation->locked_at) {
            return $this->ok([
                'eligible' => false,
                'reason' => 'User belum attach referral code',
                'amount' => $amount,
                'amount_source' => $source,
                'discount_amount' => 0,
                'final_amount' => $amount,
                'settings' => $settings,
            ]);
        }

        if ($amount < (int) $settings->min_order_amount) {
            return $this->ok([
                'eligible' => false,
                'reason' => 'Minimal order belum terpenuhi',
                'amount' => $amount,
                'amount_source' => $source,
                'discount_amount' => 0,
                'final_amount' => $amount,
                'settings' => $settings,
            ]);
        }

        $usedByUser = ReferralTransaction::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['pending','valid'])
            ->count();

        if ($settings->max_uses_per_user > 0 && $usedByUser >= (int) $settings->max_uses_per_user) {
            return $this->ok([
                'eligible' => false,
                'reason' => 'Limit penggunaan referral untuk user sudah habis',
                'amount' => $amount,
                'amount_source' => $source,
                'discount_amount' => 0,
                'final_amount' => $amount,
                'settings' => $settings,
            ]);
        }

        $discount = 0;

        if ($settings->discount_type === 'percent') {
            $discount = (int) floor($amount * ((int) $settings->discount_value) / 100);
        } else {
            $discount = (int) $settings->discount_value;
        }

        if ((int) $settings->discount_max_amount > 0) {
            $discount = min($discount, (int) $settings->discount_max_amount);
        }

        $discount = min($discount, $amount);

        return $this->ok([
            'eligible' => true,
            'reason' => null,
            'amount' => $amount,
            'amount_source' => $source,
            'discount_amount' => $discount,
            'final_amount' => max(0, $amount - $discount),
            'referrer_id' => (int) $relation->referred_by,
            'settings' => $settings,
        ]);
    }
}