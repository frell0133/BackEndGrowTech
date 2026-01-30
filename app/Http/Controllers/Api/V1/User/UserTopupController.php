<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Models\WalletTopup;
use App\Services\MidtransService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class UserTopupController extends Controller
{
    public function init(Request $request, MidtransService $midtrans)
    {
        $user = $request->user();

        $data = $request->validate([
            'amount' => ['required','integer','min:10000'],
        ]);

        $amount = (int) $data['amount'];

        $orderId = 'TOPUP-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(6));

        $topup = WalletTopup::create([
            'user_id' => $user->id,
            'order_id' => $orderId,
            'amount' => $amount,
            'currency' => 'IDR',
            'status' => 'initiated',
        ]);

        // Payload Snap minimal (QRIS akan muncul sebagai metode di halaman Snap)
        $payload = [
            'transaction_details' => [
                'order_id' => $orderId,
                'gross_amount' => $amount,
            ],
            'customer_details' => [
                'first_name' => $user->name,
                'email' => $user->email,
            ],
            // Optional: bisa tambahkan item_details
            'item_details' => [
                [
                    'id' => 'TOPUP',
                    'price' => $amount,
                    'quantity' => 1,
                    'name' => 'Wallet Topup',
                ]
            ],
        ];

        $snap = $midtrans->createSnapTransaction($payload);

        if (($snap['error'] ?? false) === true) {
            $topup->update(['status' => 'failed']);
            return response()->json([
                'success' => false,
                'error' => [
                    'message' => 'Failed create Midtrans transaction',
                    'details' => $snap,
                ],
            ], 500);
        }

        $topup->update([
            'status' => 'pending', // setelah init, anggap pending bayar
            'snap_token' => $snap['token'] ?? null,
            'redirect_url' => $snap['redirect_url'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'topup_id' => $topup->id,
                'order_id' => $topup->order_id,
                'amount' => $topup->amount,
                'status' => $topup->status,
                'mode' => $snap['mode'] ?? 'unknown',
                'snap_token' => $topup->snap_token,
                'redirect_url' => $topup->redirect_url,
                // NOTE: kalau simulate, nanti kamu “bayar” pakai endpoint simulate
                'simulate_pay_endpoint' => ($snap['mode'] ?? '') === 'simulate'
                    ? "/api/v1/topups/{$topup->order_id}/simulate-pay"
                    : null,
            ],
            'error' => null,
        ]);
    }
}
