<?php

namespace App\Jobs;

use App\Mail\DigitalItemsMail;
use App\Models\Delivery;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendDigitalItemsFallbackEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $orderId;

    public function __construct(int $orderId)
    {
        $this->orderId = $orderId;
    }

    public function handle(): void
    {
        $order = Order::query()
            ->with(['deliveries.license', 'user'])
            ->find($this->orderId);

        if (!$order) return;

        if ($order->deliveries->count() !== 1) return;

        $delivery = $order->deliveries->first();
        if (!$delivery) return;

        if ($delivery->delivery_mode !== 'one_time') return;
        if ($delivery->emailed_at) return;

        $emailTo = $order->email ?? $order->user?->email;

        DB::transaction(function () use ($order, $delivery, $emailTo) {

            $d = Delivery::where('id', $delivery->id)->lockForUpdate()->first();
            if (!$d) return;
            if ($d->emailed_at) return;

            $lic = $d->license;

            $items = [[
                'license_id' => $lic->id,
                'code' => $lic->code ?? null,
                'payload' => $lic->payload ?? null,
            ]];

            try {
                Mail::to($emailTo)->queue(new DigitalItemsMail($order, $items));
                $d->emailed_at = now();
                $d->save();
            } catch (\Throwable $e) {
                Log::error('FALLBACK_EMAIL_QUEUE_FAILED', [
                    'order_id' => $order->id,
                    'err' => $e->getMessage(),
                ]);
            }
        });
    }
}