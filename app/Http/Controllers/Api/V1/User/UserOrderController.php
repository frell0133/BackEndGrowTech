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
use App\Services\LedgerService;
use App\Services\MidtransService;
use App\Services\OrderFulfillmentService;
use App\Support\ApiResponse;
use App\Jobs\SendInvoiceEmailJob;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Log;

class UserOrderController extends Controller
{
    use ApiResponse;

    /**
     * POST /api/v1/orders (BUY NOW)
     * Body:
     * {
     *   "product_id": 1,
     *   "qty": 1,
     *   "voucher_code": "1212" // optional
     * }
     *
     * NOTE:
     * - Untuk Opsi B (multi item), cart checkout pakai endpoint /cart/checkout
     * - Endpoint ini tetap dipakai untuk "Buy Now" single product
     */
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

        // Harga: tier_pricing[role] -> tier_pricing['member'] -> price
        $role = (string) ($user->role ?? 'member');
        $tier = (array) ($product->tier_pricing ?? []);
        $unitPrice = 0;

        if (!empty($tier)) {
            $unitPrice = (int) ($tier[$role] ?? 0);
            if ($unitPrice <= 0) $unitPrice = (int) ($tier['member'] ?? 0);
            if ($unitPrice <= 0) {
                $vals = array_values($tier);
                $unitPrice = (int) ($vals[0] ?? 0);
            }
        }
        if ($unitPrice <= 0) $unitPrice = (int) ($product->price ?? 0);
        if ($unitPrice <= 0) return $this->fail('Harga product belum diset (tier_pricing/price kosong)', 422);

        $subtotal = (float) ($unitPrice * $qty);

        return DB::transaction(function () use ($user, $product, $qty, $unitPrice, $subtotal, $v) {

            $discountTotal = 0.0;
            $voucher = null;

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
                    $used = $voucher->orders()->count();
                    if ($used >= (int) $voucher->quota) {
                        return $this->fail('Kuota voucher sudah habis', 422);
                    }
                }

                if ($voucher->type === 'percent') {
                    $discountTotal = (float) floor($subtotal * ((float) $voucher->value / 100));
                } else {
                    $discountTotal = (float) $voucher->value;
                }
                if ($discountTotal > $subtotal) $discountTotal = $subtotal;
            }

            $taxPercent = 0; // default (nanti bisa kamu samakan ke setting juga kalau mau)
            $taxAmount = 0.0;

            $amount = (float) max(0, ($subtotal + $taxAmount) - $discountTotal);

            $invoice = 'INV-' . now()->format('Ymd') . '-' . Str::upper(Str::random(8));

            // legacy fields masih kita isi untuk buy-now
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
                'amount' => (float) $amount,
                'payment_gateway_code' => null,
            ]);

            // ✅ buat order_items juga (supaya konsisten Opsi B)
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
                    $voucher->id => ['discount_amount' => (float) $discountTotal],
                ]);
            }

            return $this->ok([
                'order' => $order->fresh()->load(['items.product', 'product', 'vouchers']),
            ]);
        });
    }

    /**
     * POST /api/v1/orders/{id}/payments
     * Body:
     * { "method": "midtrans" } atau { "method": "wallet" }
     */
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

                    // debit saldo
                    $ledger->purchase((int) $user->id, $amountInt, 'PAY ORDER ' . $locked->invoice_number);

                    // payment record
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
                            'status' => PaymentStatus::PAID->value,
                            'raw_callback' => array_merge((array) ($payment->raw_callback ?? []), ['method' => 'wallet']),
                        ]);
                    }

                    $locked->update([
                        'status' => OrderStatus::PAID->value,
                        'payment_gateway_code' => 'wallet',
                    ]);

                    // fulfill multi-item
                    $res = $fulfillment->fulfillPaidOrder($locked->fresh());

                    if (!($res['ok'] ?? false)) {
                        throw new \RuntimeException($res['message'] ?? 'Fulfillment failed');
                    }

                    $locked->update(['status' => OrderStatus::FULFILLED->value]);
                    // ✅ kirim invoice email (async) setelah transaksi commit
                    DB::afterCommit(function () use ($locked) {
                        \Illuminate\Support\Facades\Log::info('INVOICE DISPATCH', [
                            'source' => 'wallet_paid',
                            'order_id' => (int) $locked->id,
                            'invoice_number' => $locked->invoice_number,
                        ]);

                        $job = SendInvoiceEmailJob::dispatch((int) $locked->id)->delay(now()->addSeconds(5));

                        if (method_exists($job, 'afterCommit')) {
                            $job->afterCommit();
                        }
                    });

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

                $payment = $locked->payment;
                if (!$payment) {
                    $payment = Payment::create([
                        'order_id' => (int) $locked->id,
                        'gateway_code' => 'midtrans',
                        'external_id' => (string) $locked->invoice_number,
                        'amount' => (float) $locked->amount,
                        'status' => PaymentStatus::INITIATED->value,
                        'raw_callback' => null,
                    ]);
                }

                // ✅ PALING AMAN: 1 item_details saja, match ke gross_amount
                $payload = [
                    'transaction_details' => [
                        'order_id' => (string) $locked->invoice_number,
                        'gross_amount' => (int) round((float) $locked->amount),
                    ],
                    'item_details' => [
                        [
                            'id' => (string) $locked->invoice_number,
                            'price' => (int) round((float) $locked->amount),
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

    /**
     * GET /api/v1/orders/{id}/payments
     */
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
            'payment' => $order->payment,
            'items' => $order->items,
        ]);
    }

    /**
     * GET /api/v1/orders
     */
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

    /**
     * GET /api/v1/orders/{id}
     */
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

    /**
     * POST /api/v1/orders/{id}/cancel
     */
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
