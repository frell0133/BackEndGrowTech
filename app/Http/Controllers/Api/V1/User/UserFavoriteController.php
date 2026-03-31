<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Favorite;
use App\Models\Order;
use App\Models\Product;
use App\Support\ApiResponse;
use App\Support\PublicCache;
use App\Support\RuntimeCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserFavoriteController extends Controller
{
    use ApiResponse;

    private const INDEX_MAX_PER_PAGE = 50;
    private const INDEX_TTL = 10;
    private const INDEX_VERSION_PREFIX = 'favorites:index:version:user:';

    public function index(Request $request)
    {
        $user = $request->user();
        $perPage = max(1, min((int) $request->query('per_page', 20), self::INDEX_MAX_PER_PAGE));
        $page = max(1, (int) $request->query('page', 1));
        $version = $this->currentIndexVersion((int) $user->id);

        $cacheKey = sprintf(
            'favorites:index:user:%d:v:%d:page:%d:per_page:%d',
            (int) $user->id,
            $version,
            $page,
            $perPage
        );

        $data = RuntimeCache::remember($cacheKey, self::INDEX_TTL, function () use ($user, $perPage) {
            return Favorite::query()
                ->select([
                    'id',
                    'user_id',
                    'product_id',
                    'rating',
                    'created_at',
                ])
                ->where('user_id', $user->id)
                ->with([
                    'product:id,category_id,subcategory_id,name,slug,rating,rating_count,available_stock',
                    'product.subcategory:id,category_id,name,slug,image_url',
                ])
                ->orderByDesc('created_at')
                ->paginate($perPage)
                ->toArray();
        });

        return $this->ok($data);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        $v = $request->validate([
            'product_id' => ['required', 'integer', 'min:1'],
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
        ]);

        $productId = (int) $v['product_id'];
        $rating = isset($v['rating']) ? (int) $v['rating'] : null;

        $product = Product::query()->findOrFail($productId);

        if (!is_null($rating)) {
            $purchased = $this->hasPurchasedProduct($user->id, $productId);
            if (!$purchased) {
                return $this->fail('Rating hanya bisa diberikan setelah membeli produk ini.', 403);
            }
        }

        DB::transaction(function () use ($user, $product, $rating) {
            Favorite::query()->updateOrCreate(
                ['user_id' => $user->id, 'product_id' => $product->id],
                ['rating' => $rating]
            );

            $this->refreshProductRatingAggregate($product->id, (int) ($product->purchases_count ?? 0));
        });

        $this->bumpIndexVersion((int) $user->id);
        PublicCache::bumpCatalogProducts();

        return $this->ok(['message' => 'Favorite saved']);
    }

    public function destroy(Request $request, int $productId)
    {
        $user = $request->user();

        $fav = Favorite::query()
            ->where('user_id', $user->id)
            ->where('product_id', $productId)
            ->first();

        if (!$fav) {
            return $this->ok(['message' => 'Already not favorited']);
        }

        $product = Product::query()->find($productId);

        DB::transaction(function () use ($fav, $product) {
            $fav->delete();

            if ($product) {
                $this->refreshProductRatingAggregate($product->id, (int) ($product->purchases_count ?? 0));
            }
        });

        $this->bumpIndexVersion((int) $user->id);
        PublicCache::bumpCatalogProducts();

        return $this->ok(['message' => 'Removed from favorites']);
    }

    private function refreshProductRatingAggregate(int $productId, int $purchases): void
    {
        $agg = Favorite::query()
            ->where('product_id', $productId)
            ->whereNotNull('rating')
            ->selectRaw('COUNT(*) as rating_count, COALESCE(AVG(rating), 0) as rating_avg')
            ->first();

        $ratingCount = (int) ($agg->rating_count ?? 0);
        $rating = round((float) ($agg->rating_avg ?? 0), 2);
        $popularityScore = ($rating * 20) + $purchases;

        Product::query()
            ->whereKey($productId)
            ->update([
                'rating_count' => $ratingCount,
                'rating' => $rating,
                'popularity_score' => $popularityScore,
            ]);
    }

    private function hasPurchasedProduct(int $userId, int $productId): bool
    {
        return Order::query()
            ->where('user_id', $userId)
            ->whereIn('status', [OrderStatus::PAID, OrderStatus::FULFILLED])
            ->where(function ($q) use ($productId) {
                $q->where('product_id', $productId)
                    ->orWhereHas('items', function ($qq) use ($productId) {
                        $qq->where('product_id', $productId);
                    });
            })
            ->exists();
    }

    private function currentIndexVersion(int $userId): int
    {
        $key = self::INDEX_VERSION_PREFIX . $userId;
        $value = RuntimeCache::get($key);

        if (!$value) {
            RuntimeCache::forever($key, 1);
            return 1;
        }

        return (int) $value;
    }

    private function bumpIndexVersion(int $userId): void
    {
        $key = self::INDEX_VERSION_PREFIX . $userId;

        if (!RuntimeCache::has($key)) {
            RuntimeCache::forever($key, 1);
        }

        RuntimeCache::increment($key);
    }
}
