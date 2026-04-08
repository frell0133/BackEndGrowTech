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
        $data = PublicCache::rememberCatalogTaxonomy('categories:index', 900, function () {
            $productCounts = Product::query()
                ->join('categories', 'categories.id', '=', 'products.category_id')
                ->leftJoin('subcategories', 'subcategories.id', '=', 'products.subcategory_id')
                ->selectRaw('products.category_id, COUNT(*) as products_count')
                ->where('products.is_active', true)
                ->where('products.is_published', true)
                ->where('categories.is_active', true)
                ->where(function ($q) {
                    $q->whereNull('products.subcategory_id')
                        ->orWhere('subcategories.is_active', true);
                })
                ->groupBy('products.category_id');

            return Category::query()
                ->select([
                    'categories.id',
                    'categories.name',
                    'categories.slug',
                    'categories.redirect_link',
                    'categories.sort_order',
                    'categories.is_active',
                    DB::raw('COALESCE(pc.products_count, 0) as products_count'),
                ])
                ->leftJoinSub($productCounts, 'pc', function ($join) {
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
        $data = PublicCache::rememberCatalogTaxonomy('categories:' . $idOrSlug . ':subcategories', 900, function () use ($idOrSlug) {
            $category = Category::query()
                ->select('id', 'name', 'slug')
                ->where(function ($query) use ($idOrSlug) {
                    $query->where('id', $idOrSlug)
                        ->orWhere('slug', $idOrSlug);
                })
                ->where('is_active', true)
                ->firstOrFail();

            $productCounts = Product::query()
                ->join('categories', 'categories.id', '=', 'products.category_id')
                ->join('subcategories', 'subcategories.id', '=', 'products.subcategory_id')
                ->selectRaw('products.subcategory_id, COUNT(*) as products_count')
                ->where('products.category_id', $category->id)
                ->where('products.is_active', true)
                ->where('products.is_published', true)
                ->where('categories.is_active', true)
                ->where('subcategories.is_active', true)
                ->groupBy('products.subcategory_id');

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
                    'subcategories.sort_order',
                    'subcategories.is_active',
                    DB::raw('COALESCE(pc.products_count, 0) as products_count'),
                ])
                ->leftJoinSub($productCounts, 'pc', function ($join) {
                    $join->on('pc.subcategory_id', '=', 'subcategories.id');
                })
                ->where('subcategories.is_active', true)
                ->with(['category:id,name,slug,is_active'])
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
