<?php

namespace App\Http\Controllers\Api\V1\Bootstrap;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Models\Product;
use App\Models\Popup;
use App\Services\ProductAvailabilityService;
use App\Services\SystemAccessService;
use App\Support\ApiResponse;
use App\Support\PublicCache;

class CustomerHomeBootstrapController extends Controller
{
    use ApiResponse;

    private const TIER_KEYS = ['member', 'reseller', 'vip'];

    public function __invoke(SystemAccessService $access, ProductAvailabilityService $availability)
    {
        $payload = PublicCache::rememberContent('bootstrap:customer-home:payload', 60, function () use ($access, $availability) {
            $catalogAccess = $access->get('catalog_access');
            $catalogEnabled = (bool) ($catalogAccess['enabled'] ?? true);
            $catalogMaintenance = $catalogEnabled
                ? ''
                : (string) ($catalogAccess['message'] ?? 'Katalog sedang maintenance.');

            $banners = $this->getBanners();
            $popup = $this->getPopup();
            $products = $catalogEnabled ? $this->getPopularProducts($availability) : [];

            return [
                'popup' => $popup,
                'banners' => $banners,
                'products' => $products,
                'catalog_maintenance' => $catalogMaintenance,
            ];
        });

        return $this->ok($payload);
    }

    private function normalizeTierMap(mixed $value): array
    {
        $rawMap = is_array($value) ? $value : [];
        $normalized = [];

        foreach (self::TIER_KEYS as $key) {
            $normalized[$key] = max(0, (int) round((float) ($rawMap[$key] ?? 0)));
        }

        return $normalized;
    }

    private function buildTierFinalPricing(array $tierPricing, array $tierProfit): array
    {
        $final = [];

        foreach (self::TIER_KEYS as $key) {
            $final[$key] = (int) (($tierPricing[$key] ?? 0) + ($tierProfit[$key] ?? 0));
        }

        return $final;
    }

    private function presentProduct(mixed $product, ?int $availableStock = null): array
    {
        $data = is_array($product) ? $product : $product->toArray();

        $tierPricing = $this->normalizeTierMap($data['tier_pricing'] ?? []);
        $tierProfit = $this->normalizeTierMap($data['tier_profit'] ?? []);
        $tierFinalPricing = $this->buildTierFinalPricing($tierPricing, $tierProfit);

        $memberBase = (int) ($tierPricing['member'] ?? 0);
        $memberProfit = (int) ($tierProfit['member'] ?? 0);
        $memberFinal = (int) ($tierFinalPricing['member'] ?? ($memberBase + $memberProfit));
        $purchasesCount = (int) ($data['purchases_count'] ?? 0);
        $stock = $availableStock ?? (int) ($data['available_stock'] ?? 0);

        $data['tier_pricing'] = $tierPricing;
        $data['tier_profit'] = $tierProfit;
        $data['tier_final_pricing'] = $tierFinalPricing;
        $data['display_price'] = $memberFinal;
        $data['display_price_breakdown'] = [
            'base_price' => $memberBase,
            'profit' => $memberProfit,
            'final_price' => $memberFinal,
        ];
        $data['available_stock'] = (int) $stock;
        $data['stock'] = (int) $stock;
        $data['sold'] = $purchasesCount;
        $data['purchases_count'] = $purchasesCount;

        return $data;
    }

    private function getBanners(): array
    {
        return PublicCache::rememberContent('bootstrap:customer-home:banners', 300, function () {
            return Banner::query()
                ->select(['id', 'image_path', 'sort_order'])
                ->where('is_active', true)
                ->whereNotNull('image_path')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(function (Banner $banner) {
                    return [
                        'id' => $banner->id,
                        'sort_order' => $banner->sort_order,
                        'image_url' => $banner->image_url,
                    ];
                })
                ->values()
                ->all();
        });
    }

    private function getPopup(): ?array
    {
        return PublicCache::rememberContent('bootstrap:customer-home:popup', 120, function () {
            $popup = Popup::query()
                ->where('is_active', true)
                ->whereIn('target', ['all', 'customer'])
                ->orderByRaw("CASE WHEN target = ? THEN 0 ELSE 1 END", ['customer'])
                ->orderByDesc('id')
                ->first();

            return $popup?->toArray();
        });
    }

    private function getPopularProducts(ProductAvailabilityService $availability): array
    {
        return PublicCache::rememberCatalogProducts('bootstrap:customer-home:popular-products', 300, function () use ($availability) {
            $products = Product::query()
                ->select([
                    'products.id',
                    'products.category_id',
                    'products.subcategory_id',
                    'products.name',
                    'products.slug',
                    'products.type',
                    'products.description',
                    'products.tier_pricing',
                    'products.tier_profit',
                    'products.duration_days',
                    'products.price',
                    'products.is_active',
                    'products.is_published',
                    'products.rating',
                    'products.rating_count',
                    'products.purchases_count',
                    'products.popularity_score',
                    'products.created_at',
                ])
                ->with([
                    'category:id,name,slug,is_active',
                    'subcategory:id,category_id,name,description,slug,provider,image_url,image_path,is_active',
                ])
                ->where('products.is_active', true)
                ->where('products.is_published', true)
                ->whereHas('category', fn ($q) => $q->where('is_active', true))
                ->where(function ($q) {
                    $q->whereNull('products.subcategory_id')
                        ->orWhereHas('subcategory', fn ($sq) => $sq->where('is_active', true));
                })
                ->orderByDesc('products.popularity_score')
                ->orderByDesc('products.purchases_count')
                ->orderByDesc('products.rating')
                ->orderByDesc('products.rating_count')
                ->orderByDesc('products.id')
                ->limit(4)
                ->get();

            return $availability
                ->attachToCollection($products)
                ->map(fn ($product) => $this->presentProduct($product, (int) data_get($product, 'available_stock', 0)))
                ->values()
                ->all();
        });
    }
}
