<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Favorite;
use App\Models\Order;
use App\Models\Product;
use App\Support\ApiResponse;
use App\Support\PublicCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserFavoriteController extends Controller
{
    use ApiResponse;

    private const INDEX_MAX_PER_PAGE = 50;

    public function index(Request $request)
    {
        $user = $request->user();
        $perPage = max(1, min((int) $request->query('per_page', 20), self::INDEX_MAX_PER_PAGE));

        $data = Favorite::query()
            ->select([
                'id',
                'user_id',
                'product_id',
                'rating',
                'created_at',
            ])
            ->where('user_id', $user->id)
            ->with([
                'product:id,category_id,subcategory_id,name,slug,rating,rating_count',
                'product.subcategory:id,category_id,name,slug,image_url',
            ])
            ->orderByDesc('created_at')
            ->paginate($perPage);

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
}
