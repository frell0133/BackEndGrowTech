<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\License;
use App\Models\Product;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class UserCartController extends Controller
{
    use ApiResponse;

    private function cartForUser(int $userId): Cart
    {
        return Cart::firstOrCreate(['user_id' => $userId]);
    }

    public function show(Request $request)
    {
        $cart = $this->cartForUser($request->user()->id);

        $items = CartItem::query()
            ->where('cart_id', $cart->id)
            ->with([
                // jangan select kolom yang belum ada di DB
                'product:id,category_id,subcategory_id,name,slug,is_active,is_published',
                'product.category:id,name,slug',
                // ganti image -> image_path
                'product.subcategory:id,category_id,name,slug,provider,image_path',
            ])
            ->get()
            ->map(function (CartItem $item) {
                $p = $item->product;

                $stock = License::query()
                    ->where('product_id', $item->product_id)
                    ->where('status', License::STATUS_AVAILABLE)
                    ->count();

                $canBuy = (bool) ($p?->is_active && $p?->is_published && $stock > 0);

                // kalau price belum ada di DB, amanin pakai 0 dulu
                $price = (int) ($p->price ?? 0);

                return [
                    'id' => $item->id,
                    'qty' => $item->qty,
                    'product' => $p,
                    'stock_available' => $stock,
                    'can_buy' => $canBuy,
                    'line_total' => $price * (int) $item->qty,
                ];
            });


        $subtotal = $items->sum('line_total');

        return $this->ok([
            'items' => $items,
            'subtotal' => $subtotal,
        ]);
    }

    public function add(Request $request)
    {
        $userId = $request->user()->id;
        $cart = $this->cartForUser($userId);

        $v = $request->validate([
            'product_id' => ['required', 'integer', 'min:1'],
            'qty' => ['nullable', 'integer', 'min:1', 'max:99'],
        ]);

        $qty = (int) ($v['qty'] ?? 1);

        $product = Product::query()
            ->where('id', $v['product_id'])
            ->where('is_active', true)
            ->where('is_published', true)
            ->first();

        if (!$product) {
            return $this->fail('Product not available', 404);
        }

        // optional: blok add kalau benar-benar out of stock
        $stock = License::query()
            ->where('product_id', $product->id)
            ->where('status', License::STATUS_AVAILABLE)
            ->count();

        if ($stock <= 0) {
            return $this->fail('Out of stock', 422, ['stock_available' => 0]);
        }

        $item = CartItem::query()
            ->where('cart_id', $cart->id)
            ->where('product_id', $product->id)
            ->first();

        if ($item) {
            $newQty = min(99, $item->qty + $qty);
            $item->update(['qty' => $newQty]);
        } else {
            $item = CartItem::create([
                'cart_id' => $cart->id,
                'product_id' => $product->id,
                'qty' => $qty,
            ]);
        }

        return $this->ok(['message' => 'Added to cart', 'item' => $item]);
    }

    public function update(Request $request, int $id)
    {
        $userId = $request->user()->id;
        $cart = $this->cartForUser($userId);

        $v = $request->validate([
            'qty' => ['required', 'integer', 'min:1', 'max:99'],
        ]);

        $item = CartItem::query()
            ->where('id', $id)
            ->where('cart_id', $cart->id)
            ->firstOrFail();

        $item->update(['qty' => (int) $v['qty']]);

        return $this->ok(['message' => 'Cart updated', 'item' => $item]);
    }

    public function remove(Request $request, int $id)
    {
        $userId = $request->user()->id;
        $cart = $this->cartForUser($userId);

        $item = CartItem::query()
            ->where('id', $id)
            ->where('cart_id', $cart->id)
            ->firstOrFail();

        $item->delete();

        return $this->ok(['message' => 'Removed']);
    }
}
