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

    public function __construct(int $orderId)
    {
        $this->orderId = $orderId;
    }

    public function handle(BrevoMailService $brevo): void
    {
        $order = Order::query()
            ->with([
                'user',
                'product',        // legacy fallback
                'items.product',
                'payment',
            ])
            ->find($this->orderId);

        if (!$order) {
            Log::warning('SendInvoiceEmailJob: order not found', ['order_id' => $this->orderId]);
            return;
        }

        // ✅ idempotent (anti double-send)
        if (!empty($order->invoice_emailed_at)) {
            Log::info('SendInvoiceEmailJob: already sent', ['order_id' => $order->id]);
            return;
        }

        $to = $order->email ?? $order->user?->email;
        if (!$to) {
            Log::warning('SendInvoiceEmailJob: email empty', ['order_id' => $order->id]);
            return;
        }

        // Ambil item order (multi-item)
        $items = $order->items;

        // fallback legacy order (single product)
        if ($items->isEmpty() && !empty($order->product_id)) {
            $items = collect([
                (object) [
                    'qty' => (int) ($order->qty ?? 1),
                    'price' => (float) ($order->subtotal ?? $order->amount ?? 0),
                    'subtotal' => (float) ($order->subtotal ?? $order->amount ?? 0),
                    'product' => $order->product,
                ]
            ]);
        }

        $mappedItems = collect($items)->map(function ($it) {
            $qty = (int) ($it->qty ?? 1);
            $subtotal = (float) ($it->subtotal ?? 0);
            $price = (float) ($it->price ?? ($qty > 0 ? $subtotal / $qty : 0));

            return [
                'name' => $it->product->name ?? 'Product',
                'qty' => $qty,
                'price' => $price,
                'subtotal' => $subtotal,
            ];
        })->values()->all();

        $paymentStatus = $order->payment?->status;
        if (is_object($paymentStatus)) {
            $paymentStatus = $paymentStatus->value ?? '-';
        }

        $paymentMethod = $order->payment_gateway_code ?: ($order->payment?->gateway_code ?? '-');

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
                $order->forceFill([
                    'invoice_email_error' => is_string($res['body'] ?? null)
                        ? mb_substr((string) $res['body'], 0, 2000)
                        : mb_substr(json_encode($res['body'] ?? [], JSON_UNESCAPED_UNICODE), 0, 2000),
                ])->save();

                Log::error('SendInvoiceEmailJob: brevo failed', [
                    'order_id' => $order->id,
                    'response' => $res,
                ]);

                return;
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
            $order->forceFill([
                'invoice_email_error' => mb_substr($e->getMessage(), 0, 2000),
            ])->save();

            Log::error('SendInvoiceEmailJob: exception', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            throw $e; // biar queue retry
        }
    }
}