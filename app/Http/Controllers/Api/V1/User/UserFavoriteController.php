<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Favorite;
use App\Models\Product;
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

    // POST /api/v1/favorites
    // body: { "product_id": 1, "rating": 5(optional) }
    public function store(Request $request)
    {
        $user = $request->user();

        $v = $request->validate([
            'product_id' => ['required','integer','min:1'],
            'rating' => ['nullable','integer','min:1','max:5'],
        ]);

        $product = Product::query()->where('id', $v['product_id'])->firstOrFail();

        DB::transaction(function () use ($user, $product, $v) {
            Favorite::query()->updateOrCreate(
                ['user_id' => $user->id, 'product_id' => $product->id],
                ['rating' => $v['rating'] ?? null]
            );

            // Update agregat rating product (avg + count) dari favorites
            $agg = Favorite::query()
                ->where('product_id', $product->id)
                ->whereNotNull('rating')
                ->selectRaw('COUNT(*) as c, AVG(rating) as a')
                ->first();

            $product->rating_count = (int) ($agg->c ?? 0);
            $product->rating = (float) ($agg->a ?? 0);
            $product->save();

            // Update popularity score juga (pakai rumus sederhana)
            $product->popularity_score = (($product->rating ?? 0) * 20) + ((int) ($product->purchases_count ?? 0));
            $product->save();
        });

        return $this->ok(['message' => 'Added to favorites']);
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

                $product->popularity_score = (($product->rating ?? 0) * 20) + ((int) ($product->purchases_count ?? 0));
                $product->save();
            }
        });

        return $this->ok(['message' => 'Removed from favorites']);
    }
}