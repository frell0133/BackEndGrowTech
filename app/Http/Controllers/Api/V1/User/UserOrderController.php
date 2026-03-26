<?php

namespace App\Http\Controllers\Api\V1\User;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
use App\Http\Controllers\Controller;
use App\Support\DispatchesInvoiceEmail;
use App\Models\ReferralSetting;
use App\Models\ReferralTransaction;
use App\Models\Referral;
use App\Jobs\ProcessPaidOrderJob;
use App\Models\License;
use Illuminate\Http\JsonResponse;

// ✅ NEW: service untuk komisi referral saat PAID (wallet/midtrans)
use App\Services\ReferralCommissionService;

class UserOrderController extends Controller
{
    use ApiResponse, DispatchesInvoiceEmail;

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

        // ✅ tier pricing
        $tierKey = (string) ($user->tier ?? 'member');
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
        if ($unitPrice <= 0) $unitPrice = (int) ($product->price ?? 0);

        if ($unitPrice <= 0) {
            return $this->fail('Harga product belum diset (tier_pricing/price kosong)', 422);
        }

        $subtotal = (float) ($unitPrice * $qty);

        return DB::transaction(function () use ($user, $product, $qty, $unitPrice, $subtotal, $v) {

            $discountTotal = 0.0;

            // =========================
            // ✅ CAMPAIGN DISCOUNT (category/subcategory/product)
            // =========================
            $computedItems = [[
                'product_id' => (int) $product->id,
                'category_id' => isset($product->category_id) ? (int) $product->category_id : null,
                'subcategory_id' => isset($product->subcategory_id) ? (int) $product->subcategory_id : null,
                'qty' => (int) $qty,
                'unit_price' => (float) $unitPrice,
                'line_subtotal' => (float) $subtotal,
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

                        $usedByUser = ReferralTransaction::query()
                            ->where('user_id', (int) $user->id)
                            ->whereIn('status', ['pending', 'valid'])
                            ->count();

                        if ((int)$settings->max_uses_per_user <= 0 || $usedByUser < (int)$settings->max_uses_per_user) {

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
                'unit_price' => (float) $unitPrice,
                'line_subtotal' => (float) $subtotal,
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

    private function availableLicenseStock(int $productId): int
    {
        return License::query()
            ->where('product_id', $productId)
            ->where('status', License::STATUS_AVAILABLE)
            ->count();
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
        \App\Services\Payments\PaymentGatewayManager $gatewayManager
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
                    $result = DB::transaction(function () use ($order, $user, $ledger) {
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

                        return [
                            'order_id' => (int) $locked->id,
                            'payment_id' => (int) $payment->id,
                        ];
                    });

                    if ($result instanceof \Illuminate\Http\JsonResponse) {
                        return $result;
                    }

                    $job = ProcessPaidOrderJob::dispatch(
                        (int) $result['order_id'],
                        'wallet_paid'
                    )->delay(now()->addSeconds(1));

                    if (method_exists($job, 'afterCommit')) {
                        $job->afterCommit();
                    }

                    $freshOrder = Order::query()
                        ->with(['items.product', 'product', 'payment', 'vouchers'])
                        ->find((int) $result['order_id']);

                    return $this->ok([
                        'method' => 'wallet',
                        'status' => 'paid',
                        'processing' => [
                            'queued' => true,
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

                    return $this->fail($e->getMessage(), 422);
                }
            }

            $gateway = $gatewayManager->resolveActiveByCodeOrAlias($selected, 'order');
            if (!$gateway) {
                return $this->fail('Payment gateway tidak tersedia atau tidak aktif', 422);
            }

            $prepared = DB::transaction(function () use ($order, $user, $gateway) {
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

        $order = Order::query()
            ->with(['items.product', 'payment', 'product', 'vouchers'])
            ->where('id', (int) $id)
            ->where('user_id', (int) $user->id)
            ->first();

        if (!$order) return $this->fail('Order tidak ditemukan', 404);

        $status = (string) ($order->status?->value ?? $order->status);

        return $this->ok([
            'order_id' => (int) $order->id,
            'invoice_number' => (string) $order->invoice_number,
            'order_status' => $status,
            'amount' => (string) $order->amount,
            'gateway_fee_percent' => (float) ($order->gateway_fee_percent ?? 0),
            'gateway_fee_amount' => (string) ($order->gateway_fee_amount ?? '0.00'),
            'total_payable_gateway' => (string) ((float) $order->amount + (float) ($order->gateway_fee_amount ?? 0)),
            'payment' => $order->payment,
            'items' => $order->items,
        ]);
    }

    public function index(Request $request)
    {
        $user = $request->user();

        $data = Order::query()
            ->where('user_id', (int) $user->id)
            ->with(['items.product', 'product', 'payment', 'vouchers'])
            ->latest('id')
            ->paginate((int) $request->query('per_page', 10));

        return $this->ok($data);
    }

    public function show(Request $request, string $id)
    {
        $user = $request->user();

        $order = Order::query()
            ->where('id', (int) $id)
            ->where('user_id', (int) $user->id)
            ->with(['items.product', 'product', 'payment', 'vouchers', 'deliveries.license'])
            ->first();

        if (!$order) return $this->fail('Order tidak ditemukan', 404);

        return $this->ok($order);
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

                if (!$lockedOrder) {
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

                if ($orderStatus === OrderStatus::FAILED->value) {
                    return $this->ok([
                        'cancelled' => true,
                        'order' => $lockedOrder->fresh(['payment']),
                    ]);
                }

                $lockedOrder->status = OrderStatus::FAILED->value;
                $lockedOrder->save();

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
