<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Models\WalletTopup;
use Illuminate\Http\Request;

class UserTopupStatusController extends Controller
{
    public function show(string $orderId, Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'data' => null,
                'meta' => [],
                'error' => ['message' => 'Unauthenticated'],
            ], 401);
        }

        $topup = WalletTopup::where('order_id', $orderId)
            ->where('user_id', $user->id)
            ->first();

        if (!$topup) {
            return response()->json([
                'success' => false,
                'data' => null,
                'meta' => [],
                'error' => ['message' => 'Topup not found'],
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'order_id' => $topup->order_id,
                'status' => $topup->status,
                'amount' => $topup->amount,
                'currency' => $topup->currency,
                'paid_at' => $topup->paid_at,
                'posted_to_ledger_at' => $topup->posted_to_ledger_at,
                'gateway' => $topup->gateway,
            ],
            'meta' => [],
            'error' => null,
        ]);
    }
}
