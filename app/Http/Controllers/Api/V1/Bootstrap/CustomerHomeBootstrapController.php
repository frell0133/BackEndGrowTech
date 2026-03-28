<?php

namespace App\Http\Controllers\Api\V1\Bootstrap;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Models\License;
use App\Models\Product;
use App\Models\Popup;
use App\Services\SystemAccessService;
use App\Support\ApiResponse;
use App\Support\PublicCache;
use Illuminate\Support\Facades\DB;

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

    private function getPopularProducts(): array
    {
        return PublicCache::rememberCatalog('bootstrap:customer-home:popular-products', 300, function () {
            $availableStockSub = License::query()
                ->selectRaw('product_id, COUNT(*) as available_stock')
                ->where('status', License::STATUS_AVAILABLE)
                ->groupBy('product_id');

            return Product::query()
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
                    DB::raw('COALESCE(stock_counts.available_stock, 0) as available_stock'),
                ])
                ->leftJoinSub($availableStockSub, 'stock_counts', function ($join) {
                    $join->on('stock_counts.product_id', '=', 'products.id');
                })
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
                ->get()
                ->toArray();
        });
    }
}
