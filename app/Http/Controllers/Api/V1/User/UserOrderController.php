<?php

namespace App\Http\Controllers\Api\V1\User;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Voucher;
use App\Models\Setting;
use App\Services\LedgerService;
use App\Services\MidtransService;
use App\Services\OrderFulfillmentService;
use App\Services\DiscountCampaignService;
use App\Support\ApiResponse;
use App\Support\RuntimeCache;
use App\Support\PublicCache;
use App\Http\Controllers\Controller;
use App\Support\DispatchesInvoiceEmail;
use App\Models\ReferralSetting;
use App\Models\ReferralTransaction;
use App\Models\Referral;
use App\Jobs\ProcessPaidOrderJob;
use App\Models\License;
use Illuminate\Http\JsonResponse;
use App\Support\UserTierEligibility;

// ✅ NEW: service untuk komisi referral saat PAID (wallet/midtrans)
use App\Services\ReferralCommissionService;
use App\Services\LicenseStockService;
use App\Models\Cart;
use App\Models\CartItem;
use App\Services\ProductAvailabilityService;

class UserOrderController extends Controller
{
    use ApiResponse, DispatchesInvoiceEmail;

    private function rejectVoucherIfTierNotEligible(Voucher $voucher, ?string $tierKey)
    {
        if (!UserTierEligibility::voucherAllowed($voucher, $tierKey)) {
            return $this->fail(
                UserTierEligibility::voucherMessage($voucher, $tierKey),
                422,
                [
                    'tier' => UserTierEligibility::normalizeTier($tierKey),
                    'rules' => UserTierEligibility::tierSummaryFromRules($voucher->rules ?? []),
                ]
            );
        }

        return null;
    }

    // =========================
    // Helpers: settings
    // =========================
    private function getTaxPercent(): int
    {
        $row = Setting::query()
            ->where('group', 'payment')
            ->where('key', 'tax_percent')
            ->first();

        if (!$row) return 0;

        $val = $row->value;

        if (is_string($val)) {
            $decoded = json_decode($val, true);
            if (json_last_error() === JSON_ERROR_NONE) $val = $decoded;
        }

        if (is_array($val)) return (int) ($val['percent'] ?? 0);
        if (is_numeric($val)) return (int) $val;

        return 0;
    }

    private function getGatewayFeePercent(): float
    {
        // Fee final sekarang ditentukan dari payment gateway yang dipilih saat createPayment()
        return 0.0;
    }

    private function resolveGatewayFee(\App\Models\PaymentGateway $gateway, float $baseAmount): array
    {
        $type = strtolower((string) ($gateway->fee_type ?? ''));
        $value = (float) ($gateway->fee_value ?? 0);

        if ($type === 'fixed') {
            return [
                'type' => 'fixed',
                'percent' => 0.0,
                'amount' => max(0.0, round($value, 2)),
            ];
        }

        if ($type === 'percent') {
            $percent = max(0.0, $value);

            return [
                'type' => 'percent',
                'percent' => $percent,
                'amount' => round(max(0.0, $baseAmount) * ($percent / 100), 2),
            ];
        }

        return [
            'type' => null,
            'percent' => 0.0,
            'amount' => 0.0,
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

        return (int) max(0, $unitPrice);
    }

    private function resolveUnitProfit(Product $product, string $tierKey): float
    {
        $tierProfit = (array) ($product->tier_profit ?? []);
        $unitProfit = 0.0;

        if (!empty($tierProfit)) {
            $unitProfit = (float) ($tierProfit[$tierKey] ?? 0);
            if ($unitProfit <= 0) $unitProfit = (float) ($tierProfit['member'] ?? 0);

            if ($unitProfit <= 0) {
                $vals = array_values($tierProfit);
                $unitProfit = (float) ($vals[0] ?? 0);
            }
        }

        return max(0.0, round($unitProfit, 2));
    }

    private function runOrderFulfillment(\App\Services\OrderFulfillmentService $fulfillment, \App\Models\Order $order): array
    {
        foreach (['fulfillPaidOrder', 'handlePaidOrder', 'processPaidOrder', 'fulfill'] as $method) {
            if (!method_exists($fulfillment, $method)) {
                continue;
            }

            try {
                $result = $fulfillment->{$method}($order);

                if (is_array($result)) {
                    return $result;
                }

                return ['success' => $result !== false];
            } catch (\ArgumentCountError $e) {
                continue;
            }
        }

        return ['success' => false];
    }

    private function dispatchInvoiceForOrder(\App\Models\Order $order, string $reason): void
    {
        foreach (['dispatchInvoiceEmailAfterCommit', 'dispatchInvoiceEmail', 'queueInvoiceEmailAfterCommit'] as $method) {
            if (!method_exists($this, $method)) {
                continue;
            }

            try {
                $this->{$method}((int) $order->id, $reason, (string) $order->invoice_number);
                return;
            } catch (\ArgumentCountError $e) {
                try {
                    $this->{$method}((int) $order->id);
                    return;
                } catch (\Throwable $e2) {
                    return;
                }
            } catch (\Throwable $e) {
                return;
            }
        }
    }

    private function dispatchPaidOrderJobOnce(
        int $orderId,
        string $source,
        ?string $invoiceNumber = null
    ): bool {
        $lockKey = 'dispatch:process_paid_order:' . $orderId;
        $lockSeconds = 420;
        $lock = Cache::lock($lockKey, $lockSeconds);

        if (!$lock->get()) {
            Log::warning('PROCESS PAID ORDER JOB DUPLICATE DISPATCH BLOCKED', [
                'order_id' => $orderId,
                'invoice_number' => $invoiceNumber,
                'source' => $source,
                'queue' => 'fulfillment',
                'lock_key' => $lockKey,
                'lock_ttl_seconds' => $lockSeconds,
            ]);

            return false;
        }

        try {
            $job = ProcessPaidOrderJob::dispatch($orderId, $source)->delay(now()->addSecond());

            if (method_exists($job, 'afterCommit')) {
                $job->afterCommit();
            }

            Log::info('PROCESS PAID ORDER JOB DISPATCHED', [
                'order_id' => $orderId,
                'invoice_number' => $invoiceNumber,
                'source' => $source,
                'queue' => 'fulfillment',
                'lock_key' => $lockKey,
                'lock_ttl_seconds' => $lockSeconds,
            ]);

            return true;
        } catch (\Throwable $e) {
            try {
                $lock->release();
            } catch (\Throwable $ignored) {
            }

            Log::error('PROCESS PAID ORDER JOB DISPATCH FAILED', [
                'order_id' => $orderId,
                'invoice_number' => $invoiceNumber,
                'source' => $source,
                'queue' => 'fulfillment',
                'lock_key' => $lockKey,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function normalizePaymentMethodCode(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'wallet' : '';
        }

        if (is_array($value)) {
            foreach ([
                'code',
                'gateway_code',
                'method',
                'value',
                'id',
                'slug',
                'name',
            ] as $key) {
                $candidate = $this->normalizePaymentMethodCode($value[$key] ?? null);

                if ($candidate !== '') {
                    return $candidate;
                }
            }

            return '';
        }

        if ($value === null) {
            return '';
        }

        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            'wallet', 'saldo', 'balance', 'wallet_balance', 'internal_wallet', 'gtc_wallet', 'my_wallet' => 'wallet',
            default => $normalized,
        };
    }

    private function extractSelectedPaymentCode(Request $request, array $validated = []): string
    {
        foreach ([
            $validated['gateway_code'] ?? null,
            $validated['method'] ?? null,
            $validated['gateway'] ?? null,
            $validated['payment_method'] ?? null,
            $validated['payment_gateway_code'] ?? null,
            $validated['payment_method_code'] ?? null,
            $validated['selected_method'] ?? null,
            $request->input('gateway_code'),
            $request->input('method'),
            $request->input('gateway'),
            $request->input('payment_method'),
            $request->input('payment_gateway_code'),
            $request->input('payment_method_code'),
            $request->input('selected_method'),
            data_get($request->all(), 'selected_payment_method.code'),
            data_get($request->all(), 'selectedPaymentMethod.code'),
            data_get($request->all(), 'payment.code'),
            data_get($request->all(), 'payment.method'),
            data_get($request->all(), 'gateway.code'),
        ] as $candidate) {
            $selected = $this->normalizePaymentMethodCode($candidate);

            if ($selected !== '') {
                return $selected;
            }
        }

        if (filter_var($request->input('wallet'), FILTER_VALIDATE_BOOLEAN)) {
            return 'wallet';
        }

        if (filter_var($request->input('use_wallet'), FILTER_VALIDATE_BOOLEAN)) {
            return 'wallet';
        }

        return '';
    }

    private function isWalletMethod(string $selected): bool
    {
        return $this->normalizePaymentMethodCode($selected) === 'wallet';
    }

    // =========================
    // POST /api/v1/orders (BUY NOW)
    // =========================
    public function store(Request $request)
    {
        $user = $request->user();

        $v = $request->validate([
            'product_id' => ['required', 'integer', 'min:1'],
            'qty' => ['nullable', 'integer', 'min:1', 'max:99'],
            'voucher_code' => ['nullable', 'string', 'max:50'],
        ]);

        $qty = (int) ($v['qty'] ?? 1);

        $product = Product::query()->where('id', (int) $v['product_id'])->first();
        if (!$product) return $this->fail('Product tidak ditemukan', 404);

        if (property_exists($product, 'is_active') && $product->is_active === false) {
            return $this->fail('Product tidak aktif', 422);
        }
        if (property_exists($product, 'is_published') && $product->is_published === false) {
            return $this->fail('Product belum dipublish', 422);
        }

        $stock = $this->availableLicenseStock((int) $product->id);

        if ($stock < $qty) {
            return $this->fail('Stock tidak cukup', 422, [
                'product_id' => (int) $product->id,
                'stock_available' => (int) $stock,
                'qty_requested' => (int) $qty,
            ]);
        }

        // ✅ tier pricing + profit per tier
        $tierKey = (string) ($user->tier ?? 'member');
        $unitPrice = $this->resolveUnitPrice($product, $tierKey);
        $unitProfit = $this->resolveUnitProfit($product, $tierKey);

        if ($unitPrice <= 0) {
            return $this->fail('Harga product belum diset (tier_pricing/price kosong)', 422);
        }

        $unitBasePrice = (float) max(0, $unitPrice - $unitProfit);
        $subtotal = (float) ($unitPrice * $qty);
        $lineProfit = (float) ($unitProfit * $qty);

        return DB::transaction(function () use ($user, $product, $qty, $unitBasePrice, $unitPrice, $unitProfit, $subtotal, $lineProfit, $v) {

            $discountTotal = 0.0;

            // =========================
            // ✅ CAMPAIGN DISCOUNT (category/subcategory/product)
            // =========================
            $computedItems = [[
                'product_id' => (int) $product->id,
                'category_id' => isset($product->category_id) ? (int) $product->category_id : null,
                'subcategory_id' => isset($product->subcategory_id) ? (int) $product->subcategory_id : null,
                'qty' => (int) $qty,
                'unit_base_price' => (float) $unitBasePrice,
                'unit_profit' => (float) $unitProfit,
                'unit_price' => (float) $unitPrice,
                'line_subtotal' => (float) $subtotal,
                'line_profit' => (float) $lineProfit,
                'product_name' => (string) ($product->name ?? null),
                'product_slug' => (string) ($product->slug ?? null),
            ]];

            $campaignSvc = app(DiscountCampaignService::class);
            $campaignResult = $campaignSvc->compute(
                (int) $user->id,
                $computedItems,
                (float) $subtotal
            );

            $campaignDiscountTotal = (float) ($campaignResult['total_discount'] ?? 0.0);
            if ($campaignDiscountTotal > $subtotal) $campaignDiscountTotal = $subtotal;

            $discountTotal += $campaignDiscountTotal;

            // =========================
            // ✅ VOUCHER DISCOUNT (lock + quota paid/fulfilled)
            // =========================
            $voucher = null;
            $voucherDiscount = 0.0;

            if (!empty($v['voucher_code'])) {
                $$code = trim((string) $v['voucher_code']);
                $safeTierKey = UserTierEligibility::normalizeTier($user->tier ?? 'member');

                $voucher = Voucher::query()
                    ->where('code', $code)
                    ->lockForUpdate()
                    ->first();

                if (!$voucher) return $this->fail('Voucher tidak ditemukan', 404);
                if (!$voucher->is_active) return $this->fail('Voucher tidak aktif', 422);
                if ($voucher->expires_at && Carbon::parse($voucher->expires_at)->isPast()) {
                    return $this->fail('Voucher sudah kedaluwarsa', 422);
                }

                if ($response = $this->rejectVoucherIfTierNotEligible($voucher, $safeTierKey)) {
                    return $response;
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

            // =========================
            // ✅ REFERRAL DISCOUNT
            // =========================
            $referralDiscount = 0.0;
            $referrerId = null;

            $settings = ReferralSetting::current();
            if ($settings && $settings->isActiveNow()) {

                $relation = Referral::query()
                    ->where('user_id', (int) $user->id)
                    ->first();

                if ($relation && $relation->locked_at) {

                    if ((int)$settings->min_order_amount <= 0 || (float)$subtotal >= (float)$settings->min_order_amount) {

                        $usage = app(ReferralCommissionService::class)->getUsageSummary((int) $user->id, (int) $relation->referred_by);

                        if (!((bool) ($usage['limit_reached'] ?? false))) {

                            if ($settings->discount_type === 'fixed') {
                                $referralDiscount = (float) ((int)$settings->discount_value);
                            } else {
                                $referralDiscount = (float) floor((float)$subtotal * ((int)$settings->discount_value) / 100);
                            }

                            if ((int)$settings->discount_max_amount > 0) {
                                $referralDiscount = min($referralDiscount, (float)((int)$settings->discount_max_amount));
                            }

                            $referralDiscount = min($referralDiscount, (float)$subtotal);

                            if ($referralDiscount > 0) {
                                $discountTotal += $referralDiscount;
                                $referrerId = (int) $relation->referred_by;
                            }
                        }
                    }
                }
            }

            if ($discountTotal > $subtotal) $discountTotal = $subtotal;

            // ✅ tax
            $taxPercent = $this->getTaxPercent();
            $taxAmount = 0.0;
            if ($taxPercent > 0) {
                $taxAmount = round((float)$subtotal * ((float)$taxPercent / 100), 2);
            }

            // ✅ base amount (wallet pakai ini)
            $amount = (float) max(0, ((float)$subtotal + (float)$taxAmount) - (float)$discountTotal);

            // ✅ fee gateway
            $feePercent = $this->getGatewayFeePercent();
            $gatewayFeeAmount = 0.0;
            if ($feePercent > 0) {
                $gatewayFeeAmount = round((float)$amount * ((float)$feePercent / 100), 2);
            }

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
                'checkout_source' => 'buy_now',
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

            OrderItem::create([
                'order_id' => (int) $order->id,
                'product_id' => (int) $product->id,
                'qty' => (int) $qty,
                'unit_base_price' => (float) $unitBasePrice,
                'unit_profit' => (float) $unitProfit,
                'unit_price' => (float) $unitPrice,
                'line_subtotal' => (float) $subtotal,
                'line_profit' => (float) $lineProfit,
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

            return $this->ok([
                'order' => $order->fresh()->load(['items.product', 'product', 'vouchers', 'discountCampaigns']),
                'discount_breakdown' => [
                    'campaign_discount_total' => (float) $campaignDiscountTotal,
                    'voucher_discount_total' => (float) $voucherDiscount,
                    'referral_discount_total' => (float) $referralDiscount,
                    'discount_total' => (float) $discountTotal,
                    'applied_campaigns' => $campaignResult['applied'] ?? [],
                ],
                'payment_gateway_summary' => [
                    'fee_percent' => (float) $feePercent,
                    'fee_amount' => (float) $gatewayFeeAmount,
                    'total_payable' => (float) ($amount + $gatewayFeeAmount),
                ],
            ]);
        });
    }

    private function clearUserCartForOrderSource(Order $order, int $userId): void
    {
        $source = (string) ($order->checkout_source ?? '');

        if ($source !== 'cart') {
            return;
        }

        $cart = Cart::query()->firstOrCreate(['user_id' => $userId]);
        CartItem::query()->where('cart_id', (int) $cart->id)->delete();
    }

    private function reserveStockForOrder(Order $order, LicenseStockService $licenseStockService): void
    {
        $items = $order->items;

        if (!$items || $items->isEmpty()) {
            $items = collect([(object) [
                'product_id' => (int) ($order->product_id ?? 0),
                'qty' => (int) ($order->qty ?? 1),
            ]]);
        }

        foreach ($items as $item) {
            $productId = (int) ($item->product_id ?? 0);
            $qty = max(1, (int) ($item->qty ?? 1));

            if ($productId <= 0) {
                continue;
            }

            $alreadyReserved = License::query()
                ->where('product_id', $productId)
                ->where('reserved_order_id', (int) $order->id)
                ->where('status', License::STATUS_RESERVED)
                ->count();

            if ($alreadyReserved >= $qty) {
                continue;
            }

            $needReserve = $qty - $alreadyReserved;
            $licenseStockService->reserve($productId, (int) $order->id, $needReserve);
        }
    }

    private function availableLicenseStock(int $productId): int
    {
        return app(ProductAvailabilityService::class)->forProductId($productId);
    }

    private function stockErrorResponseForOrder(Order $order): ?JsonResponse
    {
        $items = $order->items;

        if (!$items || $items->isEmpty()) {
            $items = collect([
                (object) [
                    'product_id' => (int) ($order->product_id ?? 0),
                    'qty' => (int) ($order->qty ?? 1),
                ]
            ]);
        }

        foreach ($items as $item) {
            $productId = (int) ($item->product_id ?? 0);
            $needQty = max(1, (int) ($item->qty ?? 1));

            if ($productId <= 0) {
                return $this->fail('Product invalid', 422);
            }

            $stock = $this->availableLicenseStock($productId);

            if ($stock < $needQty) {
                return $this->fail('Stock tidak cukup', 422, [
                    'product_id' => $productId,
                    'stock_available' => $stock,
                    'qty_requested' => $needQty,
                ]);
            }
        }

        return null;
    }

    // =========================
    // POST /api/v1/orders/{id}/payments
    // =========================
    public function createPayment(
        Request $request,
        string $id,
        MidtransService $midtrans,
        LedgerService $ledger,
        OrderFulfillmentService $fulfillment,
        \App\Services\Payments\PaymentGatewayManager $gatewayManager,
        LicenseStockService $licenseStockService
    ) {
        $user = $request->user();

        $validated = $request->validate([
            'gateway_code' => ['nullable', 'string', 'max:100'],
            'method' => ['nullable', 'string', 'max:100'],
            'gateway' => ['nullable', 'string', 'max:100'],
            'payment_method' => ['nullable', 'string', 'max:100'],
            'payment_gateway_code' => ['nullable', 'string', 'max:100'],
            'payment_method_code' => ['nullable', 'string', 'max:100'],
            'selected_method' => ['nullable', 'string', 'max:100'],
            'wallet' => ['nullable'],
            'use_wallet' => ['nullable'],
        ]);

        $selected = $this->extractSelectedPaymentCode($request, $validated);

        Log::info('ORDER CREATE PAYMENT REQUEST', [
            'order_id' => (int) $id,
            'user_id' => (int) $user->id,
            'selected' => $selected,
            'raw_gateway_code' => $request->input('gateway_code'),
            'raw_method' => $request->input('method'),
            'raw_gateway' => $request->input('gateway'),
            'raw_payment_method' => $request->input('payment_method'),
            'raw_payment_gateway_code' => $request->input('payment_gateway_code'),
            'raw_payment_method_code' => $request->input('payment_method_code'),
            'wallet_flag' => $request->input('wallet'),
            'use_wallet_flag' => $request->input('use_wallet'),
        ]);

        if ($selected === '') {
            $defaultGateway = $gatewayManager->defaultForScope('order');

            if (!$defaultGateway) {
                return $this->fail('Tidak ada payment gateway order yang aktif', 422);
            }

            $selected = (string) $defaultGateway->code;
        }

        $order = Order::query()
            ->where('id', (int) $id)
            ->where('user_id', (int) $user->id)
            ->with(['items.product', 'product', 'payment', 'vouchers'])
            ->first();

        if (!$order) {
            return $this->fail('Order tidak ditemukan', 404);
        }

        $currentStatus = (string) ($order->status?->value ?? $order->status);
        if (in_array($currentStatus, [
            \App\Enums\OrderStatus::PAID->value,
            \App\Enums\OrderStatus::FULFILLED->value,
            \App\Enums\OrderStatus::REFUNDED->value,
        ], true)) {
            return $this->fail('Order sudah diproses pembayaran', 409);
        }

        $lockKey = 'order:payment:init:' . (int) $order->id;
        $lockSeconds = 25;
        $lock = Cache::lock($lockKey, $lockSeconds);

        if (!$lock->get()) {
            return $this->fail('Pembayaran sedang diproses, coba beberapa detik lagi', 409, [
                'order_id' => (int) $order->id,
                'lock_key' => $lockKey,
            ]);
        }

        try {
            if ($this->isWalletMethod($selected)) {
                try {
                    $result = DB::transaction(function () use ($order, $user, $ledger, $licenseStockService) {
                        $locked = Order::query()
                            ->where('id', (int) $order->id)
                            ->where('user_id', (int) $user->id)
                            ->with(['items.product', 'product', 'payment', 'vouchers'])
                            ->lockForUpdate()
                            ->first();

                        if (!$locked) {
                            return $this->fail('Order tidak ditemukan', 404);
                        }

                        $currentStatus = (string) ($locked->status?->value ?? $locked->status);
                        if (in_array($currentStatus, [
                            \App\Enums\OrderStatus::PAID->value,
                            \App\Enums\OrderStatus::FULFILLED->value,
                            \App\Enums\OrderStatus::REFUNDED->value,
                        ], true)) {
                            return $this->ok([
                                'method' => 'wallet',
                                'already_paid' => true,
                                'order' => $locked->fresh()->load(['items.product', 'product', 'payment', 'vouchers']),
                            ]);
                        }

                        if ($stockError = $this->stockErrorResponseForOrder($locked)) {
                            return $stockError;
                        }

                        $this->reserveStockForOrder($locked, $licenseStockService);

                        $amountInt = (int) round((float) $locked->amount);
                        if ($amountInt <= 0) {
                            return $this->fail('Amount invalid', 422);
                        }

                        $wallet = $ledger->getOrCreateUserWallet((int) $user->id);
                        $walletBalance = (int) ($wallet->balance ?? 0);

                        if ($walletBalance < $amountInt) {
                            return $this->fail('Saldo wallet tidak cukup', 422, [
                                'payment_method' => 'wallet',
                                'wallet_balance' => $walletBalance,
                                'required_amount' => $amountInt,
                                'shortfall' => max(0, $amountInt - $walletBalance),
                            ]);
                        }

                        $ledger->purchase(
                            (int) $user->id,
                            $amountInt,
                            'PAY ORDER ' . (string) $locked->invoice_number
                        );

                        $locked->payment_gateway_code = 'wallet';
                        $locked->gateway_fee_percent = 0;
                        $locked->gateway_fee_amount = 0;
                        $locked->status = \App\Enums\OrderStatus::PAID->value;
                        $locked->save();

                        $payment = \App\Models\Payment::query()->firstOrNew([
                            'order_id' => (int) $locked->id,
                        ]);

                        $payment->order_id = (int) $locked->id;
                        $payment->gateway_code = 'wallet';
                        $payment->external_id = (string) $locked->invoice_number;
                        $payment->amount = (float) $locked->amount;
                        $payment->status = \App\Enums\PaymentStatus::PAID->value;
                        $payment->raw_callback = [
                            'source' => 'wallet',
                            'paid_at' => now()->toDateTimeString(),
                        ];
                        $payment->save();
                        $this->clearUserCartForOrderSource($locked, (int) $user->id);

                        return [
                            'order_id' => (int) $locked->id,
                            'payment_id' => (int) $payment->id,
                        ];
                    });

                    if ($result instanceof \Illuminate\Http\JsonResponse) {
                        return $result;
                    }

                    $queued = $this->dispatchPaidOrderJobOnce(
                        (int) $result['order_id'],
                        'wallet_paid',
                        (string) ($order->invoice_number ?? '')
                    );

                    $freshOrder = Order::query()
                        ->with(['items.product', 'product', 'payment', 'vouchers'])
                        ->find((int) $result['order_id']);

                    return $this->ok([
                        'method' => 'wallet',
                        'status' => 'paid',
                        'processing' => [
                            'queued' => $queued,
                            'next_step' => 'fulfillment_and_invoice',
                        ],
                        'order' => $freshOrder,
                    ]);
                } catch (\Illuminate\Validation\ValidationException $e) {
                    return $this->fail($e->getMessage(), 422, [
                        'errors' => $e->errors(),
                        'payment_method' => 'wallet',
                    ]);
                } catch (\Throwable $e) {
                    Log::error('ORDER WALLET PAYMENT FAILED', [
                        'order_id' => (int) $order->id,
                        'user_id' => (int) $user->id,
                        'error' => $e->getMessage(),
                    ]);

                    try { $licenseStockService->releaseByOrder((int) $order->id); } catch (\Throwable $ignored) {}
                    return $this->fail($e->getMessage(), 422);
                }
            }

            $gateway = $gatewayManager->resolveActiveByCodeOrAlias($selected, 'order');
            if (!$gateway) {
                return $this->fail('Payment gateway tidak tersedia atau tidak aktif', 422);
            }

            $prepared = DB::transaction(function () use ($order, $user, $gateway, $licenseStockService) {
                $locked = Order::query()
                    ->where('id', (int) $order->id)
                    ->where('user_id', (int) $user->id)
                    ->with(['items.product', 'product', 'payment', 'vouchers'])
                    ->lockForUpdate()
                    ->first();

                if (!$locked) {
                    return $this->fail('Order tidak ditemukan', 404);
                }

                $currentStatus = (string) ($locked->status?->value ?? $locked->status);
                if (in_array($currentStatus, [
                    \App\Enums\OrderStatus::PAID->value,
                    \App\Enums\OrderStatus::FULFILLED->value,
                    \App\Enums\OrderStatus::REFUNDED->value,
                ], true)) {
                    return $this->fail('Order sudah diproses pembayaran', 409);
                }

                if ($stockError = $this->stockErrorResponseForOrder($locked)) {
                    return $stockError;
                }

                $this->reserveStockForOrder($locked, $licenseStockService);

                $baseAmount = (float) $locked->amount;
                $fee = $this->resolveGatewayFee($gateway, $baseAmount);
                $grossAmount = round($baseAmount + (float) $fee['amount'], 2);

                $locked->payment_gateway_code = $gateway->code;
                $locked->gateway_fee_percent = (float) $fee['percent'];
                $locked->gateway_fee_amount = (float) $fee['amount'];
                $locked->save();

                $payment = \App\Models\Payment::query()->firstOrNew([
                    'order_id' => (int) $locked->id,
                ]);

                $existingRaw = is_array($payment->raw_callback) ? $payment->raw_callback : [];

                $payment->order_id = (int) $locked->id;
                $payment->gateway_code = $gateway->code;
                $payment->external_id = (string) ($payment->external_id ?: $locked->invoice_number);
                $payment->amount = (float) $grossAmount;
                $payment->status = \App\Enums\PaymentStatus::INITIATED->value;
                $payment->raw_callback = array_merge($existingRaw, [
                    'gateway' => $gateway->code,
                    'initiated_at' => now()->toDateTimeString(),
                ]);
                $payment->save();
                $this->clearUserCartForOrderSource($locked, (int) $user->id);

                return [
                    'order_id' => (int) $locked->id,
                    'gross_amount' => (float) $grossAmount,
                    'fee' => $fee,
                    'gateway' => $gateway,
                    'order_snapshot' => $locked->fresh(['items.product', 'product', 'payment', 'vouchers']),
                ];
            });

            if ($prepared instanceof \Illuminate\Http\JsonResponse) {
                return $prepared;
            }

            $init = $gatewayManager->driverFor($gateway)->createOrderPayment(
                $gateway,
                $prepared['order_snapshot'],
                [
                    'user' => $user,
                    'gross_amount' => $prepared['gross_amount'],
                ]
            );

            return DB::transaction(function () use ($order, $user, $gateway, $prepared, $init) {
                $locked = Order::query()
                    ->where('id', (int) $order->id)
                    ->where('user_id', (int) $user->id)
                    ->with(['items.product', 'product', 'payment', 'vouchers'])
                    ->lockForUpdate()
                    ->first();

                if (!$locked) {
                    return $this->fail('Order tidak ditemukan', 404);
                }

                $payment = \App\Models\Payment::query()
                    ->where('order_id', (int) $locked->id)
                    ->lockForUpdate()
                    ->firstOrNew([
                        'order_id' => (int) $locked->id,
                    ]);

                $currentStatus = (string) ($locked->status?->value ?? $locked->status);
                $isFinalOrderState = in_array($currentStatus, [
                    \App\Enums\OrderStatus::PAID->value,
                    \App\Enums\OrderStatus::FULFILLED->value,
                    \App\Enums\OrderStatus::REFUNDED->value,
                ], true);

                if (!($init['success'] ?? false)) {
                    if (!$isFinalOrderState) {
                        $payment->order_id = (int) $locked->id;
                        $payment->gateway_code = $gateway->code;
                        $payment->external_id = (string) ($payment->external_id ?: $locked->invoice_number);
                        $payment->amount = (float) $prepared['gross_amount'];
                        $payment->status = \App\Enums\PaymentStatus::FAILED->value;
                        $payment->raw_callback = ['init' => $init['payload'] ?? $init];
                        $payment->save();
                    }

                    return $this->fail((string) ($init['message'] ?? 'Gagal membuat payment'), 422);
                }

                if (!$isFinalOrderState) {
                    $payment->order_id = (int) $locked->id;
                    $payment->gateway_code = $gateway->code;
                    $payment->external_id = (string) ($init['external_id'] ?? $locked->invoice_number);
                    $payment->amount = (float) $prepared['gross_amount'];
                    $payment->status = \App\Enums\PaymentStatus::PENDING->value;
                    $payment->raw_callback = ['init' => $init['payload'] ?? $init];
                    $payment->save();
                }

                return $this->ok([
                    'method' => $gateway->code,
                    'gateway' => [
                        'code' => $gateway->code,
                        'name' => $gateway->name,
                        'provider' => $gateway->provider,
                        'driver' => $gateway->driver,
                        'sandbox_mode' => (bool) $gateway->sandbox_mode,
                    ],
                    'order' => $locked->fresh()->load(['items.product', 'product', 'payment', 'vouchers']),
                    'payment' => $payment->fresh(),
                    'payment_gateway_summary' => [
                        'fee_type' => $prepared['fee']['type'],
                        'fee_percent' => (float) $prepared['fee']['percent'],
                        'fee_amount' => (float) $prepared['fee']['amount'],
                        'total_payable' => (float) $prepared['gross_amount'],
                    ],
                    'payment_payload' => [
                        'reference' => $init['reference'] ?? $payment->external_id,
                        'redirect_url' => $init['redirect_url'] ?? null,
                        'snap_token' => $init['snap_token'] ?? null,
                        'mode' => $init['mode'] ?? ($gateway->sandbox_mode ? 'sandbox' : 'production'),
                        'payload' => $init['payload'] ?? null,
                    ],
                ]);
            });
        } catch (\Throwable $e) {
            try { $licenseStockService->releaseByOrder((int) $order->id); } catch (\Throwable $ignored) {}
            return $this->fail($e->getMessage(), 422);
        } finally {
            try {
                $lock->release();
            } catch (\Throwable $ignored) {
            }
        }
    }

    // =========================
    // GET /api/v1/orders/{id}/payments
    // =========================
    public function paymentStatus(Request $request, string $id)
    {
        $user = $request->user();
        $orderId = (int) $id;

        $cacheKey = sprintf('order:%d:user:%d:payment-status', $orderId, (int) $user->id);

        $payload = RuntimeCache::remember($cacheKey, 5, function () use ($orderId, $user) {
            $order = Order::query()
                ->select([
                    'id',
                    'user_id',
                    'invoice_number',
                    'status',
                    'amount',
                    'gateway_fee_percent',
                    'gateway_fee_amount',
                ])
                ->with([
                    'items' => function ($query) {
                        $query->select(['id', 'order_id', 'product_id', 'qty', 'unit_price', 'line_subtotal', 'unit_profit', 'line_profit', 'product_name', 'product_slug'])
                            ->with(['product:id,name,slug']);
                    },
                    'payment' => function ($query) {
                        $query->select([
                            'id',
                            'order_id',
                            'gateway_code',
                            'external_id',
                            'amount',
                            'status',
                            'created_at',
                            'updated_at',
                        ]);
                    },
                ])
                ->where('id', $orderId)
                ->where('user_id', (int) $user->id)
                ->first();

            if (!$order) {
                return null;
            }

            $status = (string) ($order->status?->value ?? $order->status);

            return [
                'order_id' => (int) $order->id,
                'invoice_number' => (string) $order->invoice_number,
                'order_status' => $status,
                'amount' => (string) $order->amount,
                'gateway_fee_percent' => (float) ($order->gateway_fee_percent ?? 0),
                'gateway_fee_amount' => (string) ($order->gateway_fee_amount ?? '0.00'),
                'total_payable_gateway' => (string) ((float) $order->amount + (float) ($order->gateway_fee_amount ?? 0)),
                'payment' => $order->payment,
                'items' => $order->items,
            ];
        });

        if (!$payload) {
            return $this->fail('Order tidak ditemukan', 404);
        }

        return $this->ok($payload);
    }

    private function syncExpiredCreatedOrdersForUser(int $userId): void
    {
        $cutoff = now()->subHour();

        Order::query()
            ->where('user_id', $userId)
            ->where('status', OrderStatus::CREATED->value)
            ->where('created_at', '<=', $cutoff)
            ->select('id')
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($userId) {
                foreach ($rows as $row) {
                    DB::transaction(function () use ($row, $userId) {
                        $lockedOrder = Order::query()
                            ->where('user_id', $userId)
                            ->with('payment')
                            ->lockForUpdate()
                            ->find($row->id);

                        if (! $lockedOrder) {
                            return;
                        }

                        $currentOrderStatus = (string) ($lockedOrder->status?->value ?? $lockedOrder->status);

                        if ($currentOrderStatus !== OrderStatus::CREATED->value) {
                            return;
                        }

                        $lockedOrder->status = OrderStatus::CANCELLED->value;
                        $lockedOrder->save();

                        if ($lockedOrder->payment) {
                            $currentPaymentStatus = (string) ($lockedOrder->payment->status?->value ?? $lockedOrder->payment->status);

                            if (in_array($currentPaymentStatus, [
                                PaymentStatus::INITIATED->value,
                                PaymentStatus::PENDING->value,
                            ], true)) {
                                $lockedOrder->payment->status = PaymentStatus::EXPIRED->value;
                                $lockedOrder->payment->save();
                            }
                        }
                    });
                }
            });
    }

    private function displayStatusForUser(string $status): string
    {
        $normalized = strtolower(trim($status));

        if ($normalized === OrderStatus::CREATED->value) {
            return OrderStatus::CANCELLED->value;
        }

        return $normalized;
    }


    private function hasTable(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (\Throwable $e) {
            Log::warning('history_orders_table_check_failed', [
                'table' => $table,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function historyRelations(bool $minimal = false): array
    {
        $relations = [
            'payment:id,order_id,gateway_code,external_id,amount,status,created_at',
            'product:id,category_id,subcategory_id,name,slug',
            'product.category:id,name,slug',
            'product.subcategory:id,category_id,name,slug',
        ];

        if (! $minimal && $this->hasTable('order_items')) {
            $relations['items'] = function ($q) {
                $q->select([
                    'id',
                    'order_id',
                    'product_id',
                    'qty',
                    'unit_price',
                    'line_subtotal',
                    'product_name',
                    'product_slug',
                ])->with([
                    'product:id,category_id,subcategory_id,name,slug',
                    'product.category:id,name,slug',
                    'product.subcategory:id,category_id,name,slug',
                ]);
            };
        }

        if (! $minimal && $this->hasTable('order_vouchers') && $this->hasTable('vouchers')) {
            $relations[] = 'vouchers:id,code,type,value';
        }

        if (! $minimal && $this->hasTable('order_discount_campaigns') && $this->hasTable('discount_campaigns')) {
            $relations[] = 'discountCampaigns:id,name,type,value';
        }

        if (! $minimal && $this->hasTable('deliveries')) {
            $relations[] = 'deliveries:id,order_id,license_id,delivery_mode,emailed_at,revealed_at,created_at';

            if ($this->hasTable('licenses')) {
                $relations[] = 'deliveries.license:id,product_id,license_key,data_other,note,status,delivered_at,sold_at,updated_at';
            }
        }

        return $relations;
    }

    private function buildHistoryOrderQuery(int $userId, bool $minimal = false): Builder
    {
        return Order::query()
            ->where('user_id', $userId)
            ->with($this->historyRelations($minimal))
            ->latest('id');
    }

    private function applyHistoryFilters(Builder $query, array $filters, bool $minimal = false): Builder
    {
        $status = trim((string) ($filters['status'] ?? ''));
        $invoice = trim((string) ($filters['invoice'] ?? ''));
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;
        $product = trim((string) ($filters['product'] ?? ''));
        $category = trim((string) ($filters['category'] ?? ''));
        $supportsOrderItems = ! $minimal && $this->hasTable('order_items');

        if ($status !== '') {
            $query->where('status', $status);
        }

        if ($invoice !== '') {
            $query->where('invoice_number', 'like', "%{$invoice}%");
        }

        if ($dateFrom) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        if ($product !== '') {
            $query->where(function ($q) use ($product, $supportsOrderItems) {
                if ($supportsOrderItems) {
                    $q->whereHas('items', function ($item) use ($product) {
                        $item->where('product_name', 'like', "%{$product}%")
                            ->orWhereHas('product', function ($prod) use ($product) {
                                $prod->where('name', 'like', "%{$product}%")
                                    ->orWhere('slug', 'like', "%{$product}%");
                            });
                    })->orWhereHas('product', function ($prod) use ($product) {
                        $prod->where('name', 'like', "%{$product}%")
                            ->orWhere('slug', 'like', "%{$product}%");
                    });

                    return;
                }

                $q->whereHas('product', function ($prod) use ($product) {
                    $prod->where('name', 'like', "%{$product}%")
                        ->orWhere('slug', 'like', "%{$product}%");
                });
            });
        }

        if ($category !== '') {
            $query->where(function ($q) use ($category, $supportsOrderItems) {
                if ($supportsOrderItems) {
                    $q->whereHas('items.product.category', function ($cat) use ($category) {
                        $cat->where('name', 'like', "%{$category}%")
                            ->orWhere('slug', 'like', "%{$category}%");
                    })->orWhereHas('product.category', function ($cat) use ($category) {
                        $cat->where('name', 'like', "%{$category}%")
                            ->orWhere('slug', 'like', "%{$category}%");
                    });

                    return;
                }

                $q->whereHas('product.category', function ($cat) use ($category) {
                    $cat->where('name', 'like', "%{$category}%")
                        ->orWhere('slug', 'like', "%{$category}%");
                });
            });
        }

        return $query;
    }

    private function transformHistoryOrder(Order $order): Order
    {
        $items = $order->relationLoaded('items') ? collect($order->getRelation('items') ?? []) : collect();
        $deliveries = $order->relationLoaded('deliveries') ? collect($order->getRelation('deliveries') ?? []) : collect();
        $payment = $order->relationLoaded('payment') ? $order->getRelation('payment') : null;
        $product = $order->relationLoaded('product') ? $order->getRelation('product') : $order->product;
        $vouchers = $order->relationLoaded('vouchers') ? collect($order->getRelation('vouchers') ?? []) : collect();
        $discountCampaigns = $order->relationLoaded('discountCampaigns') ? collect($order->getRelation('discountCampaigns') ?? []) : collect();

        $itemDetails = $items->map(function ($item) {
            $product = $item->relationLoaded('product') ? $item->getRelation('product') : $item->product;
            $category = $product?->relationLoaded('category') ? $product->getRelation('category') : $product?->category;
            $subcategory = $product?->relationLoaded('subcategory') ? $product->getRelation('subcategory') : $product?->subcategory;

            return [
                'order_item_id' => (int) $item->id,
                'product_id' => $product?->id ? (int) $product->id : null,
                'product' => $item->product_name ?: $product?->name,
                'product_slug' => $item->product_slug ?: $product?->slug,
                'category' => $category?->name,
                'subcategory' => $subcategory?->name,
                'qty' => (int) ($item->qty ?? 0),
                'unit_price' => (float) ($item->unit_price ?? 0),
                'line_subtotal' => (float) ($item->line_subtotal ?? 0),
            ];
        })->values();

        if ($itemDetails->isEmpty() && $product) {
            $productCategory = $product?->relationLoaded('category') ? $product->getRelation('category') : $product?->category;
            $productSubcategory = $product?->relationLoaded('subcategory') ? $product->getRelation('subcategory') : $product?->subcategory;

            $itemDetails = collect([[
                'order_item_id' => null,
                'product_id' => $product?->id ? (int) $product->id : null,
                'product' => $product?->name,
                'product_slug' => $product?->slug,
                'category' => $productCategory?->name,
                'subcategory' => $productSubcategory?->name,
                'qty' => (int) ($order->qty ?? 0),
                'unit_price' => (float) ((int) ($order->qty ?? 0) > 0
                    ? ((float) ($order->subtotal ?? $order->amount ?? 0) / max(1, (int) $order->qty))
                    : (float) ($order->amount ?? 0)),
                'line_subtotal' => (float) ($order->subtotal ?? $order->amount ?? 0),
            ]]);
        }

        $licenseDetails = $deliveries->map(function ($delivery) {
            $license = $delivery->relationLoaded('license') ? $delivery->getRelation('license') : $delivery->license;

            return [
                'delivery_id' => (int) $delivery->id,
                'delivery_mode' => $delivery->delivery_mode,
                'emailed_at' => $delivery->emailed_at,
                'revealed_at' => $delivery->revealed_at,
                'license_id' => $license?->id ? (int) $license->id : null,
                'license_key' => $license?->license_key,
                'data_other' => $license?->data_other,
                'note' => $license?->note,
                'status' => $license?->status,
                'delivered_at' => $license?->delivered_at,
                'sold_at' => $license?->sold_at,
            ];
        })->values();

        $categories = $itemDetails->pluck('category')->filter()->unique()->values();
        $rawStatus = (string) ($order->status?->value ?? $order->status ?? '');
        $displayStatus = $this->displayStatusForUser($rawStatus);
        $voucherDiscountTotal = (float) $vouchers->sum(function ($voucher) {
            return (float) ($voucher->pivot->discount_amount ?? 0);
        });
        $campaignDiscountTotal = (float) $discountCampaigns->sum(function ($campaign) {
            return (float) ($campaign->pivot->discount_amount ?? 0);
        });

        $order->setAttribute('display_status', $displayStatus);
        $order->setAttribute('display_status_label', strtoupper($displayStatus));
        $order->setAttribute('payment_reference', $payment?->external_id);
        $order->setAttribute('transaction_datetime', $order->created_at?->timezone('Asia/Jakarta')->format(\DateTimeInterface::ATOM));
        $order->setAttribute('payment_datetime', $payment?->created_at?->timezone('Asia/Jakarta')->format(\DateTimeInterface::ATOM));
        $order->setAttribute('total_item_qty', (int) $itemDetails->sum(fn ($row) => (int) ($row['qty'] ?? 0)));
        $order->setAttribute('item_details', $itemDetails->values());
        $order->setAttribute('license_details', $licenseDetails);
        $order->setAttribute('history_summary', [
            'invoice' => (string) ($order->invoice_number ?? ''),
            'waktu' => $order->created_at,
            'harga' => (float) ($order->amount ?? 0),
            'subtotal' => (float) ($order->subtotal ?? 0),
            'discount_total' => (float) ($order->discount_total ?? 0),
            'voucher_discount_total' => $voucherDiscountTotal,
            'campaign_discount_total' => $campaignDiscountTotal,
            'tax_percent' => (int) ($order->tax_percent ?? 0),
            'tax_amount' => (float) ($order->tax_amount ?? 0),
            'gateway_fee_percent' => (float) ($order->gateway_fee_percent ?? 0),
            'gateway_fee_amount' => (float) ($order->gateway_fee_amount ?? 0),
            'total_paid' => (float) ($order->amount ?? 0),
            'payment_gateway_code' => (string) ($order->payment_gateway_code ?? $payment?->gateway_code ?? ''),
            'payment_reference' => (string) ($payment?->external_id ?? ''),
            'total_item_qty' => (int) $itemDetails->sum(fn ($row) => (int) ($row['qty'] ?? 0)),
            'categories' => $categories,
            'items' => $itemDetails->values(),
        ]);

        return $order;
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $this->syncExpiredCreatedOrdersForUser((int) $user->id);

        $perPage = max(1, min((int) $request->query('per_page', 10), 100));
        $filters = [
            'status' => $request->query('status', ''),
            'invoice' => $request->query('invoice_number') ?: $request->query('invoice') ?: '',
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'product' => $request->query('product', ''),
            'category' => $request->query('category', ''),
        ];

        try {
            $query = $this->applyHistoryFilters(
                $this->buildHistoryOrderQuery((int) $user->id),
                $filters
            );

            $data = $query->paginate($perPage);
        } catch (\Throwable $e) {
            Log::error('history_orders_primary_query_failed', [
                'user_id' => (int) $user->id,
                'error' => $e->getMessage(),
            ]);

            $query = $this->applyHistoryFilters(
                $this->buildHistoryOrderQuery((int) $user->id, true),
                $filters,
                true
            );

            $data = $query->paginate($perPage);
        }

        $data->getCollection()->transform(fn (Order $order) => $this->transformHistoryOrder($order));

        return $this->ok($data);
    }

    public function show(Request $request, string $id)
    {
        $user = $request->user();
        $this->syncExpiredCreatedOrdersForUser((int) $user->id);

        try {
            $order = $this->buildHistoryOrderQuery((int) $user->id)
                ->where('id', (int) $id)
                ->first();
        } catch (\Throwable $e) {
            Log::error('history_order_show_primary_failed', [
                'user_id' => (int) $user->id,
                'order_id' => (int) $id,
                'error' => $e->getMessage(),
            ]);

            $order = $this->buildHistoryOrderQuery((int) $user->id, true)
                ->where('id', (int) $id)
                ->first();
        }

        if (! $order) {
            return $this->fail('Order tidak ditemukan', 404);
        }

        return $this->ok($this->transformHistoryOrder($order));
    }

    public function cancel(Request $request, string $id)
    {
        $user = $request->user();

        try {
            return DB::transaction(function () use ($user, $id) {
                $lockedOrder = Order::query()
                    ->where('id', (int) $id)
                    ->where('user_id', (int) $user->id)
                    ->with(['payment'])
                    ->lockForUpdate()
                    ->first();

                if (! $lockedOrder) {
                    return $this->fail('Order tidak ditemukan', 404);
                }

                $orderStatus = (string) ($lockedOrder->status?->value ?? $lockedOrder->status);
                $paymentStatus = (string) ($lockedOrder->payment?->status?->value ?? $lockedOrder->payment?->status ?? '');

                if (in_array($orderStatus, [
                    OrderStatus::PAID->value,
                    OrderStatus::FULFILLED->value,
                    OrderStatus::REFUNDED->value,
                ], true) || in_array($paymentStatus, [
                    PaymentStatus::PAID->value,
                    PaymentStatus::REFUNDED->value,
                ], true)) {
                    return $this->fail('Order sudah dibayar, tidak bisa dicancel', 409);
                }

                if (in_array($orderStatus, [
                    OrderStatus::CANCELLED->value,
                    OrderStatus::FAILED->value,
                    OrderStatus::EXPIRED->value,
                ], true)) {
                    app(ReferralCommissionService::class)->invalidateOrderReferral((int) $lockedOrder->id, [
                        'source' => 'user_cancel_already_closed',
                        'order_status' => $orderStatus,
                    ]);

                    return $this->ok([
                        'cancelled' => true,
                        'order' => $lockedOrder->fresh(['payment']),
                    ]);
                }

                $lockedOrder->status = OrderStatus::CANCELLED->value;
                $lockedOrder->save();

                if ($lockedOrder->payment) {
                    $currentPaymentStatus = (string) ($lockedOrder->payment->status?->value ?? $lockedOrder->payment->status);

                    if (in_array($currentPaymentStatus, [
                        PaymentStatus::INITIATED->value,
                        PaymentStatus::PENDING->value,
                    ], true)) {
                        $lockedOrder->payment->status = PaymentStatus::EXPIRED->value;
                        $lockedOrder->payment->save();
                    }
                }

                app(ReferralCommissionService::class)->invalidateOrderReferral((int) $lockedOrder->id, [
                    'source' => 'user_cancel',
                    'order_status' => OrderStatus::CANCELLED->value,
                ]);

                return $this->ok([
                    'cancelled' => true,
                    'order' => $lockedOrder->fresh(['payment']),
                ]);
            });
        } catch (\Throwable $e) {
            Log::error('ORDER CANCEL FAILED', [
                'order_id' => (int) $id,
                'user_id' => (int) $user->id,
                'error' => $e->getMessage(),
            ]);

            return $this->fail($e->getMessage(), 422);
        }
    }
}
