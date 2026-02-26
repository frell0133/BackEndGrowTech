<?php

namespace App\Services;

use App\Models\Order;
use App\Models\License;
use App\Models\Delivery;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use App\Jobs\SendDigitalItemsFallbackEmail;
use Illuminate\Support\Facades\Log;

class OrderFulfillmentService
{
    /**
     * Fulfill order ketika status payment sudah PAID.
     * Rules:
     * - qty total == 1 => one_time (reveal sekali) + product email dikirim saat endpoint close()
     * - qty total > 1  => email_only (product email di-queue setelah paid)
     */
    public function fulfillPaidOrder(Order $order): array
    {
        Log::info('FULFILL START', [
            'order_id' => $order->id,
            'invoice_number' => $order->invoice_number ?? null,
        ]);

        // cegah dobel fulfill
        if ($order->deliveries()->count() > 0) {
            Log::info('FULFILL SKIP ALREADY_DELIVERED', ['order_id' => $order->id]);
            return ['ok' => true, 'message' => 'Already fulfilled'];
        }

        $result = DB::transaction(function () use ($order) {
            // Ambil item dari order_items (opsi cart/checkout baru)
            $items = $order->items()->get();

            Log::info('FULFILL ITEMS CHECK', [
                'order_id' => $order->id,
                'items_count' => $items->count(),
                'legacy_product_id' => $order->product_id ?? null,
                'legacy_qty' => $order->qty ?? null,
            ]);

            // fallback legacy (order lama)
            if ($items->isEmpty()) {
                $legacyQty = (int) ($order->qty ?? 1);
                $legacyProductId = (int) ($order->product_id ?? 0);

                if ($legacyProductId <= 0 || $legacyQty <= 0) {
                    Log::error('FULFILL FAIL ORDER_ITEMS_EMPTY', [
                        'order_id' => $order->id,
                        'legacy_product_id' => $legacyProductId,
                        'legacy_qty' => $legacyQty,
                    ]);

                    return ['ok' => false, 'message' => 'Order items empty'];
                }

                $items = collect([(object) [
                    'product_id' => $legacyProductId,
                    'qty' => $legacyQty,
                ]]);
            }

            $totalQty = (int) $items->sum('qty');
            $mode = ($totalQty === 1) ? 'one_time' : 'email_only';

            foreach ($items as $it) {
                $productId = (int) $it->product_id;
                $qty = (int) ($it->qty ?? 1);

                // lock stok supaya aman dari race condition
                $licenses = License::query()
                    ->where('product_id', $productId)
                    ->where('status', 'available')
                    ->lockForUpdate()
                    ->limit($qty)
                    ->get();

                Log::info('FULFILL STOCK CHECK', [
                    'order_id' => $order->id,
                    'product_id' => $productId,
                    'need_qty' => $qty,
                    'available_found' => $licenses->count(),
                    'license_ids' => $licenses->pluck('id')->all(),
                ]);

                if ($licenses->count() < $qty) {
                    Log::error('FULFILL FAIL STOCK_NOT_ENOUGH', [
                        'order_id' => $order->id,
                        'product_id' => $productId,
                        'need_qty' => $qty,
                        'available_found' => $licenses->count(),
                    ]);

                    return ['ok' => false, 'message' => 'Stock not enough for product_id=' . $productId];
                }

                // tandai sold
                License::query()
                    ->whereIn('id', $licenses->pluck('id')->all())
                    ->update([
                        'status' => 'sold',
                        'order_id' => $order->id,
                        'sold_at' => now(),
                    ]);

                // buat delivery row
                foreach ($licenses as $lic) {
                    Delivery::create([
                        'order_id' => $order->id,
                        'license_id' => $lic->id,
                        'delivery_mode' => $mode, // one_time | email_only
                        'reveal_count' => 0,
                        'revealed_at' => null,
                        'emailed_at' => null,
                    ]);
                }
            }

            // ✅ UPDATE PURCHASES + POPULARITY (inside transaction)
            $this->bumpPurchaseCountAndPopularity($order, $items);

            Log::info('FULFILL SUCCESS ALLOCATION_DONE', [
                'order_id' => $order->id,
                'total_qty' => $totalQty,
                'mode' => $mode,
            ]);

            return [
                'ok' => true,
                'message' => 'Fulfilled',
                'mode' => $mode,
                'totalQty' => $totalQty,
            ];
        });

        /**
         * qty > 1:
         * Product email langsung di-queue setelah payment sukses (barengan invoice).
         */
        if (($result['ok'] ?? false) && ((int) ($result['totalQty'] ?? 0) > 1)) {
            Log::info('FULFILL EMAIL_ONLY QUEUE_DISPATCH', [
                'order_id' => $order->id,
                'total_qty' => (int) ($result['totalQty'] ?? 0),
            ]);

            $job = SendDigitalItemsFallbackEmail::dispatch($order->id)->delay(now()->addSeconds(3));

            // aman bila queue driver mendukung afterCommit
            if (method_exists($job, 'afterCommit')) {
                $job->afterCommit();
            }
        }

        /**
         * qty == 1:
         * JANGAN auto-kirim product email di sini.
         * Product email akan dikirim saat user menutup modal (endpoint close).
         */
        if (($result['ok'] ?? false) && ((int) ($result['totalQty'] ?? 0) === 1)) {
            Log::info('FULFILL ONE_TIME WAIT_CLOSE_TO_SEND_PRODUCT_EMAIL', [
                'order_id' => $order->id,
            ]);
        }

        return $result;
    }

    /**
     * ✅ Naikkan purchases_count dan update popularity_score.
     * - Menggunakan $items hasil resolve (order_items atau fallback legacy)
     * - Popularity score: (rating * 20) + purchases_count
     */
    private function bumpPurchaseCountAndPopularity(Order $order, $items): void
    {
        try {
            // items bisa collection object {product_id, qty}
            $grouped = collect($items)
                ->groupBy(fn ($it) => (int) ($it->product_id ?? 0))
                ->map(fn ($rows) => (int) $rows->sum(fn ($r) => (int) ($r->qty ?? 1)));

            foreach ($grouped as $productId => $qty) {
                if ($productId <= 0 || $qty <= 0) continue;

                $product = Product::query()->lockForUpdate()->find($productId);
                if (!$product) continue;

                $product->purchases_count = ((int) ($product->purchases_count ?? 0)) + $qty;

                $rating = (float) ($product->rating ?? 0);
                $purchases = (int) ($product->purchases_count ?? 0);

                // rumus simple sesuai request: rating + jumlah pembelian
                $product->popularity_score = ($rating * 20) + $purchases;

                $product->save();

                Log::info('FULFILL POPULARITY_UPDATED', [
                    'order_id' => $order->id,
                    'product_id' => $productId,
                    'added_qty' => $qty,
                    'purchases_count' => (int) $product->purchases_count,
                    'rating' => (float) $product->rating,
                    'popularity_score' => (float) $product->popularity_score,
                ]);
            }
        } catch (\Throwable $e) {
            // Jangan ganggu fulfill kalau popularity gagal
            Log::error('FULFILL POPULARITY_UPDATE_FAILED', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function formatLicense(License $license): array
    {
        $data = [];

        // Nama product (biar jelas key ini product apa)
        $data['product_name'] = $license->product?->name ?? null;

        // key utama
        $data['license_key'] = $license->license_key ?? null;

        // extra info kalau ada
        $payload = [];

        if (!empty($license->metadata)) {
            $meta = $license->metadata;

            // kalau metadata masih string json, decode
            if (is_string($meta)) {
                $decoded = json_decode($meta, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $meta = $decoded;
                }
            }

            $payload['metadata'] = $meta;
        }

        if (!empty($license->data_other)) {
            $payload['data_other'] = $license->data_other;
        }

        if (!empty($license->note)) {
            $payload['note'] = $license->note;
        }

        if (!empty($payload)) {
            $data['payload'] = $payload;
        }

        return $data;
    }
}