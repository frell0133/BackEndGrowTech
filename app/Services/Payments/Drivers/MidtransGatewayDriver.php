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
        $grossAmount = (float) ($context['gross_amount'] ?? ((float) $order->amount + (float) ($order->gateway_fee_amount ?? 0)));

        $itemDetails = [];
        $itemsTotal = 0.0;

        if ($order->relationLoaded('items') && $order->items) {
            foreach ($order->items as $item) {
                $price = (int) round((float) ($item->unit_price ?? 0));
                $qty = max(1, (int) ($item->qty ?? 1));
                $lineTotal = $price * $qty;
                $itemsTotal += $lineTotal;

                $itemDetails[] = [
                    'id' => (string) ($item->product_id ?? $item->id ?? Str::uuid()),
                    'price' => $price,
                    'quantity' => $qty,
                    'name' => Str::limit((string) ($item->product_name ?? 'Product'), 50, ''),
                ];
            }
        } elseif ($order->relationLoaded('product') && $order->product) {
            $price = (int) round((float) $order->amount);
            $itemDetails[] = [
                'id' => (string) ($order->product->id ?? $order->id),
                'price' => $price,
                'quantity' => 1,
                'name' => Str::limit((string) ($order->product->name ?? 'Product'), 50, ''),
            ];
            $itemsTotal += $price;
        }

        $feeDelta = (int) round($grossAmount) - (int) round($itemsTotal);
        if ($feeDelta > 0) {
            $itemDetails[] = [
                'id' => 'gateway-fee',
                'price' => $feeDelta,
                'quantity' => 1,
                'name' => 'Gateway Fee',
            ];
        }

        $payload = [
            'transaction_details' => [
                'order_id' => (string) $order->invoice_number,
                'gross_amount' => (int) round($grossAmount),
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
        $grossAmount = (float) $topup->amount;

        $payload = [
            'transaction_details' => [
                'order_id' => (string) $topup->order_id,
                'gross_amount' => (int) round($grossAmount),
            ],
            'item_details' => [
                [
                    'id' => 'wallet-topup',
                    'price' => (int) round($grossAmount),
                    'quantity' => 1,
                    'name' => 'Wallet Topup',
                ],
            ],
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
                'simulate_pay_endpoint' => null,
                'mode' => 'simulation',
                'payload' => [
                    'simulated' => true,
                    'order_id' => $merchantOrderId,
                    'request' => $payload,
                ],
            ];
        }

        $serverKey = (string) $this->requiredConfig($gateway, 'server_key');
        $snapUrl = (string) ($config['snap_url'] ?? config('services.midtrans.snap_url') ?? '');
        $snapUrl = trim($snapUrl);

        if ($snapUrl === '') {
            $snapUrl = $gateway->sandbox_mode
                ? 'https://app.sandbox.midtrans.com/snap/v1'
                : 'https://app.midtrans.com/snap/v1';
        }

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