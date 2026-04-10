<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\License;
use App\Models\Product;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Voucher;
use App\Models\Setting;
use App\Enums\OrderStatus;
use App\Support\ApiResponse;
use App\Support\PublicCache;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Services\DiscountCampaignService;
use App\Models\ReferralSetting;
use App\Models\ReferralTransaction;
use App\Models\Referral;
use App\Services\ProductAvailabilityService;
use App\Services\ReferralCommissionService;

class UserCartController extends Controller
{
    use ApiResponse;

    private function requireUser(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return $this->fail('Unauthenticated', 401);
        }
        return $user;
    }

    private function cartForUser(int $userId): Cart
    {
        return Cart::firstOrCreate(['user_id' => $userId]);
    }

    private function decodeSettingValue(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    private function getPaymentSettings(): array
    {
        return PublicCache::rememberContent('cart:payment-settings', 60, function () {
            $rows = Setting::query()
                ->where('group', 'payment')
                ->whereIn('key', ['tax_percent', 'fee_percent'])
                ->get(['key', 'value'])
                ->keyBy('key');

            $taxValue = $this->decodeSettingValue($rows->get('tax_percent')?->value);
            $feeValue = $this->decodeSettingValue($rows->get('fee_percent')?->value);

            $taxPercent = 0;
            if (is_array($taxValue)) {
                $taxPercent = (int) ($taxValue['percent'] ?? 0);
            } elseif (is_numeric($taxValue)) {
                $taxPercent = (int) $taxValue;
            }

            $feePercent = 0.0;
            if (is_array($feeValue)) {
                $rawFee = $feeValue['percent'] ?? $feeValue['value'] ?? 0;
                $feePercent = is_numeric($rawFee) ? (float) $rawFee : 0.0;
            } elseif (is_numeric($feeValue)) {
                $feePercent = (float) $feeValue;
            }

            return [
                'tax_percent' => $taxPercent,
                'gateway_fee_percent' => $feePercent,
            ];
        });
    }

    /**
     * Ambil harga unit berdasarkan tier user (member/reseller/vip).
     */
    private function buildCartResponseData($user, ?Cart $cart = null): array
    {
        $cart = $cart ?: $this->cartForUser((int) $user->id);

        $tierKey = (string) ($user->tier ?? 'member');
        $paymentSettings = $this->getPaymentSettings();
        $taxPercent = (int) ($paymentSettings['tax_percent'] ?? 0);
        $feePercent = (float) ($paymentSettings['gateway_fee_percent'] ?? 0.0);

        $cartItems = CartItem::query()
            ->where('cart_id', $cart->id)
            ->with([
                'product:id,category_id,subcategory_id,name,slug,type,description,tier_pricing,duration_days,price,is_active,is_published,rating,rating_count,purchases_count,popularity_score',
                'product.category:id,name,slug',
                'product.subcategory:id,category_id,name,description,slug,provider,image_url,image_path',
            ])
            ->get();

        $stockMap = $this->getAvailableStockMap(
            $cartItems->pluck('product_id')->filter()->unique()->map(fn ($id) => (int) $id)->values()->all()
        );

        $items = $cartItems->map(function (CartItem $item) use ($tierKey, $stockMap) {
            $product = $item->product;
            $stock = (int) ($stockMap[(int) $item->product_id] ?? 0);
            $qty = (int) ($item->qty ?? 1);

            $unitPrice = $product ? $this->resolveUnitPrice($product, $tierKey) : 0;
            $canBuy = (bool) ($product?->is_active && $product?->is_published && $stock > 0);

            return [
                'id' => (int) $item->id,
                'product_id' => (int) $item->product_id,
                'qty' => $qty,
                'unit_price' => (int) $unitPrice,
                'line_subtotal' => (int) $unitPrice * $qty,
                'product' => $product,
                'stock_available' => $stock,
                'can_buy' => $canBuy,
            ];
        })->values();

        $subtotal = (float) $items->sum('line_subtotal');
        $discountTotal = 0.0;
        $taxAmount = $taxPercent > 0 ? round($subtotal * ($taxPercent / 100), 2) : 0.0;
        $total = (float) max(0, ($subtotal + $taxAmount) - $discountTotal);
        $gatewayFeeAmount = $feePercent > 0 ? round($total * ($feePercent / 100), 2) : 0.0;
        $cartCount = (int) $items->sum(fn ($item) => (int) ($item['qty'] ?? 0));

        return [
            'items' => $items,
            'count' => $cartCount,
            'summary' => [
                'subtotal' => $subtotal,
                'tier' => $tierKey,
                'discount_total' => $discountTotal,
                'tax_percent' => $taxPercent,
                'tax_amount' => $taxAmount,
                'total' => $total,
                'gateway_fee_percent' => (float) $feePercent,
                'gateway_fee_amount' => (float) $gatewayFeeAmount,
                'total_payable_gateway' => (float) ($total + $gatewayFeeAmount),
            ],
        ];
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

    private function buildCheckoutSuccessPayload(Order $order, float $campaignDiscountTotal, float $voucherDiscount, float $referralDiscount, float $discountTotal, array $appliedCampaigns, float $feePercent, float $gatewayFeeAmount)
    {
        return $this->ok([
            'order' => $order->fresh()->load(['items.product', 'vouchers', 'discountCampaigns']),
            'discount_breakdown' => [
                'campaign_discount_total' => (float) $campaignDiscountTotal,
                'voucher_discount_total' => (float) $voucherDiscount,
                'referral_discount_total' => (float) $referralDiscount,
                'discount_total' => (float) $discountTotal,
                'applied_campaigns' => $appliedCampaigns,
            ],
            'payment_gateway_summary' => [
                'fee_percent' => (float) $feePercent,
                'fee_amount' => (float) $gatewayFeeAmount,
                'total_payable' => (float) ($order->amount + $gatewayFeeAmount),
            ],
            'summary' => [
                'subtotal' => (float) $order->subtotal,
                'discount_total' => (float) $order->discount_total,
                'tax_percent' => (int) $order->tax_percent,
                'tax_amount' => (float) $order->tax_amount,
                'total' => (float) $order->amount,
                'gateway_fee_percent' => (float) $feePercent,
                'gateway_fee_amount' => (float) $gatewayFeeAmount,
                'total_payable_gateway' => (float) ($order->amount + $gatewayFeeAmount),
            ],
            'items' => $order->fresh()->items()->with('product')->get(),
        ]);
    }

    private function computeReferralDiscountForUser(int $userId, float $subtotal): array
    {
        $settings = ReferralSetting::current();
        if (!$settings || !$settings->isActiveNow()) {
            return ['discount' => 0.0, 'referrer_id' => null];
        }

        $relation = Referral::query()
            ->where('user_id', $userId)
            ->first();

        if (!$relation || !$relation->locked_at) {
            return ['discount' => 0.0, 'referrer_id' => null];
        }

        if ((int)$settings->min_order_amount > 0 && (float)$subtotal < (float)$settings->min_order_amount) {
            return ['discount' => 0.0, 'referrer_id' => null];
        }

        $usage = app(ReferralCommissionService::class)->getUsageSummary($userId, (int) $relation->referred_by);

        if ((bool) ($usage['limit_reached'] ?? false)) {
            return ['discount' => 0.0, 'referrer_id' => null];
        }

        $referralDiscount = 0.0;
        if (($settings->discount_type ?? 'percent') === 'fixed') {
            $referralDiscount = (float) ((int)$settings->discount_value);
        } else {
            $referralDiscount = (float) floor((float)$subtotal * ((int)$settings->discount_value) / 100);
        }

        if ((int)$settings->discount_max_amount > 0) {
            $referralDiscount = min($referralDiscount, (float)((int)$settings->discount_max_amount));
        }

        $referralDiscount = min($referralDiscount, (float)$subtotal);

        if ($referralDiscount <= 0) {
            return ['discount' => 0.0, 'referrer_id' => null];
        }

        return [
            'discount' => (float) $referralDiscount,
            'referrer_id' => (int) $relation->referred_by,
        ];
    }

    public function show(Request $request)
    {
        $user = $this->requireUser($request);
        if ($user instanceof \Illuminate\Http\JsonResponse) return $user;

        return $this->ok($this->buildCartResponseData($user));
    }

    public function add(Request $request)
    {
        $user = $this->requireUser($request);
        if ($user instanceof \Illuminate\Http\JsonResponse) return $user;

        $cart = $this->cartForUser((int) $user->id);

        $v = $request->validate([
            'product_id' => ['required', 'integer', 'min:1'],
            'qty' => ['nullable', 'integer', 'min:1', 'max:99'],
        ]);

        $qty = (int) ($v['qty'] ?? 1);

        $product = Product::query()
            ->where('id', $v['product_id'])
            ->where('is_active', true)
            ->where('is_published', true)
            ->first();

        if (!$product) return $this->fail('Product not available', 404);

        $stock = app(ProductAvailabilityService::class)->forProductId((int) $product->id);

        $item = CartItem::query()
            ->where('cart_id', $cart->id)
            ->where('product_id', $product->id)
            ->first();

        $existingQty = (int) ($item->qty ?? 0);
        $newQty = min(99, $existingQty + $qty);

        if ($stock < $newQty) {
            return $this->fail('Stock tidak cukup', 422, array_merge([
                'product_id' => (int) $product->id,
                'stock_available' => (int) $stock,
                'qty_requested' => (int) $newQty,
            ], $this->buildCartResponseData($user, $cart)));
        }

        if ($item) {
            $item->update(['qty' => $newQty]);
        } else {
            $item = CartItem::create([
                'cart_id' => $cart->id,
                'product_id' => $product->id,
                'qty' => $qty,
            ]);
        }

        return $this->ok(array_merge([
            'message' => 'Added to cart',
            'item' => $item->fresh(),
        ], $this->buildCartResponseData($user, $cart)));
    }

    public function update(Request $request, int $id)
    {
        $user = $this->requireUser($request);
        if ($user instanceof \Illuminate\Http\JsonResponse) return $user;

        $cart = $this->cartForUser((int) $user->id);

        $v = $request->validate([
            'qty' => ['required', 'integer', 'min:1', 'max:99'],
        ]);

        $item = CartItem::query()
            ->where('id', $id)
            ->where('cart_id', $cart->id)
            ->firstOrFail();

        $requestedQty = (int) $v['qty'];

        $stock = app(ProductAvailabilityService::class)->forProductId((int) $item->product_id);

        if ($stock < $requestedQty) {
            return $this->fail('Stock tidak cukup', 422, array_merge([
                'product_id' => (int) $item->product_id,
                'stock_available' => (int) $stock,
                'qty_requested' => (int) $requestedQty,
            ], $this->buildCartResponseData($user, $cart)));
        }

        $item->update(['qty' => $requestedQty]);

        return $this->ok(array_merge([
            'message' => 'Cart updated',
            'item' => $item->fresh(),
        ], $this->buildCartResponseData($user, $cart)));
    }

    public function remove(Request $request, int $id)
    {
        $user = $this->requireUser($request);
        if ($user instanceof \Illuminate\Http\JsonResponse) return $user;

        $cart = $this->cartForUser((int) $user->id);

        $item = CartItem::query()
            ->where('id', $id)
            ->where('cart_id', $cart->id)
            ->firstOrFail();

        $item->delete();

        return $this->ok(array_merge([
            'message' => 'Removed',
            'removed_item_id' => $id,
        ], $this->buildCartResponseData($user, $cart)));
    }

    public function checkout(Request $request)
    {
        $user = $this->requireUser($request);
        if ($user instanceof \Illuminate\Http\JsonResponse) return $user;

        $cart = $this->cartForUser((int) $user->id);

        $v = $request->validate([
            'voucher_code' => ['nullable', 'string', 'max:50'],
        ]);

        $tierKey = (string) ($user->tier ?? 'member');
        $paymentSettings = $this->getPaymentSettings();
        $taxPercent = (int) ($paymentSettings['tax_percent'] ?? 0);
        $feePercent = (float) ($paymentSettings['gateway_fee_percent'] ?? 0.0);

        $cartItems = CartItem::query()
            ->where('cart_id', $cart->id)
            ->with(['product']) // product sudah punya category_id/subcategory_id column
            ->get();

        if ($cartItems->isEmpty()) return $this->fail('Cart kosong', 422);

        $stockMap = $this->getAvailableStockMap(
            $cartItems->pluck('product_id')->filter()->unique()->map(fn ($id) => (int) $id)->values()->all()
        );

        foreach ($cartItems as $ci) {
            $p = $ci->product;
            if (!$p || !$p->is_active || !$p->is_published) {
                return $this->fail('Ada product yang tidak tersedia', 422, [
                    'product_id' => (int) $ci->product_id,
                ]);
            }

            $need = (int) ($ci->qty ?? 1);
            $stock = (int) ($stockMap[(int) $ci->product_id] ?? 0);

            if ($stock < $need) {
                return $this->fail('Stock tidak cukup', 422, [
                    'product_id' => (int) $ci->product_id,
                    'stock_available' => (int) $stock,
                    'qty_requested' => (int) $need,
                ]);
            }

            $unit = $this->resolveUnitPrice($p, $tierKey);
            if ($unit <= 0) {
                return $this->fail('Harga product belum diset (tier_pricing/price kosong)', 422, [
                    'product_id' => (int) $ci->product_id,
                ]);
            }
        }

        return DB::transaction(function () use ($cart, $cartItems, $tierKey, $taxPercent, $feePercent, $v, $user) {

            $computedItems = [];
            $subtotal = 0.0;

            foreach ($cartItems as $ci) {
                $p = $ci->product;
                $qty = (int) ($ci->qty ?? 1);
                $unitPrice = $this->resolveUnitPrice($p, $tierKey);

                $line = (float) ($unitPrice * $qty);
                $subtotal += $line;

                $computedItems[] = [
                    'product_id' => (int) $p->id,
                    'category_id' => isset($p->category_id) ? (int) $p->category_id : null,
                    'subcategory_id' => isset($p->subcategory_id) ? (int) $p->subcategory_id : null,
                    'qty' => $qty,
                    'unit_price' => (float) $unitPrice,
                    'line_subtotal' => (float) $line,
                    'product_name' => (string) ($p->name ?? null),
                    'product_slug' => (string) ($p->slug ?? null),
                ];
            }

            $discountTotal = 0.0;

            // ✅ CAMPAIGN DISCOUNT (based on tier price)
            $campaignSvc = app(DiscountCampaignService::class);
            $campaignResult = $campaignSvc->compute(
                (int) $user->id,
                $computedItems,
                (float) $subtotal
            );

            $campaignDiscountTotal = (float) ($campaignResult['total_discount'] ?? 0.0);
            if ($campaignDiscountTotal > $subtotal) $campaignDiscountTotal = $subtotal;

            $discountTotal += $campaignDiscountTotal;

            // ✅ VOUCHER DISCOUNT (lock + quota only paid/fulfilled)
            $voucher = null;
            $voucherDiscount = 0.0;

            if (!empty($v['voucher_code'])) {
                $code = strtoupper(trim((string) $v['voucher_code']));

                $voucher = Voucher::query()
                    ->where('code', $code)
                    ->lockForUpdate()
                    ->first();

                if (!$voucher) return $this->fail('Voucher tidak ditemukan', 404);
                if (!$voucher->is_active) return $this->fail('Voucher tidak aktif', 422);
                if ($voucher->expires_at && Carbon::parse($voucher->expires_at)->isPast()) {
                    return $this->fail('Voucher sudah kedaluwarsa', 422);
                }
                if ($voucher->min_purchase !== null && $subtotal < (float) $voucher->min_purchase) {
                    return $this->fail('Subtotal belum memenuhi minimal pembelian voucher', 422);
                }
                if ($voucher->quota !== null) {
                    $used = $voucher->orders()
                        ->whereIn('status', [OrderStatus::PAID->value, OrderStatus::FULFILLED->value])
                        ->count();

                    if ($used >= (int) $voucher->quota) {
                        return $this->fail('Kuota voucher sudah habis', 422);
                    }
                }

                if ($voucher->type == 'percent') {
                    $voucherDiscount = (float) floor($subtotal * ((float) $voucher->value / 100));
                } else {
                    $voucherDiscount = (float) $voucher->value;
                }

                if ($voucherDiscount > $subtotal) $voucherDiscount = $subtotal;

                $discountTotal += $voucherDiscount;
            }

            // jangan lebih dari subtotal
            // ✅ REFERRAL DISCOUNT (cart)
            $referralDiscount = 0.0;
            $referrerId = null;

            $ref = $this->computeReferralDiscountForUser((int) $user->id, (float) $subtotal);
            $referralDiscount = (float) ($ref['discount'] ?? 0.0);
            $referrerId = $ref['referrer_id'] ?? null;

            if ($referralDiscount > 0 && $referrerId) {
                $discountTotal += $referralDiscount;
            }

            // jangan lebih dari subtotal
            if ($discountTotal > $subtotal) $discountTotal = $subtotal;

            $taxAmount = 0.0;
            if ($taxPercent > 0) {
                $taxAmount = round($subtotal * ($taxPercent / 100), 2);
            }

            $amount = (float) max(0, ($subtotal + $taxAmount) - $discountTotal);

            $gatewayFeeAmount = 0.0;
            if ($feePercent > 0) {
                $gatewayFeeAmount = round($amount * ($feePercent / 100), 2);
            }

            $invoice = 'INV-' . now()->format('Ymd') . '-' . Str::upper(Str::random(8));

            $order = Order::create([
                'user_id' => (int) $user->id,
                'product_id' => null,
                'invoice_number' => $invoice,
                'status' => OrderStatus::CREATED->value,
                'qty' => null,
                'subtotal' => (float) $subtotal,
                'discount_total' => (float) $discountTotal, // ✅ campaign + voucher
                'tax_percent' => (int) $taxPercent,
                'tax_amount' => (float) $taxAmount,
                'gateway_fee_percent' => (float) $feePercent,
                'gateway_fee_amount' => (float) $gatewayFeeAmount,
                'amount' => (float) $amount, // base (tanpa fee gateway)
                'payment_gateway_code' => null,
            ]);

            // ✅ referral transaction (pending) jika diskon referral dipakai
            if ($referralDiscount > 0 && $referrerId) {
                ReferralTransaction::create([
                    'referrer_id' => (int) $referrerId,
                    'user_id' => (int) $user->id,
                    'order_id' => (int) $order->id,
                    'status' => 'pending',
                    'order_amount' => (int) round((float) $order->amount),
                    'discount_amount' => (int) round((float) $referralDiscount),
                    'commission_amount' => 0,
                    'occurred_at' => null,
                ]);
            }

            foreach ($computedItems as $it) {
                OrderItem::create([
                    'order_id' => (int) $order->id,
                    'product_id' => (int) $it['product_id'],
                    'qty' => (int) $it['qty'],
                    'unit_price' => (float) $it['unit_price'],
                    'line_subtotal' => (float) $it['line_subtotal'],
                    'product_name' => $it['product_name'],
                    'product_slug' => $it['product_slug'],
                ]);
            }

            // attach voucher pivot
            if ($voucher) {
                $order->vouchers()->syncWithoutDetaching([
                    $voucher->id => ['discount_amount' => (float) $voucherDiscount],
                ]);
            }

            // attach campaigns pivot (butuh relasi Order::discountCampaigns())
            if (!empty($campaignResult['applied'])) {
                foreach ($campaignResult['applied'] as $ac) {
                    $order->discountCampaigns()->attach((int) $ac['id'], [
                        'discount_amount' => (float) $ac['discount_amount'],
                    ]);
                }
            }

            CartItem::query()->where('cart_id', (int) $cart->id)->delete();

            return $this->buildCheckoutSuccessPayload(
                $order,
                (float) $campaignDiscountTotal,
                (float) $voucherDiscount,
                (float) $referralDiscount,
                (float) $discountTotal,
                $campaignResult['applied'] ?? [],
                (float) $feePercent,
                (float) $gatewayFeeAmount
            );
        });
    }

    public function buyNow(Request $request)
    {
        $user = $this->requireUser($request);
        if ($user instanceof \Illuminate\Http\JsonResponse) return $user;

        $v = $request->validate([
            'product_id' => ['required', 'integer', 'min:1'],
            'qty' => ['nullable', 'integer', 'min:1', 'max:99'],
            'voucher_code' => ['nullable', 'string', 'max:50'],
        ]);

        $qty = (int) ($v['qty'] ?? 1);
        $tierKey = (string) ($user->tier ?? 'member');
        $paymentSettings = $this->getPaymentSettings();
        $taxPercent = (int) ($paymentSettings['tax_percent'] ?? 0);
        $feePercent = (float) ($paymentSettings['gateway_fee_percent'] ?? 0.0);

        $product = Product::query()
            ->where('id', (int) $v['product_id'])
            ->where('is_active', true)
            ->where('is_published', true)
            ->with(['category:id,name,slug', 'subcategory:id,category_id,name,slug,provider,image_url,image_path'])
            ->first();

        if (!$product) return $this->fail('Product not available', 404);

        $stock = app(ProductAvailabilityService::class)->forProductId((int) $product->id);
        if ($stock < $qty) {
            return $this->fail('Stock tidak cukup', 422, [
                'product_id' => (int) $product->id,
                'stock_available' => (int) $stock,
                'qty_requested' => (int) $qty,
            ]);
        }

        $unitPrice = $this->resolveUnitPrice($product, $tierKey);
        if ($unitPrice <= 0) {
            return $this->fail('Harga product belum diset (tier_pricing/price kosong)', 422, [
                'product_id' => (int) $product->id,
            ]);
        }

        return DB::transaction(function () use ($user, $product, $qty, $unitPrice, $taxPercent, $feePercent, $v) {
            $computedItems = [[
                'product_id' => (int) $product->id,
                'category_id' => isset($product->category_id) ? (int) $product->category_id : null,
                'subcategory_id' => isset($product->subcategory_id) ? (int) $product->subcategory_id : null,
                'qty' => (int) $qty,
                'unit_price' => (float) $unitPrice,
                'line_subtotal' => (float) ($unitPrice * $qty),
                'product_name' => (string) ($product->name ?? null),
                'product_slug' => (string) ($product->slug ?? null),
            ]];

            $subtotal = (float) ($unitPrice * $qty);
            $discountTotal = 0.0;

            $campaignSvc = app(DiscountCampaignService::class);
            $campaignResult = $campaignSvc->compute((int) $user->id, $computedItems, (float) $subtotal);
            $campaignDiscountTotal = (float) ($campaignResult['total_discount'] ?? 0.0);
            if ($campaignDiscountTotal > $subtotal) $campaignDiscountTotal = $subtotal;
            $discountTotal += $campaignDiscountTotal;

            $voucher = null;
            $voucherDiscount = 0.0;

            if (!empty($v['voucher_code'])) {
                $code = strtoupper(trim((string) $v['voucher_code']));
                $voucher = Voucher::query()->where('code', $code)->lockForUpdate()->first();

                if (!$voucher) return $this->fail('Voucher tidak ditemukan', 404);
                if (!$voucher->is_active) return $this->fail('Voucher tidak aktif', 422);
                if ($voucher->expires_at && Carbon::parse($voucher->expires_at)->isPast()) {
                    return $this->fail('Voucher sudah kedaluwarsa', 422);
                }
                if ($voucher->min_purchase !== null && $subtotal < (float) $voucher->min_purchase) {
                    return $this->fail('Subtotal belum memenuhi minimal pembelian voucher', 422);
                }
                if ($voucher->quota !== null) {
                    $used = $voucher->orders()
                        ->whereIn('status', [OrderStatus::PAID->value, OrderStatus::FULFILLED->value])
                        ->count();

                    if ($used >= (int) $voucher->quota) {
                        return $this->fail('Kuota voucher sudah habis', 422);
                    }
                }

                if ($voucher->type == 'percent') {
                    $voucherDiscount = (float) floor($subtotal * ((float) $voucher->value / 100));
                } else {
                    $voucherDiscount = (float) $voucher->value;
                }

                if ($voucherDiscount > $subtotal) $voucherDiscount = $subtotal;
                $discountTotal += $voucherDiscount;
            }

            $ref = $this->computeReferralDiscountForUser((int) $user->id, (float) $subtotal);
            $referralDiscount = (float) ($ref['discount'] ?? 0.0);
            $referrerId = $ref['referrer_id'] ?? null;

            if ($referralDiscount > 0 && $referrerId) {
                $discountTotal += $referralDiscount;
            }

            if ($discountTotal > $subtotal) $discountTotal = $subtotal;

            $taxAmount = $taxPercent > 0 ? round($subtotal * ($taxPercent / 100), 2) : 0.0;
            $amount = (float) max(0, ($subtotal + $taxAmount) - $discountTotal);
            $gatewayFeeAmount = $feePercent > 0 ? round($amount * ($feePercent / 100), 2) : 0.0;

            $invoice = 'INV-' . now()->format('Ymd') . '-' . Str::upper(Str::random(8));

            $order = Order::create([
                'user_id' => (int) $user->id,
                'product_id' => (int) $product->id,
                'invoice_number' => $invoice,
                'status' => OrderStatus::CREATED->value,
                'qty' => (int) $qty,
                'subtotal' => (float) $subtotal,
                'discount_total' => (float) $discountTotal,
                'tax_percent' => (int) $taxPercent,
                'tax_amount' => (float) $taxAmount,
                'gateway_fee_percent' => (float) $feePercent,
                'gateway_fee_amount' => (float) $gatewayFeeAmount,
                'amount' => (float) $amount,
                'payment_gateway_code' => null,
            ]);

            if ($referralDiscount > 0 && $referrerId) {
                ReferralTransaction::create([
                    'referrer_id' => (int) $referrerId,
                    'user_id' => (int) $user->id,
                    'order_id' => (int) $order->id,
                    'status' => 'pending',
                    'order_amount' => (int) round((float) $order->amount),
                    'discount_amount' => (int) round((float) $referralDiscount),
                    'commission_amount' => 0,
                    'occurred_at' => null,
                ]);
            }

            OrderItem::create([
                'order_id' => (int) $order->id,
                'product_id' => (int) $product->id,
                'qty' => (int) $qty,
                'unit_price' => (float) $unitPrice,
                'line_subtotal' => (float) ($unitPrice * $qty),
                'product_name' => (string) ($product->name ?? null),
                'product_slug' => (string) ($product->slug ?? null),
            ]);

            if ($voucher) {
                $order->vouchers()->syncWithoutDetaching([
                    $voucher->id => ['discount_amount' => (float) $voucherDiscount],
                ]);
            }

            if (!empty($campaignResult['applied'])) {
                foreach ($campaignResult['applied'] as $ac) {
                    $order->discountCampaigns()->attach((int) $ac['id'], [
                        'discount_amount' => (float) $ac['discount_amount'],
                    ]);
                }
            }

            return $this->buildCheckoutSuccessPayload(
                $order,
                (float) $campaignDiscountTotal,
                (float) $voucherDiscount,
                (float) $referralDiscount,
                (float) $discountTotal,
                $campaignResult['applied'] ?? [],
                (float) $feePercent,
                (float) $gatewayFeeAmount
            );
        });
    }

    public function checkoutPreview(Request $request)
    {
        $user = $this->requireUser($request);
        if ($user instanceof \Illuminate\Http\JsonResponse) return $user;

        $cart = $this->cartForUser((int) $user->id);

        $v = $request->validate([
            'voucher_code' => ['nullable', 'string', 'max:50'],
        ]);

        $tierKey = (string) ($user->tier ?? 'member');
        $paymentSettings = $this->getPaymentSettings();
        $taxPercent = (int) ($paymentSettings['tax_percent'] ?? 0);
        $feePercent = (float) ($paymentSettings['gateway_fee_percent'] ?? 0.0);

        $cartItems = CartItem::query()
            ->where('cart_id', $cart->id)
            ->with(['product'])
            ->get();

        // kalau cart kosong, fallback ke last order
        if ($cartItems->isEmpty()) {
            $lastOrder = Order::query()
                ->where('user_id', $user->id)
                ->with(['items.product'])
                ->latest('id')
                ->first();

            if (!$lastOrder) return $this->fail('Cart kosong', 422);

            return $this->ok([
                'mode' => 'order',
                'order' => $lastOrder,
                'items' => $lastOrder->items,
                'tier' => $tierKey,
                'summary' => [
                    'subtotal' => (float) $lastOrder->subtotal,
                    'discount_total' => (float) $lastOrder->discount_total,
                    'tax_percent' => (int) ($lastOrder->tax_percent ?? 0),
                    'tax_amount' => (float) $lastOrder->tax_amount,
                    'total' => (float) $lastOrder->amount,
                    'gateway_fee_percent' => (float) ($lastOrder->gateway_fee_percent ?? 0),
                    'gateway_fee_amount' => (float) ($lastOrder->gateway_fee_amount ?? 0),
                    'total_payable_gateway' => (float) ((float) $lastOrder->amount + (float) ($lastOrder->gateway_fee_amount ?? 0)),
                ],
            ]);
        }

        $stockMap = $this->getAvailableStockMap(
            $cartItems->pluck('product_id')->filter()->unique()->map(fn ($id) => (int) $id)->values()->all()
        );

        foreach ($cartItems as $ci) {
            $p = $ci->product;
            if (!$p || !$p->is_active || !$p->is_published) {
                return $this->fail('Ada product yang tidak tersedia', 422, [
                    'product_id' => (int) $ci->product_id,
                ]);
            }

            $need = (int) ($ci->qty ?? 1);
            $stock = (int) ($stockMap[(int) $ci->product_id] ?? 0);

            if ($stock < $need) {
                return $this->fail('Stock tidak cukup', 422, [
                    'product_id' => (int) $ci->product_id,
                    'stock_available' => (int) $stock,
                    'qty_requested' => (int) $need,
                ]);
            }

            $unit = $this->resolveUnitPrice($p, $tierKey);
            if ($unit <= 0) {
                return $this->fail('Harga product belum diset (tier_pricing/price kosong)', 422, [
                    'product_id' => (int) $ci->product_id,
                ]);
            }
        }

        $items = [];
        $computedItems = [];
        $subtotal = 0.0;

        foreach ($cartItems as $ci) {
            $p = $ci->product;
            $qty = (int) ($ci->qty ?? 1);
            $unitPrice = $this->resolveUnitPrice($p, $tierKey);

            $line = (float) ($unitPrice * $qty);
            $subtotal += $line;

            $items[] = [
                'product_id' => (int) $p->id,
                'qty' => $qty,
                'unit_price' => (float) $unitPrice,
                'line_subtotal' => (float) $line,
                'product' => $p,
                'tier' => $tierKey,
            ];

            $computedItems[] = [
                'product_id' => (int) $p->id,
                'category_id' => isset($p->category_id) ? (int) $p->category_id : null,
                'subcategory_id' => isset($p->subcategory_id) ? (int) $p->subcategory_id : null,
                'qty' => $qty,
                'unit_price' => (float) $unitPrice,
                'line_subtotal' => (float) $line,
            ];
        }

        $discountTotal = 0.0;

        // ✅ campaign discount
        $campaignSvc = app(DiscountCampaignService::class);
        $campaignResult = $campaignSvc->compute(
            (int) $user->id,
            $computedItems,
            (float) $subtotal
        );
        $campaignDiscountTotal = (float) ($campaignResult['total_discount'] ?? 0.0);
        if ($campaignDiscountTotal > $subtotal) $campaignDiscountTotal = $subtotal;

        $discountTotal += $campaignDiscountTotal;

        // ✅ voucher discount (preview: no lock; quota check still ok)
        $voucherDiscount = 0.0;
        if (!empty($v['voucher_code'])) {
            $code = strtoupper(trim((string) $v['voucher_code']));

            $voucher = Voucher::query()->where('code', $code)->first();
            if (!$voucher) return $this->fail('Voucher tidak ditemukan', 404);
            if (!$voucher->is_active) return $this->fail('Voucher tidak aktif', 422);
            if ($voucher->expires_at && Carbon::parse($voucher->expires_at)->isPast()) {
                return $this->fail('Voucher sudah kedaluwarsa', 422);
            }
            if ($voucher->min_purchase !== null && $subtotal < (float) $voucher->min_purchase) {
                return $this->fail('Subtotal belum memenuhi minimal pembelian voucher', 422);
            }
            if ($voucher->quota !== null) {
                $used = $voucher->orders()
                    ->whereIn('status', [OrderStatus::PAID->value, OrderStatus::FULFILLED->value])
                    ->count();

                if ($used >= (int) $voucher->quota) {
                    return $this->fail('Kuota voucher sudah habis', 422);
                }
            }

            if ($voucher->type === 'percent') {
                $voucherDiscount = (float) floor($subtotal * ((float) $voucher->value / 100));
            } else {
                $voucherDiscount = (float) $voucher->value;
            }

            if ($voucherDiscount > $subtotal) $voucherDiscount = $subtotal;

            $discountTotal += $voucherDiscount;
        }

        // ✅ REFERRAL DISCOUNT (preview)
        $referralDiscount = 0.0;
        $referrerId = null;

        $ref = $this->computeReferralDiscountForUser((int) $user->id, (float) $subtotal);
        $referralDiscount = (float) ($ref['discount'] ?? 0.0);
        $referrerId = $ref['referrer_id'] ?? null;

        if ($referralDiscount > 0 && $referrerId) {
            $discountTotal += $referralDiscount;
        }

        if ($discountTotal > $subtotal) $discountTotal = $subtotal;

        $taxAmount = 0.0;
        if ($taxPercent > 0) {
            $taxAmount = round($subtotal * ($taxPercent / 100), 2);
        }

        $total = (float) max(0, ($subtotal + $taxAmount) - $discountTotal);

        $gatewayFeeAmount = 0.0;
        if ($feePercent > 0) {
            $gatewayFeeAmount = round($total * ($feePercent / 100), 2);
        }

        return $this->ok([
            'mode' => 'cart',
            'items' => $items,
            'discount_breakdown' => [
                'campaign_discount_total' => (float) $campaignDiscountTotal,
                'voucher_discount_total' => (float) $voucherDiscount,
                'referral_discount_total' => (float) $referralDiscount,
                'discount_total' => (float) $discountTotal,
                'applied_campaigns' => $campaignResult['applied'] ?? [],
            ],
            'summary' => [
                'subtotal' => $subtotal,
                'tier' => $tierKey,
                'discount_total' => $discountTotal,
                'tax_percent' => $taxPercent,
                'tax_amount' => $taxAmount,
                'total' => $total,
                'gateway_fee_percent' => (float) $feePercent,
                'gateway_fee_amount' => (float) $gatewayFeeAmount,
                'total_payable_gateway' => (float) ($total + $gatewayFeeAmount),
            ],
        ]);
    }

    private function getAvailableStockMap(array $productIds): array
    {
        return app(ProductAvailabilityService::class)->forProductIds($productIds);
    }
}