<?php

namespace App\Http\Controllers\Api\V1\Simulate;

use App\Http\Controllers\Controller;
use App\Models\WalletTopup;
use App\Services\LedgerService;
use Illuminate\Http\Request;

class SimulateTopupController extends Controller
{
    public function simulatePay(string $orderId, Request $request, LedgerService $ledger)
    {
        $simulate = filter_var(env('MIDTRANS_SIMULATE', true), FILTER_VALIDATE_BOOL);
        if (!$simulate) {
            return response()->json(['success' => false, 'error' => ['message' => 'Simulation disabled']], 403);
        }

        $topup = WalletTopup::where('order_id', $orderId)->first();
        if (!$topup) {
            return response()->json(['success' => false, 'error' => ['message' => 'Topup not found']], 404);
        }

        // idempotent
        if ($topup->posted_to_ledger_at) {
            return response()->json(['success' => true, 'data' => ['status' => $topup->status]], 200);
        }

        // post ke ledger
        $ledger->topup(
            $topup->user_id,
            (int) $topup->amount,
            $topup->order_id,
            "SIMULATED Topup order_id={$topup->order_id}"
        );

        $topup->update([
            'status' => 'paid',
            'posted_to_ledger_at' => now(),
            'raw_callback' => array_merge($topup->raw_callback ?? [], [
                'simulated' => true,
                'transaction_status' => 'settlement',
            ]),
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'order_id' => $topup->order_id,
                'status' => 'paid',
            ],
            'error' => null,
        ]);
    }
}
