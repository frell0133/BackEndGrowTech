<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Support\ApiResponse;
use App\Support\PublicCache;

class CategoryController extends Controller
{
    use ApiResponse;

    public function index()
    {
        $data = PublicCache::rememberCatalog('categories:index', 300, function () {
            return Category::query()
                ->select('id', 'name', 'slug', 'is_active')
                ->where('is_active', true)
                ->whereHas('products', function ($q) {
                    $q->where('is_active', true)->where('is_published', true);
                })
                ->withCount(['products as products_count' => function ($q) {
                    $q->where('is_active', true)->where('is_published', true);
                }])
                ->orderBy('name')
                ->get();
        });

        return $this->ok($data);
    }

    public function subcategories(string $idOrSlug)
    {
        $data = PublicCache::rememberCatalog('categories:' . $idOrSlug . ':subcategories', 300, function () use ($idOrSlug) {
            $category = Category::query()
                ->where('id', $idOrSlug)
                ->orWhere('slug', $idOrSlug)
                ->firstOrFail();

            $subcategories = $category->subcategories()
                ->select('id', 'category_id', 'name', 'description', 'slug', 'provider', 'image_url', 'image_path', 'is_active')
                ->where('is_active', true)
                ->whereHas('products', function ($q) {
                    $q->where('is_active', true)->where('is_published', true);
                })
                ->withCount(['products as products_count' => function ($q) {
                    $q->where('is_active', true)->where('is_published', true);
                }])
                ->orderBy('name')
                ->get();

            return [
                'category' => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                ],
                'subcategories' => $subcategories,
            ];
        });

        return $this->ok($data);
    }
}