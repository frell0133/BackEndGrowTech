<?php

namespace App\Services;

use App\Models\Order;
use App\Models\License;
use App\Models\Delivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use App\Mail\DigitalItemsMail;

class OrderFulfillmentService
{
    /**
     * Fulfill order ketika status payment sudah PAID.
     * Rules:
     * - qty == 1 => one_time (reveal sekali) + email setelah close
     * - qty > 1  => email_only langsung kirim email
     */
    public function fulfillPaidOrder(Order $order): array
    {
        // cegah dobel fulfill
        if ($order->deliveries()->count() > 0) {
            return ['ok' => true, 'message' => 'Already fulfilled'];
        }

        return DB::transaction(function () use ($order) {

            // Ambil item dari order_items (Opsi B)
            $items = $order->items()->get();

            // fallback legacy (kalau order lama belum punya items)
            if ($items->isEmpty()) {
                $legacyQty = (int) ($order->qty ?? 1);
                $legacyProductId = (int) ($order->product_id ?? 0);

                if ($legacyProductId <= 0 || $legacyQty <= 0) {
                    return ['ok' => false, 'message' => 'Order items empty'];
                }

                $items = collect([ (object)[
                    'product_id' => $legacyProductId,
                    'qty' => $legacyQty,
                ] ]);
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

            return ['ok' => true, 'message' => 'Fulfilled', 'mode' => $mode];
        });
    }

    public function formatLicense(License $license): array
    {
        // sesuaikan dengan struktur kolom licenses kamu:
        // - kalau punya "code" => kirim code
        // - kalau punya "payload" json => kirim payload
        $data = [];

        if (isset($license->code) && $license->code) {
            $data['code'] = $license->code;
        }
        if (isset($license->payload) && $license->payload) {
            $data['payload'] = $license->payload; // kalau json
        }

        $data['license_id'] = $license->id;
        return $data;
    }
    
}
