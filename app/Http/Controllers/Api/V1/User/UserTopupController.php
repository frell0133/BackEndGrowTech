<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Models\WalletTopup;
use App\Services\MidtransService;
use Illuminate\Http\Request;

class UserTopupController extends Controller
{
    public function init(Request $request, MidtransService $midtrans)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'error' => ['message' => 'Unauthenticated'],
            ], 401);
        }

        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:10000'],
        ]);

        $amount = (int) $data['amount'];

        // 1) Buat record dulu supaya order_id bisa mengandung topup_id (lebih aman)
        $topup = WalletTopup::create([
            'user_id' => $user->id,
            'order_id' => null,         // akan diisi setelah ada id
            'amount' => $amount,
            'currency' => 'IDR',
            'status' => 'initiated',
        ]);

        // 2) order_id: TOPUP-{id}-{timestamp} => mudah dimapping & unik
        $orderId = 'TOPUP-' . $topup->id . '-' . now()->format('YmdHis');
        $topup->update(['order_id' => $orderId]);

        // 3) customer_details aman
        $firstName = (string) ($user->name ?: 'User');
        $firstName = mb_substr($firstName, 0, 50);

        $payload = [
            'transaction_details' => [
                'order_id' => $orderId,
                'gross_amount' => $amount,
            ],
            'customer_details' => [
                'first_name' => $firstName,
                'email' => (string) $user->email,
            ],
            'item_details' => [
                [
                    'id' => 'TOPUP-' . $topup->id,
                    'price' => $amount,
                    'quantity' => 1,
                    'name' => 'Wallet Topup',
                ]
            ],
        ];

        $snap = $midtrans->createSnapTransaction($payload);

        // 4) Handle gagal hit Midtrans
        if (!($snap['ok'] ?? false)) {
            $topup->update([
                'status' => 'failed',
                // kalau ada kolom last_error/raw_response, isi di sini
                // 'last_error' => 'Failed create Midtrans transaction',
                // 'raw_callback' => $snap,
            ]);

            // jangan bocorin detail vendor di production
            $details = config('app.debug') ? $snap : null;

            return response()->json([
                'success' => false,
                'error' => [
                    'message' => 'Failed create Midtrans transaction',
                    'details' => $details,
                ],
            ], 500);
        }

        $token = $snap['token'] ?? null;
        $redirectUrl = $snap['redirect_url'] ?? null;

        if (!$token || !$redirectUrl) {
            $topup->update([
                'status' => 'failed',
                // 'last_error' => 'Midtrans response missing token/redirect_url',
                // 'raw_callback' => $snap,
            ]);

            $details = config('app.debug') ? $snap : null;

            return response()->json([
                'success' => false,
                'error' => [
                    'message' => 'Midtrans response missing token/redirect_url',
                    'details' => $details,
                ],
            ], 500);
        }

        // 5) Update pending + simpan token
        $topup->update([
            'status' => 'pending',
            'snap_token' => $token,
            'redirect_url' => $redirectUrl,
        ]);

        $mode = $snap['mode'] ?? 'unknown';

        return response()->json([
            'success' => true,
            'data' => [
                'topup_id' => $topup->id,
                'order_id' => $topup->order_id,
                'amount' => $topup->amount,
                'status' => $topup->status,
                'mode' => $mode,
                'snap_token' => $topup->snap_token,
                'redirect_url' => $topup->redirect_url,

                // kalau kamu pakai secret header, endpoint boleh tetap tampil,
                // tapi yang bisa execute tetap butuh header secret di controller
                'simulate_pay_endpoint' => $mode === 'simulate'
                    ? "/api/v1/topups/{$topup->order_id}/simulate-pay"
                    : null,
            ],
            'error' => null,
        ]);
    }
}
