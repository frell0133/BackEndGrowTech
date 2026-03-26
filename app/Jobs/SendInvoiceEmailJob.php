<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\Setting;
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

    public string $queue = 'mail';
    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(public int $orderId)
    {
    }

    private function decodePercent($val): float
    {
        if (is_null($val)) return 0.0;

        if (is_string($val)) {
            $d = json_decode($val, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($d)) {
                $p = $d['percent'] ?? 0;
                return is_numeric($p) ? (float) $p : 0.0;
            }
            return is_numeric($val) ? (float) $val : 0.0;
        }

        if (is_array($val)) {
            $p = $val['percent'] ?? 0;
            return is_numeric($p) ? (float) $p : 0.0;
        }

        return is_numeric($val) ? (float) $val : 0.0;
    }

    public function handle(BrevoMailService $brevo): void
    {
        $order = Order::query()
            ->with([
                'user',
                'product',
                'items.product',
                'payment',
                'vouchers',
            ])
            ->find($this->orderId);

        if (!$order) {
            Log::warning('SendInvoiceEmailJob: order not found', ['order_id' => $this->orderId]);
            return;
        }

        if (!empty($order->invoice_emailed_at)) {
            Log::info('SendInvoiceEmailJob: already sent', ['order_id' => $order->id]);
            return;
        }

        $to = $order->email ?? $order->user?->email;

        if (!$to) {
            try {
                $order->forceFill([
                    'invoice_email_error' => 'Invoice email recipient is empty',
                ])->save();
            } catch (\Throwable $ignored) {
            }

            Log::warning('SendInvoiceEmailJob: email empty', ['order_id' => $order->id]);
            throw new \RuntimeException('Invoice email recipient is empty');
        }

        $items = $order->items;

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
                'subtotal' => (float) ($it->line_subtotal ?? (($it->unit_price ?? 0) * ($it->qty ?? 1))),
            ];
        })->values()->all();

        $paymentStatus = $order->payment?->status;
        if (is_object($paymentStatus)) {
            $paymentStatus = $paymentStatus->value ?? '-';
        }
        $paymentStatus = $paymentStatus ?? '-';

        $paymentMethod = $order->payment?->gateway_code ?? $order->payment_gateway_code ?? '-';

        try {
            $rawTax = Setting::query()->where('group', 'payment')->where('key', 'tax_percent')->value('value');
            $rawFee = Setting::query()->where('group', 'payment')->where('key', 'fee_percent')->value('value');

            $settingTax = (float) $this->decodePercent($rawTax);
            $settingFee = (float) $this->decodePercent($rawFee);

            $subtotal = (float) ($order->subtotal ?? 0);
            $discount = (float) ($order->discount_total ?? 0);
            $taxPercent = (float) ($order->tax_percent ?? 0);
            $taxAmount = (float) ($order->tax_amount ?? 0);

            if ($taxPercent <= 0 && $settingTax > 0 && $subtotal > 0) {
                $taxPercent = $settingTax;
                $taxAmount = round($subtotal * ($taxPercent / 100), 2);
                $baseAmount = (float) max(0, ($subtotal + $taxAmount) - $discount);

                $order->forceFill([
                    'tax_percent' => (int) round($taxPercent),
                    'tax_amount' => (float) $taxAmount,
                    'amount' => (float) $baseAmount,
                ])->save();
            }

            $feeAmount = (float) ($order->gateway_fee_amount ?? 0);
            if ($feeAmount <= 0 && $settingFee > 0 && (float) $order->amount > 0) {
                $feeAmount = round((float) $order->amount * ($settingFee / 100), 2);

                $order->forceFill([
                    'gateway_fee_percent' => (float) $settingFee,
                    'gateway_fee_amount' => (float) $feeAmount,
                ])->save();
            }
        } catch (\Throwable $ignored) {
        }

        $order->refresh();

        $html = view('emails.invoice', [
            'order' => $order,
            'items' => $mappedItems,
            'paymentStatus' => $paymentStatus,
            'paymentMethod' => $paymentMethod,
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

            throw $e;
        }
    }
}