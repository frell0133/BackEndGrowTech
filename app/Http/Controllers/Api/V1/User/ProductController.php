<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Models\License;
use App\Models\Product;
use App\Support\ApiResponse;
use App\Support\PublicCache;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    use ApiResponse;

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
        $perPage = max(1, min((int) $request->query('per_page', 20), 50));

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

        $data = PublicCache::rememberCatalog($cacheKey, 60, function () use (
            $search,
            $perPage,
            $categoryId,
            $subcategoryId,
            $sort,
            $dir
        ) {
            $query = Product::query()
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
                    'licenses as available_stock' => function ($q) {
                        $q->where('status', License::STATUS_AVAILABLE);
                    },
                ])
                ->when($sort === 'favorite', fn ($q) => $q->withCount('favorites'))
                ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
                ->when($subcategoryId, fn ($q) => $q->where('subcategory_id', $subcategoryId))
                ->when($search !== '', fn ($q) => $q->where(function ($w) use ($search) {
                    $w->where('name', 'ilike', "%{$search}%")
                        ->orWhere('slug', 'ilike', "%{$search}%");
                }))
                ->where('is_active', true)
                ->where('is_published', true);

            switch ($sort) {
                case 'bestseller':
                    $query->orderBy('purchases_count', $dir)
                        ->orderBy('popularity_score', $dir)
                        ->orderByDesc('id');
                    break;

                case 'popular':
                    $query->orderBy('popularity_score', $dir)
                        ->orderBy('purchases_count', $dir)
                        ->orderByDesc('id');
                    break;

                case 'rating':
                    $query->orderBy('rating', $dir)
                        ->orderBy('rating_count', $dir)
                        ->orderByDesc('id');
                    break;

                case 'favorite':
                    $query->orderBy('favorites_count', $dir)
                        ->orderBy('popularity_score', $dir)
                        ->orderByDesc('id');
                    break;

                case 'latest':
                default:
                    $query->latest();
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

        $data = PublicCache::rememberCatalog('products:show:' . $product->id, 60, function () use ($product) {
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