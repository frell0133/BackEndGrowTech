<?php

namespace App\Services;

use App\Models\Order;
use App\Models\License;
use App\Models\Delivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use App\Mail\DigitalItemsMail;
use App\Jobs\SendDigitalItemsFallbackEmail;

class OrderFulfillmentService
{
    /**
     * Fulfill order ketika status payment sudah PAID.
     * Rules:
     * - qty total == 1 => one_time (reveal sekali) + fallback email otomatis (delay 3 menit)
     * - qty total > 1  => email_only langsung kirim email
     */
    public function fulfillPaidOrder(Order $order): array
    {
        // cegah dobel fulfill
        if ($order->deliveries()->count() > 0) {
            return ['ok' => true, 'message' => 'Already fulfilled'];
        }

        $result = DB::transaction(function () use ($order) {

            // Ambil item dari order_items (Opsi B)
            $items = $order->items()->get();

            // fallback legacy (kalau order lama belum punya items)
            if ($items->isEmpty()) {
                $legacyQty = (int) ($order->qty ?? 1);
                $legacyProductId = (int) ($order->product_id ?? 0);

                if ($legacyProductId <= 0 || $legacyQty <= 0) {
                    return ['ok' => false, 'message' => 'Order items empty'];
                }

                $items = collect([(object)[
                    'product_id' => $legacyProductId,
                    'qty' => $legacyQty,
                ]]);
            }

            $totalQty = (int) $items->sum('qty');
            $mode = ($totalQty === 1) ? 'one_time' : 'email_only';

            $allAllocated = collect();

            foreach ($items as $it) {
                $productId = (int) $it->product_id;
                $qty = (int) ($it->qty ?? 1);

                $licenses = License::query()
                    ->where('product_id', $productId)
                    ->where('status', 'available')
                    ->lockForUpdate()
                    ->limit($qty)
                    ->get();

                if ($licenses->count() < $qty) {
                    return ['ok' => false, 'message' => 'Stock not enough for product_id=' . $productId];
                }

                // mark used
                $ids = $licenses->pluck('id')->all();
                License::whereIn('id', $ids)->update([
                    'status' => 'used',
                    'used_at' => now(),
                ]);

                foreach ($licenses as $lic) {
                    Delivery::create([
                        'order_id' => $order->id,
                        'license_id' => $lic->id,
                        'delivery_mode' => $mode,
                        'reveal_count' => 0,
                        'revealed_at' => null,
                        'emailed_at' => ($totalQty === 1) ? null : now(),
                    ]);
                }

                $allAllocated = $allAllocated->merge($licenses);
            }

            // qty total > 1 => langsung email semua item
            if ($totalQty > 1) {
                $itemsEmail = $allAllocated->map(fn($lic) => $this->formatLicense($lic))->values()->all();
                Mail::to($order->email ?? $order->user?->email)->send(
                    new DigitalItemsMail($order, $itemsEmail)
                );
            }

            return ['ok' => true, 'message' => 'Fulfilled', 'mode' => $mode, 'totalQty' => $totalQty];
        });

        // OUTSIDE transaction: schedule fallback email untuk qty==1 (one_time)
        if (($result['ok'] ?? false) && (($result['totalQty'] ?? 0) === 1)) {
            // Default delay 3 menit
            $job = SendDigitalItemsFallbackEmail::dispatch($order->id)
                ->delay(now()->addMinutes(3));

            // kalau Laravel kamu support afterCommit, ini lebih aman
            if (method_exists($job, 'afterCommit')) {
                $job->afterCommit();
            }
        }

        return $result;
    }

    public function formatLicense(License $license): array
    {
        $data = [];

        if (isset($license->code) && $license->code) {
            $data['code'] = $license->code;
        }
        if (isset($license->payload) && $license->payload) {
            $data['payload'] = $license->payload;
        }

        $data['license_id'] = $license->id;
        return $data;
    }
}