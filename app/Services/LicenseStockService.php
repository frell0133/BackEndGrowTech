<?php

namespace App\Services;

use App\Models\License;
use Illuminate\Support\Facades\DB;

class LicenseStockService
{
    // Reserve N licenses untuk sebuah order_id
    public function reserve(int $productId, int $orderId, int $qty): array
    {
        return DB::transaction(function () use ($productId, $orderId, $qty) {

            $stocks = License::query()
                ->where('product_id', $productId)
                ->where('status', 'available')
                ->orderBy('id')
                ->lockForUpdate()
                ->limit($qty)
                ->get();

            if ($stocks->count() < $qty) {
                throw new \RuntimeException('Stok license tidak cukup');
            }

            $now = now();
            foreach ($stocks as $s) {
                $s->status = 'reserved';
                $s->reserved_order_id = $orderId;
                $s->reserved_at = $now;
                $s->save();
            }

            return $stocks->all();
        });
    }

    // Setelah payment settle: mark delivered semua license untuk order_id
    public function markDeliveredByOrder(int $orderId): int
    {
        return DB::transaction(function () use ($orderId) {

            $now = now();

            $licenses = License::query()
                ->where('reserved_order_id', $orderId)
                ->where('status', 'reserved')
                ->lockForUpdate()
                ->get();

            foreach ($licenses as $l) {
                $l->status = 'delivered';
                $l->delivered_at = $now;
                $l->save();
            }

            return $licenses->count();
        });
    }

    // Cancel order / gagal bayar: release balik available
    public function releaseByOrder(int $orderId): int
    {
        return DB::transaction(function () use ($orderId) {

            $licenses = License::query()
                ->where('reserved_order_id', $orderId)
                ->where('status', 'reserved')
                ->lockForUpdate()
                ->get();

            foreach ($licenses as $l) {
                $l->status = 'available';
                $l->reserved_order_id = null;
                $l->reserved_at = null;
                $l->save();
            }

            return $licenses->count();
        });
    }
}
