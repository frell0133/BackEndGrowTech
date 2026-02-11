<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $q = $request->query('q');
        $perPage = (int) $request->query('per_page', 20);

        // OPTIONAL filter (biar public bisa filter seperti mockup)
        $categoryId = $request->query('category_id');
        $subcategoryId = $request->query('subcategory_id');

        $products = Product::query()
            ->with([
                'category:id,name,slug',
                // ✅ include logo subkategori
                'subcategory:id,category_id,name,slug,provider,image_url,image_path'
            ])
            ->when($categoryId, fn ($qr) => $qr->where('category_id', $categoryId))
            ->when($subcategoryId, fn ($qr) => $qr->where('subcategory_id', $subcategoryId))
            ->when($q, fn ($qr) => $qr->where('name', 'ilike', "%{$q}%"))
            // public biasanya hanya tampil yang aktif + published
            ->where('is_active', true)
            ->where('is_published', true)
            ->latest()
            ->paginate($perPage);

        return $this->ok($products);
    }

    public function show(Product $product)
    {
        // ✅ include logo subkategori juga
        $product->load([
            'category:id,name,slug',
            'subcategory:id,category_id,name,slug,provider,image_url,image_path'
        ]);

        // public: optional hide jika tidak published
        if (!$product->is_active || !$product->is_published) {
            return $this->fail('Product not found', 404);
        }

        return $this->ok($product);
    }
}
