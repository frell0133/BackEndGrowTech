<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Jobs\SendDigitalItemsFallbackEmail;
use App\Models\Delivery;
use App\Models\License;
use App\Models\Order;
use App\Models\Product;
use App\Support\PublicCache;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderFulfillmentService
{
    private function dispatchProductEmailOnce(int $orderId): bool
    {
        $lockKey = 'dispatch:digital_items_email:order:' . $orderId;
        $lockSeconds = 180;
        $lock = Cache::lock($lockKey, $lockSeconds);

        if (!$lock->get()) {
            Log::warning('FULFILL EMAIL_ONLY DUPLICATE DISPATCH BLOCKED', [
                'order_id' => $orderId,
                'lock_key' => $lockKey,
                'lock_ttl_seconds' => $lockSeconds,
            ]);

            return false;
        }

        try {
            $job = SendDigitalItemsFallbackEmail::dispatch($orderId)->delay(now()->addSeconds(3));

            if (method_exists($job, 'afterCommit')) {
                $job->afterCommit();
            }

            return true;
        } catch (\Throwable $e) {
            try {
                $lock->release();
            } catch (\Throwable $ignored) {
            }

            throw $e;
        }
    }

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

        $result = DB::transaction(function () use ($order) {
            $lockedOrder = Order::query()
                ->whereKey((int) $order->id)
                ->lockForUpdate()
                ->first();

            if (!$lockedOrder) {
                Log::error('FULFILL FAIL ORDER_NOT_FOUND', [
                    'order_id' => $order->id,
                ]);

                return [
                    'ok' => false,
                    'message' => 'Order not found',
                ];
            }

            $status = (string) ($lockedOrder->status?->value ?? $lockedOrder->status);

            if (!in_array($status, [
                OrderStatus::PAID->value,
                OrderStatus::FULFILLED->value,
            ], true)) {
                Log::warning('FULFILL BLOCKED ORDER_NOT_PAID', [
                    'order_id' => $lockedOrder->id,
                    'status' => $status,
                ]);

                return [
                    'ok' => false,
                    'message' => 'Order not paid yet',
                ];
            }

            $items = $this->resolveOrderItems($lockedOrder);

            if ($items->isEmpty()) {
                Log::error('FULFILL FAIL ORDER_ITEMS_EMPTY', [
                    'order_id' => $lockedOrder->id,
                    'legacy_product_id' => $lockedOrder->product_id ?? null,
                    'legacy_qty' => $lockedOrder->qty ?? null,
                ]);

                return [
                    'ok' => false,
                    'message' => 'Order items empty',
                ];
            }

            $totalQty = (int) $items->sum(fn ($item) => max(0, (int) ($item->qty ?? 0)));
            $mode = $totalQty === 1 ? 'one_time' : 'email_only';

            $existingDeliveries = Delivery::query()
                ->where('order_id', (int) $lockedOrder->id)
                ->with(['license'])
                ->get();

            $existingCount = (int) $existingDeliveries->count();
            $deliveredByProduct = $existingDeliveries
                ->filter(fn ($delivery) => !empty($delivery->license_id) && $delivery->license)
                ->groupBy(fn ($delivery) => (int) ($delivery->license->product_id ?? 0))
                ->map(fn ($rows) => (int) $rows->count());

            Log::info('FULFILL ITEMS CHECK', [
                'order_id' => $lockedOrder->id,
                'items_count' => $items->count(),
                'total_qty' => $totalQty,
                'existing_deliveries_count' => $existingCount,
                'legacy_product_id' => $lockedOrder->product_id ?? null,
                'legacy_qty' => $lockedOrder->qty ?? null,
            ]);

            if ($existingCount >= $totalQty && $totalQty > 0) {
                if ($status !== OrderStatus::FULFILLED->value) {
                    $lockedOrder->status = OrderStatus::FULFILLED->value;
                    $lockedOrder->save();
                }

                Log::info('FULFILL SKIP ALREADY_DELIVERED', [
                    'order_id' => $lockedOrder->id,
                    'deliveries_count' => $existingCount,
                    'total_qty' => $totalQty,
                ]);

                return [
                    'ok' => true,
                    'message' => 'Already fulfilled',
                    'mode' => $mode,
                    'totalQty' => $totalQty,
                    'newlyAllocatedQty' => 0,
                    'deliveriesCount' => $existingCount,
                    'shouldQueueProductEmail' => $mode === 'email_only',
                ];
            }

            $newlyAllocatedItems = collect();
            $newlyAllocatedQty = 0;

            foreach ($items as $item) {
                $productId = (int) ($item->product_id ?? 0);
                $orderedQty = max(0, (int) ($item->qty ?? 0));
                $alreadyDeliveredQty = (int) ($deliveredByProduct->get($productId, 0) ?? 0);
                $remainingQty = max(0, $orderedQty - $alreadyDeliveredQty);

                if ($productId <= 0 || $orderedQty <= 0 || $remainingQty <= 0) {
                    continue;
                }

                $licenses = License::query()
                    ->where('product_id', $productId)
                    ->where('status', 'available')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->limit($remainingQty)
                    ->get();

                Log::info('FULFILL STOCK CHECK', [
                    'order_id' => $lockedOrder->id,
                    'product_id' => $productId,
                    'ordered_qty' => $orderedQty,
                    'already_delivered_qty' => $alreadyDeliveredQty,
                    'need_qty' => $remainingQty,
                    'available_found' => $licenses->count(),
                    'license_ids' => $licenses->pluck('id')->all(),
                ]);

                if ($licenses->count() < $remainingQty) {
                    Log::error('FULFILL FAIL STOCK_NOT_ENOUGH', [
                        'order_id' => $lockedOrder->id,
                        'product_id' => $productId,
                        'ordered_qty' => $orderedQty,
                        'already_delivered_qty' => $alreadyDeliveredQty,
                        'need_qty' => $remainingQty,
                        'available_found' => $licenses->count(),
                    ]);

                    return [
                        'ok' => false,
                        'message' => 'Stock not enough for product_id=' . $productId,
                    ];
                }

                foreach ($licenses as $license) {
                    $license->status = 'sold';
                    $license->order_id = $lockedOrder->id;
                    $license->sold_at = now();

                    if (array_key_exists('reserved_order_id', $license->getAttributes())) {
                        $license->reserved_order_id = null;
                    }

                    if (array_key_exists('reserved_at', $license->getAttributes())) {
                        $license->reserved_at = null;
                    }

                    if (array_key_exists('delivered_at', $license->getAttributes())) {
                        $license->delivered_at = now();
                    }

                    $license->save();

                    Delivery::query()->firstOrCreate(
                        [
                            'order_id' => $lockedOrder->id,
                            'license_id' => $license->id,
                        ],
                        [
                            'delivery_mode' => $mode,
                            'reveal_count' => 0,
                            'revealed_at' => null,
                            'emailed_at' => null,
                        ]
                    );
                }

                $newlyAllocatedItems->push((object) [
                    'product_id' => $productId,
                    'qty' => $remainingQty,
                ]);

                $newlyAllocatedQty += $remainingQty;
            }

            if ($newlyAllocatedItems->isNotEmpty()) {
                $this->bumpPurchaseCountAndPopularity($lockedOrder, $newlyAllocatedItems);
            }

            $deliveriesCountAfter = (int) Delivery::query()
                ->where('order_id', (int) $lockedOrder->id)
                ->count();

            if ($deliveriesCountAfter >= $totalQty && $status !== OrderStatus::FULFILLED->value) {
                $lockedOrder->status = OrderStatus::FULFILLED->value;
                $lockedOrder->save();
            }

            Log::info('FULFILL SUCCESS ALLOCATION_DONE', [
                'order_id' => $lockedOrder->id,
                'total_qty' => $totalQty,
                'mode' => $mode,
                'existing_deliveries_count' => $existingCount,
                'newly_allocated_qty' => $newlyAllocatedQty,
                'deliveries_count_after' => $deliveriesCountAfter,
            ]);

            return [
                'ok' => true,
                'message' => $newlyAllocatedQty > 0 ? 'Fulfilled' : 'Already fulfilled',
                'mode' => $mode,
                'totalQty' => $totalQty,
                'newlyAllocatedQty' => $newlyAllocatedQty,
                'deliveriesCount' => $deliveriesCountAfter,
                'shouldQueueProductEmail' => $mode === 'email_only' && $deliveriesCountAfter > 0,
            ];
        });

        if (($result['ok'] ?? false) && (int) ($result['newlyAllocatedQty'] ?? 0) > 0) {
            PublicCache::bumpCatalog();
            PublicCache::bumpDashboard();
        }

        if (($result['ok'] ?? false) && ($result['shouldQueueProductEmail'] ?? false)) {
            Log::info('FULFILL EMAIL_ONLY QUEUE_DISPATCH', [
                'order_id' => $order->id,
                'total_qty' => (int) ($result['totalQty'] ?? 0),
                'newly_allocated_qty' => (int) ($result['newlyAllocatedQty'] ?? 0),
            ]);

            $this->dispatchProductEmailOnce((int) $order->id);
        }

        if (($result['ok'] ?? false) && ((int) ($result['totalQty'] ?? 0) === 1)) {
            Log::info('FULFILL ONE_TIME WAIT_CLOSE_TO_SEND_PRODUCT_EMAIL', [
                'order_id' => $order->id,
            ]);
        }

        return $result;
    }

    private function resolveOrderItems(Order $order): Collection
    {
        $items = $order->items()->get();

        if ($items->isNotEmpty()) {
            return $items;
        }

        $legacyQty = (int) ($order->qty ?? 1);
        $legacyProductId = (int) ($order->product_id ?? 0);

        if ($legacyProductId <= 0 || $legacyQty <= 0) {
            return collect();
        }

        return collect([(object) [
            'product_id' => $legacyProductId,
            'qty' => $legacyQty,
        ]]);
    }

    /**
     * ✅ Naikkan purchases_count dan update popularity_score.
     * - Menggunakan items yang baru dialokasikan agar retry tidak double increment.
     * - Popularity score: (rating * 20) + purchases_count
     */
    private function bumpPurchaseCountAndPopularity(Order $order, Collection $items): void
    {
        try {
            $grouped = $items
                ->groupBy(fn ($it) => (int) ($it->product_id ?? 0))
                ->map(fn ($rows) => (int) $rows->sum(fn ($r) => (int) ($r->qty ?? 1)));

            foreach ($grouped as $productId => $qty) {
                if ($productId <= 0 || $qty <= 0) {
                    continue;
                }

                $product = Product::query()->lockForUpdate()->find($productId);
                if (!$product) {
                    continue;
                }

                $product->purchases_count = ((int) ($product->purchases_count ?? 0)) + $qty;

                $rating = (float) ($product->rating ?? 0);
                $purchases = (int) ($product->purchases_count ?? 0);

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
            Log::error('FULFILL POPULARITY_UPDATE_FAILED', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function formatLicense(License $license): array
    {
        $data = [];

        $data['product_name'] = $license->product?->name ?? null;
        $data['license_key'] = $license->license_key ?? null;

        $payload = [];

        if (!empty($license->metadata)) {
            $meta = $license->metadata;

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
