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
                    'category:id,name,slug',
                    'subcategory:id,category_id,name,description,slug,provider,image_url,image_path',
                ])
                ->where('products.is_active', true)
                ->where('products.is_published', true)
                ->orderByDesc('products.popularity_score')
                ->orderByDesc('products.purchases_count')
                ->orderByDesc('products.id')
                ->limit(4)
                ->get();

            return $availability
                ->attachToCollection($products)
                ->values()
                ->toArray();
        });
    }
}
