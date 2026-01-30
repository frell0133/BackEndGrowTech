<?php

namespace App\Http\Controllers\Api\V1\Webhook;

use App\Http\Controllers\Controller;
use App\Models\WalletTopup;
use App\Services\LedgerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MidtransWebhookController extends Controller
{
    public function handle(Request $request, LedgerService $ledger)
    {
        $payload = $request->all();

        $serverKey = (string) env('MIDTRANS_SERVER_KEY', '');
        if ($serverKey === '') {
            // kalau key kosong, webhook real sebaiknya ditolak biar aman
            return response()->json(['success' => false, 'error' => ['message' => 'Midtrans server key not configured']], 400);
        }

        $orderId     = (string) ($payload['order_id'] ?? '');
        $statusCode  = (string) ($payload['status_code'] ?? '');
        $grossAmount = (string) ($payload['gross_amount'] ?? '');
        $signature   = (string) ($payload['signature_key'] ?? '');

        $expected = hash('sha512', $orderId . $statusCode . $grossAmount . $serverKey);

        if (!$orderId || !$signature || !hash_equals($expected, $signature)) {
            Log::warning('Midtrans webhook invalid signature', ['order_id' => $orderId]);
            return response()->json(['success' => false, 'error' => ['message' => 'Invalid signature']], 401);
        }

        $topup = WalletTopup::where('order_id', $orderId)->first();
        if (!$topup) {
            return response()->json(['success' => false, 'error' => ['message' => 'Topup not found']], 404);
        }

        $topup->update([
            'external_id' => $payload['transaction_id'] ?? $topup->external_id,
            'raw_callback' => $payload,
        ]);

        $transactionStatus = (string) ($payload['transaction_status'] ?? '');
        $fraudStatus       = (string) ($payload['fraud_status'] ?? '');

        $isPaid = $transactionStatus === 'settlement'
            || ($transactionStatus === 'capture' && ($fraudStatus === 'accept' || $fraudStatus === ''));

        if ($transactionStatus === 'pending') {
            $topup->update(['status' => 'pending']);
            return response()->json(['success' => true]);
        }

        if (in_array($transactionStatus, ['deny','cancel'], true)) {
            $topup->update(['status' => 'failed']);
            return response()->json(['success' => true]);
        }

        if ($transactionStatus === 'expire') {
            $topup->update(['status' => 'expired']);
            return response()->json(['success' => true]);
        }

        if ($isPaid) {
            if ($topup->posted_to_ledger_at) {
                $topup->update(['status' => 'paid']);
                return response()->json(['success' => true]);
            }

            $ledger->topup(
                $topup->user_id,
                (int) $topup->amount,
                $topup->order_id,
                "Topup Midtrans order_id={$topup->order_id}"
            );

            $topup->update([
                'status' => 'paid',
                'posted_to_ledger_at' => now(),
            ]);

            return response()->json(['success' => true]);
        }

        return response()->json(['success' => true]);
    }
}
