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

class SendDigitalItemsFallbackEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $orderId;

    public function __construct(int $orderId)
    {
        $this->orderId = $orderId;
    }

    public function handle(OrderFulfillmentService $fulfill, BrevoMailService $brevo): void
    {
        $order = Order::query()
            ->with(['user'])
            ->find($this->orderId);

        if (!$order) return;

        $to = $order->email ?? $order->user?->email;
        if (!$to) return;

        // ambil deliveries + license + product
        $deliveries = Delivery::query()
            ->where('order_id', $order->id)
            ->with(['license.product'])
            ->get();

        if ($deliveries->isEmpty()) return;

        // ✅ kalau sudah emailed sukses, jangan kirim lagi
        $alreadyEmailed = $deliveries->first(fn ($d) => !empty($d->emailed_at));
        if ($alreadyEmailed) return;

        $itemsEmail = $deliveries
            ->map(fn ($d) => $fulfill->formatLicense($d->license))
            ->values()
            ->all();

        $html = view('emails.digital-items', [
            'order' => $order,
            'items' => $itemsEmail,
        ])->render();

        $res = $brevo->sendHtml($to, 'Pesanan GrowTech - Digital Items', $html);

        if (!($res['ok'] ?? false)) {
            // ❌ biarkan emailed_at null supaya bisa retry (manual/resend)
            return;
        }

        // ✅ tandai emailed_at setelah sukses
        Delivery::query()
            ->where('order_id', $order->id)
            ->whereNull('emailed_at')
            ->update(['emailed_at' => now()]);
    }
}