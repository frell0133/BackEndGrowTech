<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Support\ApiResponse;
use App\Support\PublicCache;
use Illuminate\Support\Facades\DB;

class CategoryController extends Controller
{
    use ApiResponse;

    public function index()
    {
        $data = PublicCache::rememberCatalog('categories:index', 300, function () {
            $productCounts = Product::query()
                ->selectRaw('category_id, COUNT(*) as products_count')
                ->where('is_active', true)
                ->where('is_published', true)
                ->groupBy('category_id');

            return Category::query()
                ->select([
                    'categories.id',
                    'categories.name',
                    'categories.slug',
                    'categories.is_active',
                    DB::raw('pc.products_count as products_count'),
                ])
                ->joinSub($productCounts, 'pc', function ($join) {
                    $join->on('pc.category_id', '=', 'categories.id');
                })
                ->where('categories.is_active', true)
                ->orderBy('categories.sort_order')
                ->orderBy('categories.name')
                ->get();
        });

        return $this->ok($data);
    }

    public function subcategories(string $idOrSlug)
    {
        $data = PublicCache::rememberCatalog('categories:' . $idOrSlug . ':subcategories', 300, function () use ($idOrSlug) {
            $category = Category::query()
                ->select('id', 'name', 'slug')
                ->where(function ($query) use ($idOrSlug) {
                    $query->where('id', $idOrSlug)
                        ->orWhere('slug', $idOrSlug);
                })
                ->where('is_active', true)
                ->firstOrFail();

            $productCounts = Product::query()
                ->selectRaw('subcategory_id, COUNT(*) as products_count')
                ->where('category_id', $category->id)
                ->where('is_active', true)
                ->where('is_published', true)
                ->groupBy('subcategory_id');

            $subcategories = $category->subcategories()
                ->select([
                    'subcategories.id',
                    'subcategories.category_id',
                    'subcategories.name',
                    'subcategories.description',
                    'subcategories.slug',
                    'subcategories.provider',
                    'subcategories.image_url',
                    'subcategories.image_path',
                    'subcategories.is_active',
                    DB::raw('pc.products_count as products_count'),
                ])
                ->joinSub($productCounts, 'pc', function ($join) {
                    $join->on('pc.subcategory_id', '=', 'subcategories.id');
                })
                ->where('subcategories.is_active', true)
                ->orderBy('subcategories.sort_order')
                ->orderBy('subcategories.name')
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
