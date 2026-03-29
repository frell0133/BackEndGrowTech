<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Favorite;
use App\Models\Product;
use App\Services\ProductAvailabilityService;
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

    public function index(Request $request, ProductAvailabilityService $availability)
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

        $data = PublicCache::rememberCatalogProducts($cacheKey, self::INDEX_CACHE_TTL, function () use (
            $search,
            $perPage,
            $categoryId,
            $subcategoryId,
            $sort,
            $dir,
            $availability
        ) {
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
                    'products.purchases_count',
                    'products.popularity_score',
                    'products.created_at',
                ])
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

            $paginator = $query->paginate($perPage);
            $products = $availability->attachToCollection($paginator->getCollection());
            $paginator->setCollection($products);

            return $paginator->toArray();
        });

        return $this->ok($data);
    }

    public function show(Product $product, ProductAvailabilityService $availability)
    {
        if (!$product->is_active || !$product->is_published) {
            return $this->fail('Product not found', 404);
        }

        $data = PublicCache::rememberCatalogProducts('products:show:' . $product->id, self::SHOW_CACHE_TTL, function () use ($product, $availability) {
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
                    'favorites',
                ])
                ->whereKey($product->id)
                ->where('is_active', true)
                ->where('is_published', true)
                ->first();

            if (!$fresh) {
                return null;
            }

            $fresh->setAttribute('available_stock', $availability->forProductId((int) $fresh->id));

            return $fresh->toArray();
        });

        if (!$data) {
            return $this->fail('Product not found', 404);
        }

        return $this->ok($data);
    }
}
