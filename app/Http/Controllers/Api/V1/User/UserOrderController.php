<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Voucher;
use App\Services\LedgerService;
use App\Services\MidtransService;
use App\Services\OrderFulfillmentService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UserOrderController extends Controller
{
    use ApiResponse;

    /**
     * POST /api/v1/orders
     * Body:
     * {
     *   "product_id": 1,
     *   "qty": 1,
     *   "voucher_code": "1212" // optional
     * }
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
        if (!$product) {
            return $this->fail('Product tidak ditemukan', 404);
        }

        // Optional: kalau di model ada is_active/is_published
        if (property_exists($product, 'is_active') && $product->is_active === false) {
            return $this->fail('Product tidak aktif', 422);
        }
        if (property_exists($product, 'is_published') && $product->is_published === false) {
            return $this->fail('Product belum dipublish', 422);
        }

        // ===== Ambil harga =====
        // Prioritas: tier_pricing[role] -> tier_pricing['member'] -> product->price
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

        if ($unitPrice <= 0) {
            // fallback ke kolom price (kalau ada)
            $unitPrice = (int) ($product->price ?? 0);
        }

        if ($unitPrice <= 0) {
            return $this->fail('Harga product belum diset (tier_pricing/price kosong)', 422);
        }

        $subtotal = (float) ($unitPrice * $qty);

        return DB::transaction(function () use ($user, $product, $qty, $subtotal, $v) {

            $discountTotal = 0.0;
            $voucher = null;

            // ===== Voucher optional =====
            if (!empty($v['voucher_code'])) {
                $code = strtoupper(trim((string) $v['voucher_code']));

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
                    return $this->fail('Subtotal belum memenuhi minimal pembelian voucher', 422);
                }

                // quota optional
                if ($voucher->quota !== null) {
                    $used = $voucher->orders()->count();
                    if ($used >= (int) $voucher->quota) {
                        return $this->fail('Kuota voucher sudah habis', 422);
                    }
                }

                // hitung diskon
                if ($voucher->type === 'percent') {
                    $discountTotal = (float) floor($subtotal * ((float) $voucher->value / 100));
                } else { // fixed
                    $discountTotal = (float) $voucher->value;
                }
                if ($discountTotal > $subtotal) $discountTotal = $subtotal;
            }

            $amount = (float) max(0, $subtotal - $discountTotal);

            // invoice unik
            $invoice = 'INV-' . now()->format('Ymd') . '-' . Str::upper(Str::random(8));

            $order = Order::create([
                'user_id' => (int) $user->id,
                'product_id' => (int) $product->id,
                'invoice_number' => $invoice,
                'status' => OrderStatus::CREATED->value,
                'qty' => (int) $qty,
                'subtotal' => (float) $subtotal,
                'discount_total' => (float) $discountTotal,
                'amount' => (float) $amount,
                'payment_gateway_code' => null,
            ]);

            // attach voucher ke pivot
            if ($voucher) {
                $order->vouchers()->syncWithoutDetaching([
                    $voucher->id => ['discount_amount' => (float) $discountTotal],
                ]);
            }

            return $this->ok([
                'order' => $order->fresh()->load(['product', 'vouchers']),
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
            ->with(['product', 'payment', 'vouchers'])
            ->first();

        if (!$order) {
            return $this->fail('Order tidak ditemukan', 404);
        }

        // kalau sudah paid/fulfilled/refunded, jangan bayar lagi
        $status = (string) ($order->status?->value ?? $order->status);
        if (in_array($status, [
            OrderStatus::PAID->value,
            OrderStatus::FULFILLED->value,
            OrderStatus::REFUNDED->value
        ], true)) {
            return $this->fail('Order sudah diproses pembayaran', 409);
        }

        // ===== WALLET PAYMENT =====
        if ($v['method'] === 'wallet') {
            try {
                return DB::transaction(function () use ($order, $user, $ledger, $fulfillment) {

                    // lock order supaya tidak double bayar (idempotent)
                    $locked = Order::query()
                        ->where('id', (int) $order->id)
                        ->lockForUpdate()
                        ->first();

                    $curStatus = (string) ($locked->status?->value ?? $locked->status);

                    if (in_array($curStatus, [
                        OrderStatus::PAID->value,
                        OrderStatus::FULFILLED->value,
                    ], true)) {
                        return $this->ok([
                            'method' => 'wallet',
                            'already_paid' => true,
                            'order' => $locked->fresh()->load(['product','payment','vouchers','deliveries.license']),
                        ]);
                    }

                    // debit saldo user
                    $amountInt = (int) round((float) $locked->amount);
                    if ($amountInt <= 0) {
                        return $this->fail('Amount invalid', 422);
                    }

                    // LedgerService::purchase biasanya akan throw ValidationException kalau saldo kurang
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

                    // order paid
                    $locked->update([
                        'status' => OrderStatus::PAID->value,
                        'payment_gateway_code' => 'wallet',
                    ]);

                    // fulfill (ambil license & buat deliveries)
                    $res = $fulfillment->fulfillPaidOrder($locked->fresh());

                    if (!($res['ok'] ?? false)) {
                        // rollback: biar debit wallet ikut batal jika fulfillment gagal
                        throw new \RuntimeException($res['message'] ?? 'Fulfillment failed');
                    }

                    // order fulfilled
                    $locked->update([
                        'status' => OrderStatus::FULFILLED->value,
                    ]);

                    return $this->ok([
                        'method' => 'wallet',
                        'payment' => $payment->fresh(),
                        'order' => $locked->fresh()->load(['product','vouchers','deliveries.license','payment']),
                        'fulfillment' => $res,
                    ]);
                });

            } catch (\Illuminate\Validation\ValidationException $e) {
                // saldo tidak cukup biasanya masuk sini
                return $this->fail('Validation failed', 422, $e->errors());
            } catch (\Throwable $e) {
                return $this->fail('Wallet payment failed', 500, $e->getMessage());
            }
        }

        // ===== MIDTRANS PAYMENT =====
        try {
            return DB::transaction(function () use ($order, $user, $midtrans) {

                // lock order biar idempotent
                $locked = Order::query()
                    ->where('id', (int) $order->id)
                    ->lockForUpdate()
                    ->first();

                // buat/reuse payment pending
                $payment = $locked->payment;
                if (!$payment) {
                    $payment = Payment::create([
                        'order_id' => (int) $locked->id,
                        'gateway_code' => 'midtrans',
                        'external_id' => (string) $locked->invoice_number, // order_id midtrans
                        'amount' => (float) $locked->amount,
                        'status' => PaymentStatus::INITIATED->value,
                        'raw_callback' => null,
                    ]);
                }

                $payload = [
                    'transaction_details' => [
                        'order_id' => (string) $locked->invoice_number,
                        'gross_amount' => (int) round((float) $locked->amount),
                    ],
                    'item_details' => [
                        [
                            'id' => (string) $locked->product_id,
                            'price' => (int) round((float) $locked->amount),
                            'quantity' => (int) ($locked->qty ?? 1),
                            'name' => mb_substr((string) ($locked->product?->name ?? 'Product'), 0, 50),
                        ],
                    ],
                    'customer_details' => [
                        'first_name' => (string) ($user->name ?? 'Customer'),
                        'email' => (string) ($user->email ?? 'customer@example.com'),
                    ],
                ];

                $snap = $midtrans->createSnapTransaction($payload);

                // kalau service kamu return structure lain, ini aman karena kita cek token/redirect_url juga
                $ok = (bool) ($snap['ok'] ?? false);
                if (!$ok) {
                    // fallback: kalau service kamu tidak ada 'ok' tapi ada token
                    if (!empty($snap['token']) || !empty($snap['redirect_url'])) {
                        $ok = true;
                    }
                }

                if (!$ok) {
                    $payment->update([
                        'status' => PaymentStatus::FAILED->value,
                        'raw_callback' => $snap,
                    ]);
                    return $this->fail('Gagal membuat pembayaran Midtrans', 422, $snap);
                }

                // update order & payment -> pending
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
                    'order' => $locked->fresh()->load(['product', 'vouchers', 'payment']),
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
     * (AMAN: hanya order milik user)
     */
    public function paymentStatus(Request $request, string $id)
    {
        $user = $request->user();

        $order = Order::query()
            ->with(['payment', 'product', 'vouchers'])
            ->where('id', (int) $id)
            ->where('user_id', (int) $user->id)
            ->first();

        if (!$order) {
            return $this->fail('Order tidak ditemukan', 404);
        }

        $status = (string) ($order->status?->value ?? $order->status);

        return $this->ok([
            'order_id' => (int) $order->id,
            'invoice_number' => (string) $order->invoice_number,
            'order_status' => $status,
            'amount' => (string) $order->amount,
            'payment' => $order->payment,
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
            ->with(['product', 'payment', 'vouchers'])
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
            ->with(['product', 'payment', 'vouchers', 'deliveries.license'])
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
