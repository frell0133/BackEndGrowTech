<?php

namespace App\Http\Controllers\Api\V1\Webhook;

use App\Http\Controllers\Controller;
use App\Models\WalletTopup;
use App\Models\ReferralSetting;
use App\Models\ReferralTransaction;
use App\Services\LedgerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// === ADJUST (kalau model order kamu beda) ===
use App\Models\Order; // pastikan model Order ada

class MidtransWebhookController extends Controller
{
    public function handle(Request $request, LedgerService $ledger)
    {
        $payload = $request->all();

        Log::info('MIDTRANS WEBHOOK HIT RAW', [
            'ip' => $request->ip(),
            'url' => $request->fullUrl(),
            'payload' => $payload,
        ]);

        // ===== 1) ambil field penting =====
        $orderId       = (string) ($payload['order_id'] ?? '');
        $statusCode    = (string) ($payload['status_code'] ?? '');
        $grossAmount   = (string) ($payload['gross_amount'] ?? '');
        $signatureKey  = (string) ($payload['signature_key'] ?? '');
        $transactionStatus = (string) ($payload['transaction_status'] ?? '');
        $fraudStatus       = (string) ($payload['fraud_status'] ?? '');
        $paymentType       = (string) ($payload['payment_type'] ?? '');

        if ($orderId === '' || $statusCode === '' || $grossAmount === '' || $signatureKey === '') {
            Log::warning('MIDTRANS WEBHOOK INVALID PAYLOAD', ['payload' => $payload]);
            return response()->json(['success' => true, 'ignored' => true, 'message' => 'Invalid payload (ignored)'], 200);
        }

        // ===== 3) server key =====
        $serverKey = (string) config('services.midtrans.server_key', env('MIDTRANS_SERVER_KEY', ''));
        if ($serverKey === '') {
            Log::error('MIDTRANS WEBHOOK SERVER KEY EMPTY', ['order_id' => $orderId]);
            return response()->json(['success' => true, 'ignored' => true, 'message' => 'Server key not configured (ignored)'], 200);
        }

        // ===== 4) verify signature =====
        $expected = hash('sha512', $orderId . $statusCode . $grossAmount . $serverKey);
        if (!hash_equals($expected, $signatureKey)) {
            Log::warning('MIDTRANS WEBHOOK INVALID SIGNATURE', [
                'order_id' => $orderId,
                'expected' => $expected,
                'got' => $signatureKey,
            ]);
            return response()->json(['success' => true, 'ignored' => true, 'message' => 'Invalid signature (ignored)'], 200);
        }

        // ===== 5) status paid? =====
        $isPaid =
            ($transactionStatus === 'settlement') ||
            ($transactionStatus === 'capture' && $fraudStatus === 'accept');

        $mappedStatus = match ($transactionStatus) {
            'settlement' => 'paid',
            'capture'    => $isPaid ? 'paid' : 'pending',
            'pending'    => 'pending',
            'deny'       => 'failed',
            'cancel'     => 'failed',
            'expire'     => 'expired',
            'refund'     => 'refunded',
            'partial_refund' => 'refunded',
            default      => 'pending',
        };

        /**
         * ==========================================================
         * A) HANDLE TOPUP (existing logic)
         * ==========================================================
         */
        $topup = WalletTopup::where('order_id', $orderId)->first();

        if ($topup) {
            try {
                DB::transaction(function () use ($topup, $payload, $mappedStatus, $isPaid, $orderId, $ledger) {

                    $lockedTopup = WalletTopup::where('id', $topup->id)->lockForUpdate()->first();

                    if (in_array($lockedTopup->status, ['paid', 'success', 'completed'], true)) {
                        Log::info('MIDTRANS WEBHOOK DUPLICATE TOPUP (ALREADY PAID)', [
                            'order_id' => $orderId,
                            'status' => $lockedTopup->status,
                        ]);
                        return;
                    }

                    $lockedTopup->midtrans_payload = $payload;
                    $lockedTopup->status = $mappedStatus;
                    $lockedTopup->save();

                    if (!$isPaid || $mappedStatus !== 'paid') {
                        return;
                    }

                    $userId = (int) $lockedTopup->user_id;
                    $amount = (int) $lockedTopup->amount;

                    DB::table('wallets')->where('user_id', $userId)->increment('balance', $amount);

                    $ledger->topup(
                        userId: $userId,
                        amount: $amount,
                        reference: $lockedTopup->order_id,
                        meta: [
                            'provider' => 'midtrans',
                            'transaction_status' => $payload['transaction_status'] ?? null,
                            'payment_type' => $payload['payment_type'] ?? null,
                            'transaction_id' => $payload['transaction_id'] ?? null,
                        ]
                    );

                    $lockedTopup->status = 'paid';
                    $lockedTopup->save();

                    Log::info('MIDTRANS TOPUP PAID -> WALLET CREDITED', [
                        'order_id' => $orderId,
                        'user_id' => $userId,
                        'amount' => $amount,
                    ]);
                });

                return response()->json(['success' => true, 'message' => 'OK (topup)'], 200);

            } catch (\Throwable $e) {
                Log::error('MIDTRANS TOPUP WEBHOOK ERROR', [
                    'order_id' => $orderId,
                    'err' => $e->getMessage(),
                ]);
                return response()->json(['success' => true, 'ignored' => true, 'message' => 'Topup internal error (ignored)'], 200);
            }
        }

        /**
         * ==========================================================
         * B) HANDLE ORDER PRODUCT + REFERRAL COMMISSION
         * ==========================================================
         *
         * Kalau bukan topup, kita coba cari Order.
         * ADJUST: sesuaikan kolom pencarian order kamu.
         * - Jika order kamu punya kolom "order_id" string (midtrans order id), gunakan itu.
         * - Jika order kamu pakai "code" / "invoice" / "order_number", sesuaikan.
         */
        $order = Order::query()
            // === ADJUST 1: ganti 'order_id' jika kolommu beda ===
            ->where('order_id', $orderId)
            ->first();

        if (!$order) {
            Log::warning('MIDTRANS WEBHOOK NO MATCH TOPUP/ORDER (IGNORED)', [
                'order_id' => $orderId,
                'transaction_status' => $transactionStatus,
                'payment_type' => $paymentType,
            ]);
            return response()->json(['success' => true, 'ignored' => true, 'message' => 'No matching topup/order (ignored)'], 200);
        }

        try {
            DB::transaction(function () use ($order, $payload, $mappedStatus, $isPaid, $orderId, $ledger) {

                // lock order biar idempotent
                $lockedOrder = Order::query()->where('id', $order->id)->lockForUpdate()->first();

                // === ADJUST 2: status field order kamu (paid/completed) ===
                if (in_array($lockedOrder->status, ['paid', 'completed', 'success'], true)) {
                    Log::info('MIDTRANS WEBHOOK DUPLICATE ORDER (ALREADY PAID)', [
                        'order_id' => $orderId,
                        'status' => $lockedOrder->status,
                    ]);
                    return;
                }

                // simpan payload kalau kamu punya kolom json
                if (property_exists($lockedOrder, 'midtrans_payload')) {
                    $lockedOrder->midtrans_payload = $payload;
                }

                // map status ke order (sesuaikan)
                // === ADJUST 3: status mapping order kamu ===
                $lockedOrder->status = $mappedStatus === 'paid' ? 'paid' : $mappedStatus;
                $lockedOrder->save();

                // jika belum paid, selesai
                if (!$isPaid || $mappedStatus !== 'paid') {
                    // kalau punya referral_transactions pending, biarkan tetap pending
                    return;
                }

                /**
                 * REFERRAL:
                 * Kita set referral_transactions yang order_id = order->id menjadi valid
                 * lalu hitung komisi untuk referrer dan catat ke ledger/wallet.
                 */
                $tx = ReferralTransaction::query()
                    ->where('order_id', $lockedOrder->id)
                    ->lockForUpdate()
                    ->first();

                if (!$tx) {
                    // order ini bukan order referral, aman skip
                    return;
                }

                // kalau sudah valid, skip (idempotent)
                if ($tx->status === 'valid') {
                    return;
                }

                $settings = ReferralSetting::current();

                // Kalau referral dimatikan admin, kita tandai invalid (atau biarkan, pilih salah satu)
                if (!$settings->enabled) {
                    $tx->status = 'invalid';
                    $tx->occurred_at = now();
                    $tx->save();
                    return;
                }

                // === ADJUST 4: ambil total order amount yang benar ===
                // ganti sesuai kolom order kamu: total_amount / grand_total / amount
                $orderAmount = (int) ($lockedOrder->total_amount ?? 0);

                // hitung komisi
                $commission = 0;
                if ($settings->commission_type === 'fixed') {
                    $commission = (int) $settings->commission_value;
                } else {
                    // percent
                    $commission = (int) floor($orderAmount * ((int) $settings->commission_value) / 100);
                }

                // update tx jadi valid
                $tx->status = 'valid';
                $tx->order_amount = $orderAmount;
                $tx->commission_amount = max(0, $commission);
                $tx->occurred_at = now();
                $tx->save();

                // credit komisi ke wallet referrer + ledger
                if ($commission > 0) {
                    DB::table('wallets')->where('user_id', (int)$tx->referrer_id)->increment('balance', $commission);

                    // kalau LedgerService kamu belum punya method referralCommission,
                    // pakai method generic yang kamu punya (contoh: topup/credit).
                    // Aku pakai "topup" sebagai fallback.
                    $ledger->topup(
                        userId: (int) $tx->referrer_id,
                        amount: (int) $commission,
                        reference: 'REFERRAL:' . $lockedOrder->id,
                        meta: [
                            'type' => 'referral_commission',
                            'order_id' => $lockedOrder->id,
                            'buyer_user_id' => $tx->user_id,
                            'midtrans_order_id' => $orderId,
                        ]
                    );
                }

                Log::info('MIDTRANS ORDER PAID -> REFERRAL VALID + COMMISSION CREDITED', [
                    'midtrans_order_id' => $orderId,
                    'order_db_id' => $lockedOrder->id,
                    'referrer_id' => $tx->referrer_id,
                    'buyer_user_id' => $tx->user_id,
                    'commission' => $commission,
                ]);
            });

            return response()->json(['success' => true, 'message' => 'OK (order)'], 200);

        } catch (\Throwable $e) {
            Log::error('MIDTRANS ORDER WEBHOOK ERROR', [
                'order_id' => $orderId,
                'err' => $e->getMessage(),
            ]);

            return response()->json(['success' => true, 'ignored' => true, 'message' => 'Order internal error (ignored)'], 200);
        }
    }
}
