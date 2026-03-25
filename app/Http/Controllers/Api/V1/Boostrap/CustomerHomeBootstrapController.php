<?php

namespace App\Http\Controllers\Api\V1\Bootstrap;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Models\License;
use App\Models\Popup;
use App\Models\Product;
use App\Services\SystemAccessService;
use App\Support\ApiResponse;
use App\Support\PublicCache;

class CustomerHomeBootstrapController extends Controller
{
    use ApiResponse;

    public function __invoke(SystemAccessService $access)
    {
        $catalogEnabled = $access->enabled('catalog_access');
        $catalogMaintenance = $catalogEnabled
            ? ''
            : $access->message('catalog_access', 'Katalog sedang maintenance.');

        $banners = $this->getBanners();
        $popup = $this->getPopup();
        $products = $catalogEnabled ? $this->getPopularProducts() : [];

        return $this->ok([
            'popup' => $popup,
            'banners' => $banners,
            'products' => $products,
            'catalog_maintenance' => $catalogMaintenance,
        ]);
    }

    private function getBanners(): array
    {
        return PublicCache::rememberContent('bootstrap:customer-home:banners', 120, function () {
            return Banner::query()
                ->where('is_active', true)
                ->whereNotNull('image_path')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'image_path', 'sort_order'])
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
        return PublicCache::rememberContent('bootstrap:customer-home:popup', 60, function () {
            $popup = Popup::query()
                ->where('is_active', true)
                ->whereIn('target', ['all', 'customer'])
                ->orderByRaw("CASE WHEN target = ? THEN 0 ELSE 1 END", ['customer'])
                ->orderByDesc('id')
                ->first();

            return $popup?->toArray();
        });
    }

    private function getPopularProducts(): array
    {
        return PublicCache::rememberCatalog('bootstrap:customer-home:popular-products', 60, function () {
            return Product::query()
                ->select([
                    'id',
                    'category_id',
                    'subcategory_id',
                    'name',
                    'slug',
                    'type',
                    'description',
                    'tier_pricing',
                    'duration_days',
                    'price',
                    'is_active',
                    'is_published',
                    'rating',
                    'rating_count',
                    'purchases_count',
                    'popularity_score',
                    'created_at',
                ])
                ->with([
                    'category:id,name,slug',
                    'subcategory:id,category_id,name,description,slug,provider,image_url,image_path',
                ])
                ->withCount([
                    'licenses as available_stock' => function ($query) {
                        $query->where('status', License::STATUS_AVAILABLE);
                    },
                ])
                ->where('is_active', true)
                ->where('is_published', true)
                ->orderByDesc('popularity_score')
                ->orderByDesc('purchases_count')
                ->orderByDesc('id')
                ->limit(4)
                ->get()
                ->toArray();
        });
    }
}