<?php

namespace App\Http\Controllers\Api\V1\Webhook;

use App\Http\Controllers\Controller;
use App\Models\WalletTopup;
use App\Services\LedgerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class MidtransWebhookController extends Controller
{
    public function handle(Request $request, LedgerService $ledger)
    {
        $payload = $request->all();
        Log::info('MIDTRANS WEBHOOK HIT RAW', $request->all());
  
        $serverKey = (string) (config('services.midtrans.server_key') ?? env('MIDTRANS_SERVERKEY', ''));
        if ($serverKey === '') {
            return response()->json([
                'success' => false,
                'error' => ['message' => 'Midtrans server key not configured'],
            ], 400);
        }

        $orderId     = (string) ($payload['order_id'] ?? '');
        $statusCode  = (string) ($payload['status_code'] ?? '');
        $grossAmount = (string) ($payload['gross_amount'] ?? '');
        $signature   = (string) ($payload['signature_key'] ?? '');

        // ✅ signature Midtrans: sha512(order_id + status_code + gross_amount + server_key)
        $expected = hash('sha512', $orderId . $statusCode . $grossAmount . $serverKey);

        if ($orderId === '' || $signature === '' || !hash_equals($expected, $signature)) {
            Log::warning('Midtrans webhook invalid signature', [
                'order_id' => $orderId,
                'status_code' => $statusCode,
                'gross_amount' => $grossAmount,
            ]);

            return response()->json([
                'success' => false,
                'error' => ['message' => 'Invalid signature'],
            ], 401);
        }

        $topup = WalletTopup::where('order_id', $orderId)->first();
        if (!$topup) {
            return response()->json([
                'success' => false,
                'error' => ['message' => 'Topup not found'],
            ], 404);
        }

        // ✅ guard: gross_amount harus match amount di DB
        // Midtrans gross_amount biasanya "10000.00"
        $grossInt = (int) round((float) $grossAmount);
        if ($grossInt !== (int) $topup->amount) {
            Log::warning('Midtrans gross_amount mismatch', [
                'order_id' => $orderId,
                'midtrans_gross_amount' => $grossAmount,
                'db_amount' => $topup->amount,
            ]);

            return response()->json([
                'success' => false,
                'error' => ['message' => 'Gross amount mismatch'],
            ], 422);
        }

        // ✅ simpan callback + external_id
        $topup->external_id = $payload['transaction_id'] ?? $topup->external_id;
        $topup->raw_callback = $payload;
        $topup->save();

        $transactionStatus = (string) ($payload['transaction_status'] ?? '');
        $fraudStatus       = (string) ($payload['fraud_status'] ?? '');

        // ✅ paid rules:
        $isPaid = $transactionStatus === 'settlement'
            || ($transactionStatus === 'capture' && ($fraudStatus === 'accept' || $fraudStatus === ''));

        // ✅ pending
        if ($transactionStatus === 'pending') {
            if ($topup->status !== 'pending') {
                $topup->status = 'pending';
                $topup->save();
            }
            return response()->json(['success' => true]);
        }

        // ✅ failed
        if (in_array($transactionStatus, ['deny', 'cancel'], true)) {
            if ($topup->status !== 'failed') {
                $topup->status = 'failed';
                $topup->save();
            }
            return response()->json(['success' => true]);
        }

        // ✅ expired
        if ($transactionStatus === 'expire') {
            if ($topup->status !== 'expired') {
                $topup->status = 'expired';
                $topup->save();
            }
            return response()->json(['success' => true]);
        }

        // ✅ paid -> post ledger (idempotent + atomic)
        if ($isPaid) {
            if ($topup->posted_to_ledger_at) {
                if ($topup->status !== 'paid') {
                    $topup->status = 'paid';
                    $topup->save();
                }
                return response()->json(['success' => true]);
            }

            DB::transaction(function () use ($ledger, $topup, $payload) {
                $topup = WalletTopup::whereKey($topup->id)->lockForUpdate()->first();

                if ($topup->posted_to_ledger_at) {
                    return;
                }

                $ledger->topup(
                    (int) $topup->user_id,
                    (int) $topup->amount,
                    (string) $topup->order_id,
                    "Topup Midtrans order_id={$topup->order_id}, tx_id=" . ($payload['transaction_id'] ?? '-')
                );

                $topup->status = 'paid';
                $topup->posted_to_ledger_at = now();
                $topup->save();
            });

            return response()->json(['success' => true]);
        }

    }
}
