<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $data = Category::query()
            ->select('id', 'name', 'slug', 'is_active')
            ->where('is_active', true)
            // hanya category yang punya produk published+active
            ->whereHas('products', function ($q) {
                $q->where('is_active', true)->where('is_published', true);
            })
            ->withCount(['products as products_count' => function ($q) {
                $q->where('is_active', true)->where('is_published', true);
            }])
            ->orderBy('name')
            ->get();

        return $this->ok($data);
    }

    public function subcategories(string $idOrSlug)
    {
        $category = Category::query()
            ->where('id', $idOrSlug)
            ->orWhere('slug', $idOrSlug)
            ->firstOrFail();

        $data = $category->subcategories()
            ->select('id', 'category_id', 'name', 'slug', 'provider', 'image', 'is_active')
            ->where('is_active', true)
            ->whereHas('products', function ($q) {
                $q->where('is_active', true)->where('is_published', true);
            })
            ->withCount(['products as products_count' => function ($q) {
                $q->where('is_active', true)->where('is_published', true);
            }])
            ->orderBy('name')
            ->get();

        return $this->ok([
            'category' => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
            ],
            'subcategories' => $data
        ]);
    }
}
