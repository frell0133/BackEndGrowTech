<?php

namespace App\Http\Controllers\Api\V1\Webhook;

use App\Http\Controllers\Controller;
use App\Models\WalletTopup;
use App\Services\LedgerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MidtransWebhookController extends Controller
{
    /**
     * Midtrans HTTP Notification / Webhook
     *
     * Route (contoh):
     * POST /api/v1/webhooks/payments/midtrans
     */
    public function handle(Request $request, LedgerService $ledger)
    {
        $payload = $request->all();

        // (Opsional) log raw webhook untuk debug
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

        // ===== 2) validasi basic field =====
        if ($orderId === '' || $statusCode === '' || $grossAmount === '' || $signatureKey === '') {
            Log::warning('MIDTRANS WEBHOOK INVALID PAYLOAD', ['payload' => $payload]);

            // balas 200 supaya midtrans tidak retry terus, tapi kamu tetap punya log
            return response()->json([
                'success' => true,
                'ignored' => true,
                'message' => 'Invalid payload (ignored)',
            ], 200);
        }

        // ===== 3) server key (FIX nama env) =====
        // PENTING: pastikan config/services.php pakai env('MIDTRANS_SERVER_KEY')
        $serverKey = (string) config('services.midtrans.server_key', env('MIDTRANS_SERVER_KEY', ''));

        if ($serverKey === '') {
            Log::error('MIDTRANS WEBHOOK SERVER KEY EMPTY', [
                'order_id' => $orderId,
            ]);

            // balas 200 biar tidak spam email, tapi kamu tahu ada misconfig dari log
            return response()->json([
                'success' => true,
                'ignored' => true,
                'message' => 'Server key not configured (ignored)',
            ], 200);
        }

        // ===== 4) verify signature =====
        // Midtrans signature = sha512(order_id + status_code + gross_amount + server_key)
        $expected = hash('sha512', $orderId . $statusCode . $grossAmount . $serverKey);

        if (!hash_equals($expected, $signatureKey)) {
            Log::warning('MIDTRANS WEBHOOK INVALID SIGNATURE', [
                'order_id' => $orderId,
                'expected' => $expected,
                'got' => $signatureKey,
            ]);

            // balas 200 untuk stop retry + email, tapi log ada untuk investigasi
            return response()->json([
                'success' => true,
                'ignored' => true,
                'message' => 'Invalid signature (ignored)',
            ], 200);
        }

        // ===== 5) mapping status midtrans -> status internal =====
        // paid condition:
        $isPaid =
            ($transactionStatus === 'settlement') ||
            ($transactionStatus === 'capture' && $fraudStatus === 'accept');

        $newTopupStatus = match ($transactionStatus) {
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

        // ===== 6) cari topup berdasarkan order_id =====
        $topup = WalletTopup::where('order_id', $orderId)->first();

        // Ini penting buat kasus "Send test notification" dari Midtrans
        // order_id nya "payment_notif_test_..." -> gak ada di DB kamu -> JANGAN balas 404.
        if (!$topup) {
            Log::warning('MIDTRANS WEBHOOK TOPUP NOT FOUND (IGNORED)', [
                'order_id' => $orderId,
                'transaction_status' => $transactionStatus,
                'payment_type' => $paymentType,
            ]);

            return response()->json([
                'success' => true,
                'ignored' => true,
                'message' => 'Topup not found (ignored)',
            ], 200);
        }

        // ===== 7) proses update DB + idempotent =====
        try {
            DB::transaction(function () use ($topup, $payload, $newTopupStatus, $isPaid, $orderId, $ledger) {

                // lock row topup untuk hindari double credit
                $lockedTopup = WalletTopup::where('id', $topup->id)->lockForUpdate()->first();

                // kalau sudah paid/posted sebelumnya → idempotent (abaikan)
                // sesuaikan dengan field status yang kamu punya
                if (in_array($lockedTopup->status, ['paid', 'success', 'completed'], true)) {
                    Log::info('MIDTRANS WEBHOOK DUPLICATE (ALREADY PAID)', [
                        'order_id' => $orderId,
                        'status' => $lockedTopup->status,
                    ]);
                    return;
                }

                // simpan payload terakhir dari midtrans (kalau ada kolomnya)
                // sesuaikan nama field di model kamu: provider_payload / midtrans_payload / payload
                $lockedTopup->midtrans_payload = $payload; // kalau kolom json ada
                $lockedTopup->status = $newTopupStatus;
                $lockedTopup->save();

                // kalau belum paid, stop di sini
                if (!$isPaid || $newTopupStatus !== 'paid') {
                    return;
                }

                // ===== CREDIT WALLET + LEDGER (sekali saja) =====
                // asumsi topup punya user_id & amount
                $userId = (int) $lockedTopup->user_id;
                $amount = (int) $lockedTopup->amount;

                // 1) credit wallet
                DB::table('wallets')
                    ->where('user_id', $userId)
                    ->increment('balance', $amount);

                // 2) ledger topup (pakai service kamu)
                // Sesuaikan signature method di LedgerService kamu kalau beda
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

                // (Opsional) tandai topup sudah diposting / completed
                $lockedTopup->status = 'paid';
                $lockedTopup->save();

                Log::info('MIDTRANS WEBHOOK PAID -> WALLET CREDITED', [
                    'order_id' => $orderId,
                    'user_id' => $userId,
                    'amount' => $amount,
                ]);
            });

            return response()->json([
                'success' => true,
                'message' => 'OK',
            ], 200);

        } catch (\Throwable $e) {
            Log::error('MIDTRANS WEBHOOK ERROR', [
                'order_id' => $orderId,
                'err' => $e->getMessage(),
            ]);

            // balas 200 biar midtrans gak spam retry tanpa henti,
            // tapi error tetap ke-capture di log kamu.
            return response()->json([
                'success' => true,
                'ignored' => true,
                'message' => 'Internal error (ignored)',
            ], 200);
        }
    }
}
