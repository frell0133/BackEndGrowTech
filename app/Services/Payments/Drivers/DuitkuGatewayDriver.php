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

class DuitkuGatewayDriver implements PaymentGatewayDriver
{
    public function createOrderPayment(PaymentGateway $gateway, Order $order, array $context = []): array
    {
        $user = $context['user'] ?? null;
        $$grossAmount = (int) round((float) ($context['gross_amount'] ?? ((float) $order->amount + (float) ($order->gateway_fee_amount ?? 0))));

        $itemDetails = [];
        if ($order->relationLoaded('items') && $order->items) {
            foreach ($order->items as $item) {
                $price = (int) round((float) ($item->unit_price ?? 0));
                $qty = max(1, (int) ($item->qty ?? 1));
                $itemDetails[] = [
                    'name' => (string) ($item->product_name ?? 'Product'),
                    'price' => $price,
                    'quantity' => $qty,
                ];
            }
        }

        if (empty($itemDetails)) {
            $itemDetails[] = [
                'name' => 'Order Payment',
                'price' => $grossAmount,
                'quantity' => 1,
            ];
        }

        return $this->createInvoice(
            gateway: $gateway,
            merchantOrderId: (string) $order->invoice_number,
            amount: $grossAmount,
            title: 'Order ' . (string) $order->invoice_number,
            email: (string) ($user->email ?? ''),
            customerName: (string) ($user->name ?? 'Customer'),
            phoneNumber: (string) ($user->phone ?? ''),
            itemDetails: $itemDetails
        );
    }

    public function createTopupPayment(PaymentGateway $gateway, WalletTopup $topup, array $context = []): array
    {
        $user = $context['user'] ?? null;
        $grossAmount = (int) round((float) $topup->amount);

        return $this->createInvoice(
            gateway: $gateway,
            merchantOrderId: (string) $topup->order_id,
            amount: $grossAmount,
            title: 'Wallet Topup',
            email: (string) ($user->email ?? ''),
            customerName: (string) ($user->name ?? 'Customer'),
            phoneNumber: (string) ($user->phone ?? ''),
            itemDetails: [
                [
                    'name' => 'Wallet Topup',
                    'price' => $grossAmount,
                    'quantity' => 1,
                ],
            ]
        );
    }

    public function parseWebhook(PaymentGateway $gateway, Request $request): array
    {
        $payload = $request->all();
        $config = $gateway->resolvedConfig();

        $merchantCode = (string) ($payload['merchantCode'] ?? '');
        $merchantOrderId = (string) ($payload['merchantOrderId'] ?? '');
        $amountRaw = (string) ($payload['amount'] ?? '0');
        $signature = Str::lower((string) ($payload['signature'] ?? ''));
        $apiKey = (string) ($config['api_key'] ?? '');

        if ($merchantCode === '' || $merchantOrderId === '' || $apiKey === '') {
            throw ValidationException::withMessages([
                'gateway_config' => 'Payload atau konfigurasi Duitku tidak lengkap.',
            ]);
        }

        $expected = md5($merchantCode . $amountRaw . $merchantOrderId . $apiKey);
        if (!hash_equals(Str::lower($expected), $signature)) {
            throw ValidationException::withMessages([
                'signature' => 'Signature Duitku tidak valid.',
            ]);
        }

        $resultCode = (string) ($payload['resultCode'] ?? '');
        $status = match ($resultCode) {
            '00' => 'paid',
            '01' => 'failed',
            default => 'pending',
        };

        return [
            'valid' => true,
            'merchant_order_id' => $merchantOrderId,
            'external_id' => (string) ($payload['reference'] ?? $merchantOrderId),
            'status' => $status,
            'amount' => (float) str_replace(',', '', $amountRaw),
            'paid_at' => now()->toDateTimeString(),
            'payload' => $payload,
        ];
    }

    protected function createInvoice(
        PaymentGateway $gateway,
        string $merchantOrderId,
        int $amount,
        string $title,
        string $email,
        string $customerName,
        string $phoneNumber,
        array $itemDetails
    ): array {
        $config = $gateway->resolvedConfig();

        $merchantCode = (string) ($config['merchant_code'] ?? '');
        $apiKey = (string) ($config['api_key'] ?? '');

        if ($merchantCode === '' || $apiKey === '') {
            throw ValidationException::withMessages([
                'gateway_config' => 'Konfigurasi Duitku merchant_code/api_key belum diisi.',
            ]);
        }

        $baseUrl = $gateway->sandbox_mode
            ? 'https://api-sandbox.duitku.com/api/merchant'
            : 'https://api-prod.duitku.com/api/merchant';

        $timestamp = (string) round(microtime(true) * 1000);
        $signature = hash('sha256', $merchantCode . $timestamp . $apiKey);

        $body = [
            'paymentAmount' => $amount,
            'merchantOrderId' => $merchantOrderId,
            'productDetails' => $title,
            'email' => $email,
            'phoneNumber' => $phoneNumber,
            'customerVaName' => $customerName,
            'callbackUrl' => rtrim((string) config('app.url'), '/') . '/api/v1/webhooks/payments/' . $gateway->code,
            'returnUrl' => rtrim((string) config('app.frontend_url', config('app.url')), '/'),
            'expiryPeriod' => (int) ($config['expiry_period'] ?? 60),
            'itemDetails' => $itemDetails,
        ];

        $paymentMethod = (string) ($config['payment_method'] ?? '');
        if ($paymentMethod !== '') {
            $body['paymentMethod'] = $paymentMethod;
        }

        $response = Http::timeout(30)
            ->acceptJson()
            ->withHeaders([
                'x-duitku-signature' => $signature,
                'x-duitku-timestamp' => $timestamp,
                'x-duitku-merchantcode' => $merchantCode,
            ])
            ->post($baseUrl . '/createInvoice', $body);

        $json = $response->json();

        if (!$response->successful()) {
            return [
                'success' => false,
                'status' => 'failed',
                'external_id' => $merchantOrderId,
                'message' => (string) ($json['statusMessage'] ?? 'Gagal membuat invoice Duitku'),
                'payload' => is_array($json) ? $json : ['raw' => $response->body()],
            ];
        }

        return [
            'success' => true,
            'status' => 'pending',
            'external_id' => (string) ($json['reference'] ?? $merchantOrderId),
            'reference' => (string) ($json['reference'] ?? $merchantOrderId),
            'redirect_url' => (string) ($json['paymentUrl'] ?? ''),
            'mode' => $gateway->sandbox_mode ? 'sandbox' : 'production',
            'payload' => is_array($json) ? $json : [],
        ];
    }
}