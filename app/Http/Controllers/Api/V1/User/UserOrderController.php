<?php

namespace App\Http\Controllers\Api\V1\User;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
        $row = Setting::query()
            ->where('group', 'payment')
            ->where('key', 'fee_percent')
            ->first();

        if (!$row) return 0.0;

        $val = $row->value;

        if (is_string($val)) {
            $decoded = json_decode($val, true);
            if (json_last_error() === JSON_ERROR_NONE) $val = $decoded;
        }

        if (is_array($val)) {
            $v = $val['percent'] ?? $val['value'] ?? 0;
            return is_numeric($v) ? (float) $v : 0.0;
        }

        if (is_numeric($val)) return (float) $val;

        return 0.0;
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
            // ✅ REFERRAL DISCOUNT (NULL-SAFE)
            // =========================
            $referralDiscount = 0.0;
            $referrerId = null;

            $settings = ReferralSetting::current();
            if ($settings && $settings->isActiveNow()) {

                $relation = Referral::query()
                    ->where('user_id', (int) $user->id)
                    ->first();

                if ($relation && $relation->locked_at) {

                    // minimal order
                    if ((int)$settings->min_order_amount <= 0 || (float)$subtotal >= (float)$settings->min_order_amount) {

                        // limit penggunaan per user (pending/valid)
                        $usedByUser = ReferralTransaction::query()
                            ->where('user_id', (int) $user->id)
                            ->whereIn('status', ['pending', 'valid'])
                            ->count();

                        if ((int)$settings->max_uses_per_user <= 0 || $usedByUser < (int)$settings->max_uses_per_user) {

                            // hitung diskon
                            if ($settings->discount_type === 'fixed') {
                                $referralDiscount = (float) ((int)$settings->discount_value);
                            } else { // percent
                                $referralDiscount = (float) floor((float)$subtotal * ((int)$settings->discount_value) / 100);
                            }

                            // max diskon
                            if ((int)$settings->discount_max_amount > 0) {
                                $referralDiscount = min($referralDiscount, (float)((int)$settings->discount_max_amount));
                            }

                            // tidak boleh lebih dari subtotal
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

            // ✅ tax dari setting
            $taxPercent = $this->getTaxPercent();
            $taxAmount = 0.0;
            if ($taxPercent > 0) {
                $taxAmount = round((float)$subtotal * ((float)$taxPercent / 100), 2);
            }

            // ✅ base amount (wallet pakai ini)
            $amount = (float) max(0, ((float)$subtotal + (float)$taxAmount) - (float)$discountTotal);

            // ✅ fee gateway (midtrans customer bayar ini tambahan)
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

            // attach voucher pivot (nilai diskon voucher saja)
            if ($voucher) {
                $order->vouchers()->syncWithoutDetaching([
                    $voucher->id => ['discount_amount' => (float) $voucherDiscount],
                ]);
            }

            // attach campaign pivot
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

    // =========================
    // POST /api/v1/orders/{id}/payments
    // =========================
    public function createPayment(
        Request $request,
        string $id,
        MidtransService $midtrans,
        LedgerService $ledger,
        OrderFulfillmentService $fulfillment
    ) {
        $user = $request->user();

        $v = $request->validate([
            'method' => ['required', 'in:midtrans,wallet'],
        ]);

        $order = Order::query()
            ->where('id', (int) $id)
            ->where('user_id', (int) $user->id)
            ->with(['items.product', 'product', 'payment', 'vouchers'])
            ->first();

        if (!$order) return $this->fail('Order tidak ditemukan', 404);

        $status = (string) ($order->status?->value ?? $order->status);
        if (in_array($status, [
            OrderStatus::PAID->value,
            OrderStatus::FULFILLED->value,
            OrderStatus::REFUNDED->value
        ], true)) {
            return $this->fail('Order sudah diproses pembayaran', 409);
        }

        // ===== WALLET =====
        if ($v['method'] === 'wallet') {
            try {
                return DB::transaction(function () use ($order, $user, $ledger, $fulfillment) {

                    $locked = Order::query()
                        ->where('id', (int) $order->id)
                        ->lockForUpdate()
                        ->first();

                    $curStatus = (string) ($locked->status?->value ?? $locked->status);

                    if (in_array($curStatus, [OrderStatus::PAID->value, OrderStatus::FULFILLED->value], true)) {
                        return $this->ok([
                            'method' => 'wallet',
                            'already_paid' => true,
                            'order' => $locked->fresh()->load(['items.product','product','payment','vouchers','deliveries.license']),
                        ]);
                    }

                    $amountInt = (int) round((float) $locked->amount);
                    if ($amountInt <= 0) return $this->fail('Amount invalid', 422);

                    $ledger->purchase((int) $user->id, $amountInt, 'PAY ORDER ' . $locked->invoice_number);

                    $payment = $locked->payment;
                    if (!$payment) {
                        $payment = Payment::create([
                            'order_id' => (int) $locked->id,
                            'gateway_code' => 'wallet',
                            'external_id' => null,
                            'amount' => (float) $locked->amount,
                            'status' => PaymentStatus::PAID->value,
                            'raw_callback' => ['method' => 'wallet'],
                        ]);
                    } else {
                        $payment->update([
                            'gateway_code' => 'wallet',
                            'amount' => (float) $locked->amount,
                            'status' => PaymentStatus::PAID->value,
                            'raw_callback' => array_merge((array) ($payment->raw_callback ?? []), ['method' => 'wallet']),
                        ]);
                    }

                    $locked->update([
                        'status' => OrderStatus::PAID->value,
                        'payment_gateway_code' => 'wallet',
                    ]);

                    $res = $fulfillment->fulfillPaidOrder($locked->fresh());

                    if (!($res['ok'] ?? false)) {
                        throw new \RuntimeException($res['message'] ?? 'Fulfillment failed');
                    }

                    $locked->update(['status' => OrderStatus::FULFILLED->value]);

                    $this->dispatchInvoiceEmailAfterCommit(
                        (int) $locked->id,
                        'wallet_paid',
                        (string) $locked->invoice_number
                    );

                    return $this->ok([
                        'method' => 'wallet',
                        'payment' => $payment->fresh(),
                        'order' => $locked->fresh()->load(['items.product','product','vouchers','deliveries.license','payment']),
                        'fulfillment' => $res,
                    ]);
                });
            } catch (\Illuminate\Validation\ValidationException $e) {
                return $this->fail('Validation failed', 422, $e->errors());
            } catch (\Throwable $e) {
                return $this->fail('Wallet payment failed', 500, $e->getMessage());
            }
        }

        // ===== MIDTRANS =====
        try {
            return DB::transaction(function () use ($order, $user, $midtrans) {

                $locked = Order::query()
                    ->where('id', (int) $order->id)
                    ->lockForUpdate()
                    ->first();

                // ✅ hitung gross = base + fee
                $baseAmount = (float) $locked->amount;

                $feeAmount = (float) ($locked->gateway_fee_amount ?? 0);
                if ($feeAmount <= 0) {
                    $feePercent = $this->getGatewayFeePercent();
                    if ($feePercent > 0) {
                        $feeAmount = round((float)$baseAmount * ((float)$feePercent / 100), 2);
                        $locked->update([
                            'gateway_fee_percent' => (float) $feePercent,
                            'gateway_fee_amount' => (float) $feeAmount,
                        ]);
                    }
                }

                $gross = (float) ($baseAmount + $feeAmount);

                $payment = $locked->payment;
                if (!$payment) {
                    $payment = Payment::create([
                        'order_id' => (int) $locked->id,
                        'gateway_code' => 'midtrans',
                        'external_id' => (string) $locked->invoice_number,
                        'amount' => (float) $gross, // ✅ gross
                        'status' => PaymentStatus::INITIATED->value,
                        'raw_callback' => null,
                    ]);
                } else {
                    $payment->update([
                        'gateway_code' => 'midtrans',
                        'external_id' => (string) $locked->invoice_number,
                        'amount' => (float) $gross,
                    ]);
                }

                $payload = [
                    'transaction_details' => [
                        'order_id' => (string) $locked->invoice_number,
                        'gross_amount' => (int) round($gross),
                    ],
                    'item_details' => [
                        [
                            'id' => (string) $locked->invoice_number,
                            'price' => (int) round($gross),
                            'quantity' => 1,
                            'name' => mb_substr('Growtech Order ' . (string) $locked->invoice_number, 0, 50),
                        ],
                    ],
                    'customer_details' => [
                        'first_name' => (string) ($user->name ?? 'Customer'),
                        'email' => (string) ($user->email ?? 'customer@example.com'),
                    ],
                ];

                $snap = $midtrans->createSnapTransaction($payload);

                $ok = (bool) ($snap['ok'] ?? false);
                if (!$ok) {
                    if (!empty($snap['token']) || !empty($snap['redirect_url'])) $ok = true;
                }

                if (!$ok) {
                    $payment->update([
                        'status' => PaymentStatus::FAILED->value,
                        'raw_callback' => $snap,
                    ]);
                    return $this->fail('Gagal membuat pembayaran Midtrans', 422, $snap);
                }

                $locked->update([
                    'status' => OrderStatus::PENDING->value,
                    'payment_gateway_code' => 'midtrans',
                ]);

                $payment->update([
                    'status' => PaymentStatus::PENDING->value,
                    'raw_callback' => $snap,
                ]);

                return $this->ok([
                    'method' => 'midtrans',
                    'order' => $locked->fresh()->load(['items.product', 'product', 'vouchers', 'payment']),
                    'payment' => $payment->fresh(),
                    'amounts' => [
                        'base' => (float) $baseAmount,
                        'fee' => (float) $feeAmount,
                        'gross' => (float) $gross,
                    ],
                    'snap' => [
                        'mode' => $snap['mode'] ?? null,
                        'token' => $snap['token'] ?? null,
                        'redirect_url' => $snap['redirect_url'] ?? null,
                    ],
                ]);
            });
        } catch (\Throwable $e) {
            return $this->fail('Midtrans payment init failed', 500, $e->getMessage());
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

    // =========================
    // GET /api/v1/orders
    // =========================
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

    // =========================
    // GET /api/v1/orders/{id}
    // =========================
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

    // =========================
    // POST /api/v1/orders/{id}/cancel
    // =========================
    public function cancel(Request $request, string $id)
    {
        $user = $request->user();

        $order = Order::query()
            ->where('id', (int) $id)
            ->where('user_id', (int) $user->id)
            ->first();

        if (!$order) return $this->fail('Order tidak ditemukan', 404);

        $status = (string) ($order->status?->value ?? $order->status);

        if (in_array($status, [OrderStatus::PAID->value, OrderStatus::FULFILLED->value], true)) {
            return $this->fail('Order sudah dibayar, tidak bisa dicancel', 409);
        }

        $order->update(['status' => OrderStatus::FAILED->value]);

        return $this->ok([
            'cancelled' => true,
            'order' => $order->fresh(),
        ]);
    }
}