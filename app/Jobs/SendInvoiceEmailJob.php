<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\BrevoMailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendInvoiceEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $orderId;
    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(int $orderId)
    {
        $this->orderId = $orderId;
    }

    public function handle(BrevoMailService $brevo): void
    {
        $order = Order::query()
            ->with([
                'user',
                'product',       // legacy fallback
                'items.product', // multi-item (opsi B)
                'payment',
            ])
            ->find($this->orderId);

        if (!$order) {
            Log::warning('SendInvoiceEmailJob: order not found', ['order_id' => $this->orderId]);
            return;
        }

        // anti double send
        if (!empty($order->invoice_emailed_at)) {
            Log::info('SendInvoiceEmailJob: already sent', ['order_id' => $order->id]);
            return;
        }

        $to = $order->email ?? $order->user?->email;
        if (!$to) {
            // simpan error biar gampang dilihat
            try {
                $order->forceFill([
                    'invoice_email_error' => 'Invoice email recipient is empty',
                ])->save();
            } catch (\Throwable $ignored) {
            }

            Log::warning('SendInvoiceEmailJob: email empty', ['order_id' => $order->id]);

            // throw supaya retry (kalau memang mau retry)
            throw new \RuntimeException('Invoice email recipient is empty');
        }

        $items = $order->items;

        // fallback legacy single-product order
        if ($items->isEmpty() && !empty($order->product_id)) {
            $legacyQty = (int) ($order->qty ?? 1);
            $legacySubtotal = (float) ($order->subtotal ?? $order->amount ?? 0);
            $legacyUnit = $legacyQty > 0 ? ($legacySubtotal / $legacyQty) : 0;

            $items = collect([
                (object) [
                    'qty' => $legacyQty,
                    'unit_price' => $legacyUnit,
                    'line_subtotal' => $legacySubtotal,
                    'product' => $order->product,
                ]
            ]);
        }

        $mappedItems = collect($items)->map(function ($it) {
            return [
                'name' => $it->product->name ?? 'Product',
                'qty' => (int) ($it->qty ?? 1),
                'price' => (float) ($it->unit_price ?? 0),
                'subtotal' => (float) ($it->line_subtotal ?? 0),
            ];
        })->values()->all();

        $paymentStatus = $order->payment?->status;
        if (is_object($paymentStatus)) {
            $paymentStatus = $paymentStatus->value ?? '-';
        }

        $paymentMethod = $order->payment?->gateway_code
            ?? $order->payment_gateway_code
            ?? '-';

        $html = view('emails.invoice', [
            'order' => $order,
            'items' => $mappedItems,
            'paymentStatus' => $paymentStatus ?? '-',
            'paymentMethod' => $paymentMethod ?? '-',
        ])->render();

        $subject = 'Invoice Pesanan ' . ($order->invoice_number ?? ('#' . $order->id));

        try {
            $res = $brevo->sendHtml($to, $subject, $html);

            if (!($res['ok'] ?? false)) {
                $body = $res['body'] ?? null;

                try {
                    $order->forceFill([
                        'invoice_email_error' => is_string($body)
                            ? mb_substr($body, 0, 2000)
                            : mb_substr((string) json_encode($body, JSON_UNESCAPED_UNICODE), 0, 2000),
                    ])->save();
                } catch (\Throwable $ignored) {
                }

                Log::error('SendInvoiceEmailJob: brevo failed', [
                    'order_id' => $order->id,
                    'response' => $res,
                ]);

                // ✅ PENTING: throw supaya queue retry
                throw new \RuntimeException('Brevo send failed for invoice email');
            }

            $order->forceFill([
                'invoice_emailed_at' => now(),
                'invoice_email_error' => null,
            ])->save();

            Log::info('SendInvoiceEmailJob: success', [
                'order_id' => $order->id,
                'to' => $to,
            ]);
        } catch (\Throwable $e) {
            try {
                $order->forceFill([
                    'invoice_email_error' => mb_substr($e->getMessage(), 0, 2000),
                ])->save();
            } catch (\Throwable $ignored) {
            }

            Log::error('SendInvoiceEmailJob: exception', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            throw $e; // retry queue / masuk failed_jobs kalau mentok
        }
    }
}