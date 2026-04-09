<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\ProductAvailabilityService;
use App\Support\ApiResponse;
use App\Support\PublicCache;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    use ApiResponse;

    private const INDEX_CACHE_TTL = 300;
    private const SHOW_CACHE_TTL = 300;
    private const INDEX_MAX_PER_PAGE = 30;
    private const TIER_KEYS = ['member', 'reseller', 'vip'];

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

    private function applyVisibleCatalogGuard($query)
    {
        return $query
            ->where('products.is_active', true)
            ->where('products.is_published', true)
            ->whereHas('category', fn ($q) => $q->where('is_active', true))
            ->where(function ($q) {
                $q->whereNull('products.subcategory_id')
                    ->orWhereHas('subcategory', fn ($sq) => $sq->where('is_active', true));
            });
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

        $favoritesCount = (int) ($data['favorites_count'] ?? 0);
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
        $data['favorites_count'] = $favoritesCount;
        $data['available_stock'] = (int) $stock;
        $data['stock'] = (int) $stock;
        $data['sold'] = $purchasesCount;
        $data['purchases_count'] = $purchasesCount;

        return $data;
    }

    public function index(Request $request, ProductAvailabilityService $availability)
    {
        $search = trim((string) $request->query('q', ''));
        $perPage = max(1, min((int) $request->query('per_page', 20), self::INDEX_MAX_PER_PAGE));

        $categoryId = $request->query('category_id');
        $subcategoryId = $request->query('subcategory_id') ?? $request->query('subcategory');

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
                    'products.tier_profit',
                    'products.duration_days',
                    'products.price',
                    'products.rating',
                    'products.rating_count',
                    'products.purchases_count',
                    'products.popularity_score',
                    'products.created_at',
                ])
                ->with([
                    'category:id,name,slug,is_active',
                    'subcategory:id,category_id,name,description,slug,provider,image_url,is_active',
                ])
                ->withCount('favorites')
                ->when($categoryId, fn ($q) => $q->where('products.category_id', $categoryId))
                ->when($subcategoryId, fn ($q) => $q->where('products.subcategory_id', $subcategoryId))
                ->when($search !== '', function ($q) use ($search) {
                    $q->where(function ($w) use ($search) {
                        $w->where('products.name', 'ilike', "%{$search}%")
                            ->orWhere('products.slug', 'ilike', "%{$search}%");
                    });
                });

            $query = $this->applyVisibleCatalogGuard($query);

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
            $collection = $availability->attachToCollection($paginator->getCollection());

            $paginator->setCollection(
                $collection->map(fn ($product) => $this->presentProduct($product, (int) data_get($product, 'available_stock', 0)))
            );

            return $paginator->toArray();
        });

        return $this->ok($data);
    }

    public function show(Product $product, ProductAvailabilityService $availability)
    {
        if (!$product->is_active || !$product->is_published) {
            return $this->fail('Product not found', 404);
        }

        if (($product->category && !$product->category->is_active) || ($product->subcategory && !$product->subcategory->is_active)) {
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
                    'tier_profit',
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
                    'category:id,name,slug,is_active',
                    'subcategory:id,category_id,name,description,slug,provider,image_url,image_path,is_active',
                ])
                ->withCount('favorites')
                ->whereKey($product->id)
                ->where('is_active', true)
                ->where('is_published', true)
                ->whereHas('category', fn ($q) => $q->where('is_active', true))
                ->where(function ($q) {
                    $q->whereNull('subcategory_id')
                        ->orWhereHas('subcategory', fn ($sq) => $sq->where('is_active', true));
                })
                ->first();

            if (!$fresh) {
                return null;
            }

            return $this->presentProduct($fresh, $availability->forProductId((int) $fresh->id));
        });

        if (!$data) {
            return $this->fail('Product not found', 404);
        }

        return $this->ok($data);
    }
}
