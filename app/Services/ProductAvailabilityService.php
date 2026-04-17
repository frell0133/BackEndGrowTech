<?php

namespace App\Services;

use App\Models\License;
use App\Models\ProductStock;
use Illuminate\Support\Collection;

class ProductAvailabilityService
{
    public function forProductIds(array $productIds): array
    {
        $productIds = collect($productIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        if (empty($productIds)) {
            return [];
        }

        $licenseCounts = License::query()
            ->selectRaw('product_id, COUNT(*) as total')
            ->whereIn('product_id', $productIds)
            ->where('status', License::STATUS_AVAILABLE)
            ->whereNull('reserved_order_id')
            ->groupBy('product_id')
            ->pluck('total', 'product_id')
            ->map(fn ($total) => (int) $total)
            ->toArray();

        $productStockCounts = ProductStock::query()
            ->selectRaw('product_id, COUNT(*) as total')
            ->whereIn('product_id', $productIds)
            ->where('status', 'available')
            ->whereNull('reserved_order_id')
            ->groupBy('product_id')
            ->pluck('total', 'product_id')
            ->map(fn ($total) => (int) $total)
            ->toArray();

        $result = [];

        foreach ($productIds as $productId) {
            $licenseCount = (int) ($licenseCounts[$productId] ?? 0);
            $productStockCount = (int) ($productStockCounts[$productId] ?? 0);

            $result[$productId] = $licenseCount > 0
                ? $licenseCount
                : $productStockCount;
        }

        return $result;
    }

    public function forProductId(int $productId): int
    {
        return (int) ($this->forProductIds([$productId])[$productId] ?? 0);
    }

    public function attachToCollection(Collection $products, string $attribute = 'available_stock'): Collection
    {
        $stockMap = $this->forProductIds(
            $products->pluck('id')->map(fn ($id) => (int) $id)->all()
        );

        return $products->map(function ($product) use ($stockMap, $attribute) {
            $productId = (int) data_get($product, 'id', 0);
            $available = (int) ($stockMap[$productId] ?? 0);

            if (is_array($product)) {
                $product[$attribute] = $available;
                return $product;
            }

            $product->setAttribute($attribute, $available);
            return $product;
        });
    }
}
