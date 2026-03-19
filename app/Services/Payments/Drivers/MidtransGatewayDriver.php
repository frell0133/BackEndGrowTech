<?php

namespace App\Services\Payments\Drivers;

use App\Models\Order;
use App\Models\PaymentGateway;
use App\Models\WalletTopup;
use App\Services\Payments\Contracts\PaymentGatewayDriver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MidtransGatewayDriver implements PaymentGatewayDriver
{
    public function createOrderPayment(PaymentGateway $gateway, Order $order, array $context = []): array
    {
        $user = $context['user'] ?? null;

        $baseAmount = (int) round((float) $order->amount); // sudah net: subtotal + tax - discount
        $gatewayFee = (int) round((float) ($order->gateway_fee_amount ?? 0));
        $grossAmount = (int) round((float) ($context['gross_amount'] ?? ($baseAmount + $gatewayFee)));

        $itemDetails = [
            [
                'id' => 'order-' . (string) $order->id,
                'price' => max(0, $baseAmount),
                'quantity' => 1,
                'name' => Str::limit('Order ' . (string) $order->invoice_number, 50, ''),
            ],
        ];

        if ($gatewayFee > 0) {
            $itemDetails[] = [
                'id' => 'gateway-fee',
                'price' => $gatewayFee,
                'quantity' => 1,
                'name' => 'Gateway Fee',
            ];
        }

        $sumItems = 0;
        foreach ($itemDetails as $it) {
            $sumItems += ((int) $it['price'] * (int) $it['quantity']);
        }

        // jaga-jaga kalau ada selisih rounding
        if ($sumItems !== $grossAmount) {
            $delta = $grossAmount - $sumItems;
            $itemDetails[0]['price'] = (int) $itemDetails[0]['price'] + $delta;
        }

        $payload = [
            'transaction_details' => [
                'order_id' => (string) $order->invoice_number,
                'gross_amount' => $grossAmount,
            ],
            'item_details' => $itemDetails,
            'customer_details' => [
                'first_name' => (string) ($user->name ?? 'Customer'),
                'email' => (string) ($user->email ?? ''),
                'phone' => (string) ($user->phone ?? ''),
            ],
        ];

        return $this->createSnapTransaction($gateway, $payload, (string) $order->invoice_number);
    }

    public function createTopupPayment(PaymentGateway $gateway, WalletTopup $topup, array $context = []): array
    {
        $user = $context['user'] ?? null;

        $baseAmount = (int) round((float) $topup->amount);
        $gatewayFee = (int) round((float) ($topup->gateway_fee_amount ?? 0));
        $grossAmount = (int) round((float) ($context['gross_amount'] ?? ($baseAmount + $gatewayFee)));

        $itemDetails = [
            [
                'id' => 'wallet-topup',
                'price' => max(0, $baseAmount),
                'quantity' => 1,
                'name' => 'Wallet Topup',
            ],
        ];

        if ($gatewayFee > 0) {
            $itemDetails[] = [
                'id' => 'gateway-fee',
                'price' => $gatewayFee,
                'quantity' => 1,
                'name' => 'Gateway Fee',
            ];
        }

        $sumItems = 0;
        foreach ($itemDetails as $it) {
            $sumItems += ((int) $it['price'] * (int) $it['quantity']);
        }

        if ($sumItems !== $grossAmount) {
            $delta = $grossAmount - $sumItems;
            $itemDetails[0]['price'] = (int) $itemDetails[0]['price'] + $delta;
        }

        $payload = [
            'transaction_details' => [
                'order_id' => (string) $topup->order_id,
                'gross_amount' => $grossAmount,
            ],
            'item_details' => $itemDetails,
            'customer_details' => [
                'first_name' => (string) ($user->name ?? 'Customer'),
                'email' => (string) ($user->email ?? ''),
                'phone' => (string) ($user->phone ?? ''),
            ],
        ];

        return $this->createSnapTransaction($gateway, $payload, (string) $topup->order_id);
    }

    public function parseWebhook(PaymentGateway $gateway, Request $request): array
    {
        $payload = $request->all();

        $orderId = (string) ($payload['order_id'] ?? '');
        $statusCode = (string) ($payload['status_code'] ?? '');
        $grossAmount = (string) ($payload['gross_amount'] ?? '');
        $signatureKey = (string) ($payload['signature_key'] ?? '');

        $serverKey = (string) $this->requiredConfig($gateway, 'server_key');

        $expected = hash('sha512', $orderId . $statusCode . $grossAmount . $serverKey);
        if (!hash_equals($expected, $signatureKey)) {
            throw ValidationException::withMessages([
                'signature' => 'Signature Midtrans tidak valid.',
            ]);
        }

        $status = $this->mapWebhookStatus($payload);

        return [
            'valid' => true,
            'merchant_order_id' => $orderId,
            'external_id' => (string) ($payload['transaction_id'] ?? $orderId),
            'status' => $status,
            'amount' => (float) ($payload['gross_amount'] ?? 0),
            'paid_at' => $payload['settlement_time'] ?? $payload['transaction_time'] ?? null,
            'payload' => $payload,
        ];
    }

    protected function createSnapTransaction(PaymentGateway $gateway, array $payload, string $merchantOrderId): array
    {
        $config = $gateway->resolvedConfig();
        $simulate = (bool) ($config['simulate'] ?? config('services.midtrans.simulate', false));
        $mode = $gateway->sandbox_mode ? 'sandbox' : 'production';

        if ($simulate) {
            return [
                'success' => true,
                'status' => 'pending',
                'external_id' => $merchantOrderId,
                'reference' => $merchantOrderId,
                'snap_token' => 'SIM-' . Str::upper(Str::random(24)),
                'redirect_url' => rtrim((string) config('app.frontend_url', config('app.url')), '/') . '/payment/simulated/' . $merchantOrderId,
                'simulate_pay_endpoint' => rtrim((string) config('app.url'), '/') . '/api/v1/topups/' . $merchantOrderId . '/simulate-pay',
                'mode' => 'simulation',
                'payload' => [
                    'simulated' => true,
                    'order_id' => $merchantOrderId,
                    'request' => $payload,
                ],
            ];
        }

        $serverKey = (string) $this->requiredConfig($gateway, 'server_key');
        $snapUrl = $this->normalizedSnapBaseUrl((string) ($config['snap_url'] ?? config('services.midtrans.snap_url') ?? ''), $gateway->sandbox_mode);

        $response = Http::timeout(30)
            ->acceptJson()
            ->withBasicAuth($serverKey, '')
            ->post(rtrim($snapUrl, '/') . '/transactions', $payload);

        $json = $response->json();

        if (!$response->successful()) {
            return [
                'success' => false,
                'status' => 'failed',
                'external_id' => $merchantOrderId,
                'message' => (string) ($json['error_messages'][0] ?? $json['status_message'] ?? 'Gagal membuat transaksi Midtrans'),
                'payload' => is_array($json) ? $json : ['raw' => $response->body()],
            ];
        }

        return [
            'success' => true,
            'status' => 'pending',
            'external_id' => $merchantOrderId,
            'reference' => $merchantOrderId,
            'snap_token' => (string) ($json['token'] ?? ''),
            'redirect_url' => (string) ($json['redirect_url'] ?? ''),
            'mode' => $mode,
            'payload' => is_array($json) ? $json : [],
        ];
    }

    protected function normalizedSnapBaseUrl(string $snapUrl, bool $sandboxMode): string
    {
        $snapUrl = trim($snapUrl);

        if ($snapUrl === '') {
            return $sandboxMode
                ? 'https://app.sandbox.midtrans.com/snap/v1'
                : 'https://app.midtrans.com/snap/v1';
        }

        $snapUrl = rtrim($snapUrl, '/');

        if (Str::endsWith($snapUrl, '/transactions')) {
            $snapUrl = Str::beforeLast($snapUrl, '/transactions');
        }

        return $snapUrl;
    }

    protected function mapWebhookStatus(array $payload): string
    {
        $transactionStatus = Str::lower((string) ($payload['transaction_status'] ?? ''));
        $fraudStatus = Str::lower((string) ($payload['fraud_status'] ?? ''));

        return match ($transactionStatus) {
            'capture' => $fraudStatus === 'challenge' ? 'pending' : 'paid',
            'settlement' => 'paid',
            'pending' => 'pending',
            'deny', 'cancel' => 'failed',
            'expire' => 'expired',
            'refund', 'partial_refund', 'chargeback' => 'refunded',
            default => 'pending',
        };
    }

    protected function requiredConfig(PaymentGateway $gateway, string $key): mixed
    {
        $config = $gateway->resolvedConfig();

        $fallback = match ($key) {
            'server_key' => config('services.midtrans.server_key'),
            default => null,
        };

        $value = $config[$key] ?? $fallback;

        if ($value === null || $value === '') {
            throw ValidationException::withMessages([
                'gateway_config' => "Konfigurasi Midtrans [{$key}] belum diisi.",
            ]);
        }

        return $value;
    }
}   