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
        $this->onQueue('default');
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

        // ambil deliveries + license + product
        $deliveries = Delivery::query()
            ->where('order_id', $order->id)
            ->with(['license.product'])
            ->get();

        if ($deliveries->isEmpty()) {
            Log::warning('SendDigitalItemsFallbackEmail: no deliveries', [
                'order_id' => $order->id,
            ]);
            return;
        }

        // ✅ kalau sudah emailed sukses, jangan kirim lagi
        $alreadyEmailed = $deliveries->first(fn ($d) => !empty($d->emailed_at));
        if ($alreadyEmailed) {
            Log::info('SendDigitalItemsFallbackEmail: already emailed', [
                'order_id' => $order->id,
            ]);
            return;
        }

        $itemsEmail = $deliveries
            ->map(fn ($d) => $d->license ? $fulfill->formatLicense($d->license) : null)
            ->filter()
            ->values()
            ->all();

        if (empty($itemsEmail)) {
            Log::warning('SendDigitalItemsFallbackEmail: no license payload formatted', [
                'order_id' => $order->id,
            ]);
            return;
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

            // throw supaya retry queue / masuk failed_jobs kalau mentok
            throw new \RuntimeException('Brevo send failed for digital items email');
        }

        // ✅ tandai emailed_at setelah sukses
        Delivery::query()
            ->where('order_id', $order->id)
            ->whereNull('emailed_at')
            ->update(['emailed_at' => now()]);

        Log::info('SendDigitalItemsFallbackEmail: success', [
            'order_id' => $order->id,
            'to' => $to,
            'deliveries_count' => count($itemsEmail),
        ]);
    }
}