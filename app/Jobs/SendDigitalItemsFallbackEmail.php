<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\Delivery;
use App\Services\OrderFulfillmentService;
use App\Services\BrevoMailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendDigitalItemsFallbackEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $orderId;
    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(int $orderId)
    {
        $this->orderId = $orderId;
    }

    public function handle(OrderFulfillmentService $fulfill, BrevoMailService $brevo): void
    {
        Log::info('SendDigitalItemsFallbackEmail: start', [
            'order_id' => $this->orderId,
        ]);

        $order = Order::query()
            ->with(['user'])
            ->find($this->orderId);

        if (!$order) {
            Log::warning('SendDigitalItemsFallbackEmail: order not found', [
                'order_id' => $this->orderId,
            ]);
            return;
        }

        $to = $order->email ?? $order->user?->email;

        if (!$to) {
            Log::warning('SendDigitalItemsFallbackEmail: recipient empty', [
                'order_id' => $order->id,
            ]);
            throw new \RuntimeException('Digital items email recipient is empty');
        }

        // Hanya ambil delivery yang BELUM pernah dikirim ke email
        $deliveries = Delivery::query()
            ->where('order_id', $order->id)
            ->whereNull('emailed_at')
            ->with(['license.product'])
            ->get();

        if ($deliveries->isEmpty()) {
            Log::info('SendDigitalItemsFallbackEmail: nothing pending', [
                'order_id' => $order->id,
            ]);
            return;
        }

        $itemsEmail = $deliveries
            ->map(function ($d) use ($fulfill) {
                return $d->license ? $fulfill->formatLicense($d->license) : null;
            })
            ->filter(function ($item) {
                if (!is_array($item)) {
                    return false;
                }

                return !empty($item['license_key'])
                    || !empty($item['payload'])
                    || !empty($item['product_name']);
            })
            ->values()
            ->all();

        if (empty($itemsEmail)) {
            Log::warning('SendDigitalItemsFallbackEmail: no license payload formatted', [
                'order_id' => $order->id,
                'delivery_ids' => $deliveries->pluck('id')->all(),
            ]);

            throw new \RuntimeException('No valid digital items payload formatted');
        }

        $html = view('emails.digital-items', [
            'order' => $order,
            'items' => $itemsEmail,
        ])->render();

        $res = $brevo->sendHtml($to, 'Pesanan GrowTech - Digital Items', $html);

        if (!($res['ok'] ?? false)) {
            Log::error('SendDigitalItemsFallbackEmail: brevo failed', [
                'order_id' => $order->id,
                'to' => $to,
                'response' => $res,
            ]);

            throw new \RuntimeException('Brevo send failed for digital items email');
        }

        // Tandai hanya delivery yang BENAR-BENAR diproses pada job ini
        Delivery::query()
            ->whereIn('id', $deliveries->pluck('id')->all())
            ->whereNull('emailed_at')
            ->update([
                'emailed_at' => now(),
            ]);

        Log::info('SendDigitalItemsFallbackEmail: success', [
            'order_id' => $order->id,
            'to' => $to,
            'deliveries_count' => $deliveries->count(),
            'delivery_ids' => $deliveries->pluck('id')->all(),
        ]);
    }
}