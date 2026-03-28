<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Favorite;
use App\Models\License;
use App\Models\Product;
use App\Support\ApiResponse;
use App\Support\PublicCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    use ApiResponse;

    private const INDEX_CACHE_TTL = 300;
    private const SHOW_CACHE_TTL = 300;
    private const INDEX_MAX_PER_PAGE = 30;

    private function normalizeSort(string $sort): string
    {
        $sort = strtolower(trim($sort));

        return match ($sort) {
            'terlaris' => 'bestseller',
            'favorit' => 'favorite',
            default => $sort ?: 'latest',
        };
    }

    private function buildIndexCacheKey(
        Request $request,
        string $search,
        int $perPage,
        mixed $categoryId,
        mixed $subcategoryId,
        string $sort,
        string $dir
    ): string {
        $params = [
            'q' => $search,
            'per_page' => $perPage,
            'page' => (int) $request->query('page', 1),
            'category_id' => $categoryId !== null ? (string) $categoryId : '',
            'subcategory_id' => $subcategoryId !== null ? (string) $subcategoryId : '',
            'sort' => $sort,
            'dir' => $dir,
        ];

        ksort($params);

        return 'products:index:' . md5(http_build_query($params));
    }

    public function index(Request $request)
    {
        $search = trim((string) $request->query('q', ''));
        $perPage = max(1, min((int) $request->query('per_page', 20), self::INDEX_MAX_PER_PAGE));

        $categoryId = $request->query('category_id');
        $subcategoryId = $request->query('subcategory_id');

        $sort = $this->normalizeSort((string) $request->query('sort', 'latest'));
        $dir = strtolower((string) $request->query('dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        $cacheKey = $this->buildIndexCacheKey(
            $request,
            $search,
            $perPage,
            $categoryId,
            $subcategoryId,
            $sort,
            $dir
        );

        $data = PublicCache::rememberCatalog($cacheKey, self::INDEX_CACHE_TTL, function () use (
            $search,
            $perPage,
            $categoryId,
            $subcategoryId,
            $sort,
            $dir
        ) {
            $availableStockSub = License::query()
                ->selectRaw('product_id, COUNT(*) as available_stock')
                ->where('status', License::STATUS_AVAILABLE)
                ->groupBy('product_id');

            $favoriteCountSub = Favorite::query()
                ->selectRaw('product_id, COUNT(*) as favorites_count')
                ->groupBy('product_id');

            $query = Product::query()
                ->select([
                    'products.id',
                    'products.category_id',
                    'products.subcategory_id',
                    'products.name',
                    'products.slug',
                    'products.type',
                    'products.description',
                    'products.tier_pricing',
                    'products.price',
                    'products.rating',
                    'products.rating_count',
                    DB::raw('COALESCE(stock_counts.available_stock, 0) as available_stock'),
                ])
                ->leftJoinSub($availableStockSub, 'stock_counts', function ($join) {
                    $join->on('stock_counts.product_id', '=', 'products.id');
                })
                ->with([
                    'category:id,name,slug',
                    'subcategory:id,category_id,name,description,slug,provider,image_url',
                ])
                ->when($sort === 'favorite', function ($query) use ($favoriteCountSub) {
                    $query->leftJoinSub($favoriteCountSub, 'favorite_counts', function ($join) {
                        $join->on('favorite_counts.product_id', '=', 'products.id');
                    })->addSelect(DB::raw('COALESCE(favorite_counts.favorites_count, 0) as favorites_count'));
                })
                ->when($categoryId, fn ($q) => $q->where('products.category_id', $categoryId))
                ->when($subcategoryId, fn ($q) => $q->where('products.subcategory_id', $subcategoryId))
                ->when($search !== '', function ($q) use ($search) {
                    $q->where(function ($w) use ($search) {
                        $w->where('products.name', 'ilike', "%{$search}%")
                            ->orWhere('products.slug', 'ilike', "%{$search}%");
                    });
                })
                ->where('products.is_active', true)
                ->where('products.is_published', true);

            switch ($sort) {
                case 'bestseller':
                    $query->orderBy('products.purchases_count', $dir)
                        ->orderBy('products.popularity_score', $dir)
                        ->orderByDesc('products.id');
                    break;

                case 'popular':
                    $query->orderBy('products.popularity_score', $dir)
                        ->orderBy('products.purchases_count', $dir)
                        ->orderByDesc('products.id');
                    break;

                case 'rating':
                    $query->orderBy('products.rating', $dir)
                        ->orderBy('products.rating_count', $dir)
                        ->orderByDesc('products.id');
                    break;

                case 'favorite':
                    $query->orderBy('favorites_count', $dir)
                        ->orderBy('products.popularity_score', $dir)
                        ->orderByDesc('products.id');
                    break;

                case 'latest':
                default:
                    $query->orderByDesc('products.created_at')
                        ->orderByDesc('products.id');
                    break;
            }

            return $query->paginate($perPage)->toArray();
        });

        return $this->ok($data);
    }

    public function show(Product $product)
    {
        if (!$product->is_active || !$product->is_published) {
            return $this->fail('Product not found', 404);
        }

        $data = PublicCache::rememberCatalog('products:show:' . $product->id, self::SHOW_CACHE_TTL, function () use ($product) {
            $fresh = Product::query()
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
                    'updated_at',
                ])
                ->with([
                    'category:id,name,slug',
                    'subcategory:id,category_id,name,description,slug,provider,image_url,image_path',
                ])
                ->withCount([
                    'licenses as available_stock' => function ($q) {
                        $q->where('status', License::STATUS_AVAILABLE);
                    },
                    'favorites',
                ])
                ->whereKey($product->id)
                ->where('is_active', true)
                ->where('is_published', true)
                ->first();

            return $fresh?->toArray();
        });

        if (!$data) {
            return $this->fail('Product not found', 404);
        }

        return $this->ok($data);
    }
}
