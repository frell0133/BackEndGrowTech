<?php

namespace App\Http\Controllers\Api\V1\Simulate;

use App\Http\Controllers\Controller;
use App\Models\WalletTopup;
use App\Services\LedgerService;
use Illuminate\Http\Request;

class SimulateTopupController extends Controller
{
    public function pay(string $orderId, Request $request, LedgerService $ledger)
    {
        // ✅ HARD BLOCK di production
        if (app()->environment('production')) {
            return response()->json([
                'success' => false,
                'data' => null,
                'meta' => (object)[],
                'error' => ['message' => 'Simulate endpoint disabled in production.'],
            ], 403);
        }

        // ✅ Feature flag MIDTRANS_SIMULATE harus true
        $simulate = (bool) (config('services.midtrans.simulate') ?? env('MIDTRANS_SIMULATE', false));
        if (!$simulate) {
            return response()->json([
                'success' => false,
                'data' => null,
                'meta' => (object)[],
                'error' => ['message' => 'Simulate is disabled (MIDTRANS_SIMULATE=false).'],
            ], 403);
        }

        $topup = WalletTopup::where('order_id', $orderId)->first();
        if (!$topup) {
            return response()->json([
                'success' => false,
                'data' => null,
                'meta' => (object)[],
                'error' => ['message' => 'Topup not found.'],
            ], 404);
        }

        if ($topup->posted_to_ledger_at) {
            return response()->json([
                'success' => true,
                'data' => [
                    'message' => 'Already posted to ledger.',
                    'order_id' => $topup->order_id,
                    'status' => $topup->status,
                ],
                'meta' => (object)[],
                'error' => null,
            ]);
        }

        // ✅ tandai paid + post ledger
        $ledger->topup(
            (int) $topup->user_id,
            (int) $topup->amount,
            (string) $topup->order_id,
            "Topup SIMULATE order_id={$topup->order_id}"
        );

        $topup->status = 'paid';
        $topup->posted_to_ledger_at = now();
        $topup->save();

        return response()->json([
            'success' => true,
            'data' => [
                'message' => 'Simulated payment applied and posted to ledger.',
                'order_id' => $topup->order_id,
            ],
            'meta' => (object)[],
            'error' => null,
        ]);
    }
}
