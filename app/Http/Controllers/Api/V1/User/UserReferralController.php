<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Referral;
use App\Models\ReferralSetting;
use App\Models\ReferralTransaction;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\UserTierEligibility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\ReferralUsageService;

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
            'tier_eligible' => UserTierEligibility::isReferralTierAllowed($user->tier ?? 'member'),
            'tier_message' => UserTierEligibility::isReferralTierAllowed($user->tier ?? 'member')
                ? null
                : UserTierEligibility::referralTierMessage($user->tier ?? 'member'),
        ]);
    }

    public function attach(Request $request)
    {
        $user = $request->user();
        if (!$user) return $this->fail('Unauthenticated', 401);

        if (!UserTierEligibility::isReferralTierAllowed($user->tier ?? 'member')) {
            return $this->fail(UserTierEligibility::referralTierMessage($user->tier ?? 'member'), 422, [
                'tier' => UserTierEligibility::normalizeTier($user->tier ?? 'member'),
            ]);
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'max:50'],
        ]);

        $code = User::normalizeReferralCode($data['code']);

        $referrer = User::query()
            ->where('referral_code', $code)
            ->first();

        if (!$referrer) return $this->fail('Referral code tidak valid', 422);
        if ($referrer->id === $user->id) return $this->fail('Tidak bisa pakai referral code sendiri', 422);

        return DB::transaction(function () use ($user, $referrer) {
            $existing = Referral::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($existing && $existing->locked_at) {
                return $this->fail('Referral sudah terkunci (hanya bisa sekali)', 409);
            }

            $ref = Referral::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'referred_by' => $referrer->id,
                    'locked_at' => now(),
                ]
            );

            return $this->ok([
                'message' => 'Referral berhasil dipasang',
                'referral' => $ref,
                'referrer' => $referrer->only('id', 'name', 'email', 'referral_code'),
            ]);
        });
    }

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

    public function previewDiscount(Request $request, ReferralUsageService $referralUsage)
    {
        $user = $request->user();
        if (!$user) return $this->fail('Unauthenticated', 401);

        $tierKey = UserTierEligibility::normalizeTier($user->tier ?? 'member');
        if (!UserTierEligibility::isReferralTierAllowed($tierKey)) {
            $amount = (int) ($request->input('amount') ?? $this->computeCartSubtotal($user));
            return $this->ok([
                'eligible' => false,
                'reason' => UserTierEligibility::referralTierMessage($tierKey),
                'amount' => $amount,
                'amount_source' => $request->has('amount') ? 'request' : 'cart',
                'discount_amount' => 0,
                'final_amount' => $amount,
                'settings' => ReferralSetting::current(),
            ]);
        }

        $data = $request->validate([
            'amount' => ['nullable', 'integer', 'min:0'],
        ]);

        $amount = array_key_exists('amount', $data) ? (int) $data['amount'] : null;
        $amountSource = 'request';
        if ($amount === null) {
            $amount = $this->computeCartSubtotal($user);
            $amountSource = 'cart';
        }

        $settings = ReferralSetting::current();
        if (!$settings || !$settings->isActiveNow()) {
            return $this->ok([
                'eligible' => false,
                'reason' => 'Referral campaign tidak aktif / sudah expired',
                'amount' => $amount,
                'amount_source' => $amountSource,
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
                'amount_source' => $amountSource,
                'discount_amount' => 0,
                'final_amount' => $amount,
                'settings' => $settings,
            ]);
        }

        if ($amount <= 0) {
            return $this->ok([
                'eligible' => false,
                'reason' => 'Cart masih kosong',
                'amount' => $amount,
                'amount_source' => $amountSource,
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
                'amount_source' => $amountSource,
                'discount_amount' => 0,
                'final_amount' => $amount,
                'settings' => $settings,
            ]);
        }

        $usedByUser = $referralUsage->countConsumableUsesForUser((int) $user->id);

        if ((int) $settings->max_uses_per_user > 0 && $usedByUser >= (int) $settings->max_uses_per_user) {
            return $this->ok([
                'eligible' => false,
                'reason' => 'Limit penggunaan referral untuk user sudah habis',
                'amount' => $amount,
                'amount_source' => $amountSource,
                'discount_amount' => 0,
                'final_amount' => $amount,
                'settings' => $settings,
            ]);
        }

        $discount = 0;
        if (($settings->discount_type ?? 'percent') === 'percent') {
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
            'amount_source' => $amountSource,
            'discount_amount' => (int) $discount,
            'final_amount' => (int) max(0, $amount - $discount),
            'referrer_id' => (int) $relation->referred_by,
            'settings' => $settings,
        ]);
    }

    public function history(Request $request)
    {
        $user = $request->user();
        if (!$user) return $this->fail('Unauthenticated', 401);

        $perPage = (int) $request->query('per_page', 20);
        $status = $request->query('status');
        $q = trim((string) $request->query('q', ''));
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');

        $tx = ReferralTransaction::query()
            ->where('referrer_id', $user->id)
            ->with([
                'user:id,name,email,referral_code',
                'order:id,invoice_number,amount,discount_total,created_at',
            ]);

        if ($status) $tx->where('status', $status);
        if ($dateFrom) $tx->whereDate('created_at', '>=', $dateFrom);
        if ($dateTo) $tx->whereDate('created_at', '<=', $dateTo);

        if ($q !== '') {
            $tx->whereHas('user', function ($u) use ($q) {
                $u->where('name', 'ilike', "%{$q}%")
                    ->orWhere('email', 'ilike', "%{$q}%")
                    ->orWhere('referral_code', 'ilike', "%{$q}%");
            });
        }

        $items = $tx->latest('id')->paginate($perPage);
        $itemsArr = $items->toArray();
        $itemsArr['data'] = collect($items->items())->map(function ($row) {
            $tanggal = optional($row->occurred_at ?: $row->created_at)->toDateString();

            return [
                'buyer' => [
                    'id' => (int) $row->user_id,
                    'name' => $row->user?->name,
                    'email' => $row->user?->email,
                ],
                'order' => [
                    'id' => (int) $row->order_id,
                    'invoice_number' => $row->order?->invoice_number,
                    'amount' => (int) ($row->order?->amount ?? 0),
                    'discount_total' => (int) ($row->order?->discount_total ?? 0),
                ],
                'status' => $row->status,
                'discount_amount' => (int) $row->discount_amount,
                'commission_amount' => (int) $row->commission_amount,
                'tanggal' => $tanggal,
            ];
        })->values();

        return $this->ok([
            'summary' => [
                'total' => (int) $items->total(),
            ],
            'items' => $itemsArr,
        ]);
    }

    public function usage(Request $request)
    {
        $user = $request->user();
        if (!$user) return $this->fail('Unauthenticated', 401);

        $summary = ReferralTransaction::query()
            ->where('referrer_id', $user->id)
            ->select([
                DB::raw('COUNT(*)::int as total_orders_used'),
                DB::raw('COUNT(DISTINCT user_id)::int as total_users_used'),
                DB::raw("SUM(CASE WHEN status='valid' THEN 1 ELSE 0 END)::int as valid"),
                DB::raw("SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END)::int as pending"),
                DB::raw("SUM(CASE WHEN status='invalid' THEN 1 ELSE 0 END)::int as invalid"),
                DB::raw('COALESCE(SUM(commission_amount),0)::int as total_komisi'),
                DB::raw('COALESCE(SUM(discount_amount),0)::int as total_discount'),
            ])->first();

        return $this->ok([
            'my_referral_code' => $user->referral_code,
            'total_users_used' => (int) ($summary->total_users_used ?? 0),
            'total_orders_used' => (int) ($summary->total_orders_used ?? 0),
            'valid' => (int) ($summary->valid ?? 0),
            'pending' => (int) ($summary->pending ?? 0),
            'invalid' => (int) ($summary->invalid ?? 0),
            'total_komisi' => (int) ($summary->total_komisi ?? 0),
            'total_discount' => (int) ($summary->total_discount ?? 0),
        ]);
    }
}
