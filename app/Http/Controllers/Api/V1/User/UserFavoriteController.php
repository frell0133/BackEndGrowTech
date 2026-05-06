<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Favorite;
use App\Models\Order;
use App\Models\Product;
use App\Services\ProductAvailabilityService;
use App\Support\ApiResponse;
use App\Support\PublicCache;
use App\Support\RuntimeCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserFavoriteController extends Controller
{
    use ApiResponse;

    private const INDEX_MAX_PER_PAGE = 100;
    private const INDEX_TTL = 10;
    private const INDEX_VERSION_PREFIX = 'favorites:index:version:user:';

    public function index(Request $request)
    {
        $user = $request->user();
        $perPage = max(1, min((int) $request->query('per_page', 20), self::INDEX_MAX_PER_PAGE));
        $page = max(1, (int) $request->query('page', 1));
        $scope = $request->query('scope', 'favorited');
        $includeAll = $scope === 'all';
        $version = $this->currentIndexVersion((int) $user->id);

        $cacheKey = sprintf(
            'favorites:index:user:%d:v:%d:page:%d:per_page:%d:scope:%s',
            (int) $user->id,
            $version,
            $page,
            $perPage,
            $includeAll ? 'all' : 'favorited'
        );

        $data = RuntimeCache::remember($cacheKey, self::INDEX_TTL, function () use ($user, $perPage, $includeAll) {
            $query = Favorite::query()
                ->select([
                    'id',
                    'user_id',
                    'product_id',
                    'rating',
                    'is_favorited',
                    'created_at',
                ])
                ->where('user_id', $user->id);

            if (!$includeAll) {
                $query->where('is_favorited', true);
            }

            return $query
                ->with([
                    'product:id,category_id,subcategory_id,name,slug,rating,rating_count,is_active,is_published',
                    'product.subcategory:id,category_id,name,slug,image_url',
                ])
                ->orderByDesc('created_at')
                ->paginate($perPage)
                ->toArray();
        });

        $availability = app(ProductAvailabilityService::class);

        if (!empty($data['data']) && is_array($data['data'])) {
            foreach ($data['data'] as &$favorite) {
                $product = $favorite['product'] ?? null;

                if (!$product || empty($product['id'])) {
                    continue;
                }

                $product['available_stock'] = (int) $availability->forProductId((int) $product['id']);
                $favorite['product'] = $product;
            }
            unset($favorite);
        }

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

            $alreadyRated = Favorite::query()
                ->where('user_id', $user->id)
                ->where('product_id', $productId)
                ->whereNotNull('rating')
                ->exists();

            if ($alreadyRated) {
                return $this->fail('Rating produk sudah terkunci dan tidak dapat diubah.', 409);
            }
        }

        DB::transaction(function () use ($user, $product, $rating) {
            $favorite = Favorite::query()
                ->where('user_id', $user->id)
                ->where('product_id', $product->id)
                ->first();

            if ($favorite) {
                $payload = [];

                if (is_null($rating)) {
                    $payload['is_favorited'] = true;
                } else {
                    $payload['rating'] = $rating;
                }

                if (!empty($payload)) {
                    $favorite->fill($payload)->save();
                }
            } else {
                Favorite::query()->create([
                    'user_id' => $user->id,
                    'product_id' => $product->id,
                    'rating' => $rating,
                    'is_favorited' => is_null($rating),
                ]);
            }

            $this->refreshProductRatingAggregate($product->id, (int) ($product->purchases_count ?? 0));
        });

        $this->bumpIndexVersion((int) $user->id);
        PublicCache::bumpCatalogProducts();

        return $this->ok([
            'message' => is_null($rating)
                ? 'Produk berhasil ditambahkan ke favorite.'
                : 'Rating produk berhasil disimpan dan tidak dapat diubah lagi.',
            'can_edit_rating' => false,
            'rating_locked' => !is_null($rating),
        ]);
    }

    public function destroy(Request $request, int $productId)
    {
        $user = $request->user();

        $fav = Favorite::query()
            ->where('user_id', $user->id)
            ->where('product_id', $productId)
            ->first();

        if (!$fav) {
            return $this->ok(['message' => 'Produk sudah tidak ada di favorite.']);
        }

        $product = Product::query()->find($productId);

        DB::transaction(function () use ($fav, $product) {
            if (!is_null($fav->rating)) {
                $fav->is_favorited = false;
                $fav->save();
            } else {
                $fav->delete();
            }

            if ($product) {
                $this->refreshProductRatingAggregate($product->id, (int) ($product->purchases_count ?? 0));
            }
        });

        $this->bumpIndexVersion((int) $user->id);
        PublicCache::bumpCatalogProducts();

        return $this->ok(['message' => 'Produk berhasil dihapus dari favorite.']);
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
