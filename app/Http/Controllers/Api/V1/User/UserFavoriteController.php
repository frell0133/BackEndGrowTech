<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Favorite;
use App\Models\Product;
use App\Models\Order;
use App\Enums\OrderStatus;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserFavoriteController extends Controller
{
    use ApiResponse;

    // GET /api/v1/favorites
    public function index(Request $request)
    {
        $user = $request->user();

        $data = Favorite::query()
            ->where('user_id', $user->id)
            ->with(['product.category:id,name,slug', 'product.subcategory:id,category_id,name,slug,provider,image_url,image_path'])
            ->latest()
            ->paginate((int) $request->query('per_page', 20));

        return $this->ok($data);
    }

    /**
     * POST /api/v1/favorites
     * body: { "product_id": 1, "rating": 1..5 (optional) }
     *
     * - Favorite boleh kapan saja
     * - Rating hanya boleh kalau sudah pernah beli (order PAID/FULFILLED)
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $v = $request->validate([
            'product_id' => ['required','integer','min:1'],
            'rating' => ['nullable','integer','min:1','max:5'],
        ]);

        $productId = (int) $v['product_id'];
        $rating    = isset($v['rating']) ? (int) $v['rating'] : null;

        // pastikan product ada
        $product = Product::query()->findOrFail($productId);

        // ✅ Jika user mengirim rating, wajib sudah pernah beli
        if (!is_null($rating)) {
            $purchased = $this->hasPurchasedProduct($user->id, $productId);
            if (!$purchased) {
                return $this->fail('Rating hanya bisa diberikan setelah membeli produk ini.', 403);
            }
        }

        DB::transaction(function () use ($user, $product, $rating) {
            // favorite selalu boleh: updateOrCreate
            Favorite::query()->updateOrCreate(
                ['user_id' => $user->id, 'product_id' => $product->id],
                ['rating' => $rating] // kalau null, berarti favorite tanpa rating
            );

            // ✅ Update agregat rating product hanya dari favorites yg punya rating (dan sudah lolos validasi purchase)
            $agg = Favorite::query()
                ->where('product_id', $product->id)
                ->whereNotNull('rating')
                ->selectRaw('COUNT(*) as c, AVG(rating) as a')
                ->first();

            $product->rating_count = (int) ($agg->c ?? 0);
            $product->rating = (float) ($agg->a ?? 0);

            // kalau kamu sudah punya kolom populer:
            $purchases = (int) ($product->purchases_count ?? 0);
            $product->popularity_score = ((float) $product->rating * 20) + $purchases;

            $product->save();
        });

        return $this->ok(['message' => 'Favorite saved']);
    }

    // DELETE /api/v1/favorites/{productId}
    public function destroy(Request $request, int $productId)
    {
        $user = $request->user();

        $fav = Favorite::query()
            ->where('user_id', $user->id)
            ->where('product_id', $productId)
            ->first();

        if (!$fav) return $this->ok(['message' => 'Already not favorited']);

        $product = Product::query()->find($productId);

        DB::transaction(function () use ($fav, $product) {
            $fav->delete();

            if ($product) {
                $agg = Favorite::query()
                    ->where('product_id', $product->id)
                    ->whereNotNull('rating')
                    ->selectRaw('COUNT(*) as c, AVG(rating) as a')
                    ->first();

                $product->rating_count = (int) ($agg->c ?? 0);
                $product->rating = (float) ($agg->a ?? 0);

                $purchases = (int) ($product->purchases_count ?? 0);
                $product->popularity_score = ((float) $product->rating * 20) + $purchases;

                $product->save();
            }
        });

        return $this->ok(['message' => 'Removed from favorites']);
    }

    /**
     * ✅ Cek apakah user pernah membeli product ini (PAID/FULFILLED)
     * Support:
     * - order_items (flow cart baru)
     * - orders.product_id (legacy)
     */
    private function hasPurchasedProduct(int $userId, int $productId): bool
    {
        return Order::query()
            ->where('user_id', $userId)
            ->whereIn('status', [OrderStatus::PAID, OrderStatus::FULFILLED])
            ->where(function ($q) use ($productId) {
                // legacy
                $q->where('product_id', $productId)

                  // order_items
                  ->orWhereHas('items', function ($qq) use ($productId) {
                      $qq->where('product_id', $productId);
                  });
            })
            ->exists();
    }
}