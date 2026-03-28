<?php

namespace App\Jobs;

use App\Models\Delivery;
use App\Models\Order;
use App\Services\BrevoMailService;
use App\Services\OrderFulfillmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SendOrderDeliveryEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(
        public int $orderId,
        public string $trigger = 'resend',
        public bool $forceResend = false
    ) {
        $this->onQueue('mail');
    }

    public function handle(OrderFulfillmentService $fulfill, BrevoMailService $brevo): void
    {
        $lockKey = 'delivery:mail:order:' . $this->orderId;
        $lockSeconds = max(180, (int) $this->timeout + 30);
        $lock = Cache::lock($lockKey, $lockSeconds);

        if (!$lock->get()) {
            Log::warning('SendOrderDeliveryEmailJob: lock busy, skip duplicate execution', [
                'order_id' => $this->orderId,
                'trigger' => $this->trigger,
                'force_resend' => $this->forceResend,
                'lock_key' => $lockKey,
                'lock_ttl_seconds' => $lockSeconds,
            ]);

            return;
        }

        try {
            $order = Order::query()
                ->with([
                    'user',
                    'items',
                    'deliveries.license.product',
                    'product',
                    'payment',
                ])
                ->find($this->orderId);

            if (!$order) {
                Log::warning('SendOrderDeliveryEmailJob: order not found', [
                    'order_id' => $this->orderId,
                    'trigger' => $this->trigger,
                ]);
                return;
            }

            if ($order->deliveries->count() === 0) {
                Log::warning('SendOrderDeliveryEmailJob: no deliveries', [
                    'order_id' => $order->id,
                    'trigger' => $this->trigger,
                ]);
                return;
            }

            $totalQty = $order->items->count() > 0
                ? (int) $order->items->sum('qty')
                : (int) ($order->qty ?? 1);

            $firstDelivery = $order->deliveries->first();

            if ($this->trigger === 'close') {
                if ($totalQty !== 1) {
                    Log::warning('SendOrderDeliveryEmailJob: close trigger blocked by total qty', [
                        'order_id' => $order->id,
                        'total_qty' => $totalQty,
                    ]);
                    return;
                }

                if (!$firstDelivery || $firstDelivery->delivery_mode !== 'one_time' || !$firstDelivery->revealed_at) {
                    Log::warning('SendOrderDeliveryEmailJob: close trigger blocked by delivery state', [
                        'order_id' => $order->id,
                        'delivery_mode' => $firstDelivery?->delivery_mode,
                        'revealed_at' => $firstDelivery?->revealed_at,
                    ]);
                    return;
                }
            }

            if (!$this->forceResend && $order->deliveries->whereNotNull('emailed_at')->count() > 0) {
                Log::info('SendOrderDeliveryEmailJob: already emailed, skip', [
                    'order_id' => $order->id,
                    'trigger' => $this->trigger,
                ]);
                return;
            }

            $to = $order->email ?? $order->user?->email;
            if (!$to) {
                throw new \RuntimeException('Order email not found');
            }

            $items = $order->deliveries
                ->map(fn ($delivery) => $delivery->license ? $fulfill->formatLicense($delivery->license) : null)
                ->filter(fn ($item) => !empty($item['license_key']) || !empty($item['payload']) || !empty($item['product_name']))
                ->values()
                ->all();

            if (empty($items)) {
                Log::warning('SendOrderDeliveryEmailJob: no delivery payload', [
                    'order_id' => $order->id,
                    'trigger' => $this->trigger,
                ]);
                return;
            }

            $html = view('emails.digital-items', [
                'order' => $order,
                'items' => $items,
            ])->render();

            $result = $brevo->sendHtml($to, 'Pesanan GrowTech - Digital Items', $html);
            if (!($result['ok'] ?? false)) {
                Log::error('SendOrderDeliveryEmailJob: brevo failed', [
                    'order_id' => $order->id,
                    'trigger' => $this->trigger,
                    'to' => $to,
                    'response' => $result,
                ]);

                throw new \RuntimeException('Brevo send failed');
            }

            DB::transaction(function () use ($order) {
                Delivery::query()
                    ->where('order_id', (int) $order->id)
                    ->lockForUpdate()
                    ->get();

                $query = Delivery::query()->where('order_id', (int) $order->id);

                if (!$this->forceResend) {
                    $query->whereNull('emailed_at');
                }

                $query->update(['emailed_at' => now()]);
            });

            Log::info('SendOrderDeliveryEmailJob: success', [
                'order_id' => $order->id,
                'trigger' => $this->trigger,
                'force_resend' => $this->forceResend,
                'to' => $to,
                'deliveries_count' => count($items),
            ]);
        } finally {
            try {
                $lock->release();
            } catch (\Throwable $ignored) {
            }
        }
    }
}
