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

        // Hanya untuk kasus qty=1 (one_time), karena qty>1 sudah langsung email saat fulfill
        if ($order->deliveries->count() !== 1) return;

        $delivery = $order->deliveries->first();
        if (!$delivery) return;

        if ($delivery->delivery_mode !== 'one_time') return;

        // kalau sudah pernah email, stop
        if ($delivery->emailed_at) return;

        DB::transaction(function () use ($order, $delivery) {

            // lock supaya tidak double-send kalau job kepanggil 2x
            $d = Delivery::where('id', $delivery->id)->lockForUpdate()->first();
            if (!$d) return;

            if ($d->emailed_at) return;

            $lic = $d->license;

            $items = [[
                'license_id' => $lic->id,
                'code' => $lic->code ?? null,
                'payload' => $lic->payload ?? null,
            ]];

            Mail::to($order->email ?? $order->user?->email)->send(
                new DigitalItemsMail($order, $items)
            );

            $d->emailed_at = now();
            $d->save();
        });
    }
}