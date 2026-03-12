<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Models\WalletTopup;
use App\Services\Payments\PaymentGatewayManager;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserTopupController extends Controller
{
    use ApiResponse;

    public function init(Request $request, PaymentGatewayManager $gatewayManager)
    {
        $user = $request->user();

        $v = $request->validate([
            'amount' => ['required', 'numeric', 'min:10000'],
            'gateway_code' => ['nullable', 'string', 'max:100'],
            'method' => ['nullable', 'string', 'max:100'], // backward compatibility
        ]);

        $gatewayKey = trim((string) ($v['gateway_code'] ?? $v['method'] ?? ''));

        $gateway = $gatewayKey !== ''
            ? $gatewayManager->resolveActiveByCodeOrAlias($gatewayKey, 'topup')
            : $gatewayManager->defaultForScope('topup');

        if (!$gateway) {
            return $this->fail('Payment gateway topup tidak tersedia atau tidak aktif', 422);
        }

        $amount = (float) $v['amount'];

        $topup = DB::transaction(function () use ($user, $amount, $gateway) {
            $topup = WalletTopup::create([
                'user_id' => (int) $user->id,
                'order_id' => 'TMP-' . now()->format('YmdHis') . '-' . strtoupper(substr(md5((string) microtime(true)), 0, 6)),
                'amount' => $amount,
                'currency' => 'IDR',
                'status' => 'initiated',
                'gateway_code' => $gateway->code,
                'raw_callback' => [],
            ]);

            $topup->order_id = 'TOPUP-' . now()->format('YmdHis') . '-' . $topup->id;
            $topup->save();

            return $topup;
        });

        try {
            $init = $gatewayManager->driverFor($gateway)->createTopupPayment($gateway, $topup, [
                'user' => $user,
            ]);
        } catch (\Throwable $e) {
            $topup->status = 'failed';
            $topup->raw_callback = ['exception' => $e->getMessage()];
            $topup->save();

            return $this->fail($e->getMessage(), 422);
        }

        if (!($init['success'] ?? false)) {
            $topup->status = 'failed';
            $topup->raw_callback = ['init' => $init['payload'] ?? $init];
            $topup->save();

            return $this->fail((string) ($init['message'] ?? 'Gagal membuat topup'), 422);
        }

        $simulatePayEndpoint = $init['simulate_pay_endpoint'] ?? null;
        if (!$simulatePayEndpoint && ($init['mode'] ?? null) === 'simulation') {
            $simulatePayEndpoint = rtrim((string) config('app.url'), '/') . '/api/v1/topups/' . $topup->order_id . '/simulate-pay';
        }

        $topup->status = (string) ($init['status'] ?? 'pending');
        $topup->external_id = (string) ($init['external_id'] ?? $topup->order_id);
        $topup->snap_token = (string) ($init['snap_token'] ?? '');
        $topup->redirect_url = (string) ($init['redirect_url'] ?? '');
        $topup->raw_callback = ['init' => $init['payload'] ?? $init];
        $topup->save();

        return $this->ok([
            'topup_id' => (int) $topup->id,
            'order_id' => $topup->order_id,
            'status' => $topup->status,
            'amount' => (float) $topup->amount,
            'currency' => $topup->currency,
            'gateway_code' => $gateway->code,
            'gateway' => $gateway->code,
            'external_id' => $topup->external_id,
            'redirect_url' => $topup->redirect_url,
            'snap_token' => $topup->snap_token,
            'reference' => $init['reference'] ?? $topup->external_id,
            'simulate_pay_endpoint' => $simulatePayEndpoint,
            'status_endpoint' => rtrim((string) config('app.url'), '/') . '/api/v1/wallet/topups/' . $topup->order_id,
            'mode' => $init['mode'] ?? ($gateway->sandbox_mode ? 'sandbox' : 'production'),
            'payment_payload' => $init['payload'] ?? null,
        ]);
    }
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
            'id' => (int) $topup->id,
            'order_id' => $topup->order_id,
            'status' => $topup->status,
            'amount' => (float) $topup->amount,
            'currency' => $topup->currency,
            'gateway_code' => $topup->gateway_code,
            'external_id' => $topup->external_id,
            'paid_at' => optional($topup->paid_at)?->toISOString(),
            'posted_to_ledger_at' => optional($topup->posted_to_ledger_at)?->toISOString(),
            'invoice_emailed_at' => optional($topup->invoice_emailed_at)?->toISOString(),
            'invoice_email_error' => $topup->invoice_email_error,
            'created_at' => optional($topup->created_at)?->toISOString(),
        ]);
    }
}
