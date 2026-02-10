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
            $qty = (int) ($order->qty ?? 1);
            $productId = (int) $order->product_id;

            // Ambil license qty dengan locking supaya gak double terambil
            $licenses = License::query()
                ->where('product_id', $productId)
                ->where('status', 'available')
                ->lockForUpdate()
                ->limit($qty)
                ->get();

            if ($licenses->count() < $qty) {
                // stok kurang
                // opsional: update status order jadi hold/failed
                return ['ok' => false, 'message' => 'Stock not enough'];
            }

            // mark used
            $ids = $licenses->pluck('id')->all();
            License::whereIn('id', $ids)->update([
                'status' => 'used',
                'used_at' => now(), // kalau kolom ada
            ]);

            $mode = ($qty === 1) ? 'one_time' : 'email_only';

            foreach ($licenses as $lic) {
                Delivery::create([
                    'order_id' => $order->id,
                    'license_id' => $lic->id,
                    'delivery_mode' => $mode,
                    'reveal_count' => 0,
                    'revealed_at' => null,
                    'emailed_at' => ($qty === 1) ? null : now(), // qty>1: langsung email
                ]);
            }

            // qty>1: langsung kirim email semua item
            if ($qty > 1) {
                $items = $licenses->map(fn($lic) => $this->formatLicense($lic))->values()->all();
                Mail::to($order->email ?? $order->user?->email)->send(
                    new DigitalItemsMail($order, $items)
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
