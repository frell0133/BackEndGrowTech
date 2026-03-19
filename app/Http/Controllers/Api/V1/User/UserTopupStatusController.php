<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Models\WalletTopup;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class UserTopupStatusController extends Controller
{
    use ApiResponse;

    public function show(Request $request, string $orderId)
    {
        $user = $request->user();

        $topup = WalletTopup::query()
            ->where('user_id', (int) $user->id)
            ->where('order_id', $orderId)
            ->first();

        if (!$topup) {
            return $this->fail('Topup tidak ditemukan', 404);
        }

        return $this->ok([
            'order_id' => $topup->order_id,
            'status' => $topup->status,
            'amount' => (float) $topup->amount,
            'gateway_fee_percent' => (float) ($topup->gateway_fee_percent ?? 0),
            'gateway_fee_amount' => (float) ($topup->gateway_fee_amount ?? 0),
            'total_payable_gateway' => (float) ((float) $topup->amount + (float) ($topup->gateway_fee_amount ?? 0)),
            'currency' => $topup->currency,
            'paid_at' => $topup->paid_at,
            'posted_to_ledger_at' => $topup->posted_to_ledger_at,
            'gateway_code' => $topup->gateway_code,
            'gateway' => $topup->gateway_code,
            'redirect_url' => $topup->redirect_url,
            'snap_token' => $topup->snap_token,
            'external_id' => $topup->external_id,
        ]);
    }
}