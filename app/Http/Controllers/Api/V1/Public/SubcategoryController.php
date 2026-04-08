<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\SubCategory;
use App\Support\ApiResponse;
use App\Support\PublicCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SubcategoryController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $categoryId = $request->query('category_id', 'all');

        $data = PublicCache::rememberCatalogTaxonomy('subcategories:index:' . $categoryId, 900, function () use ($categoryId) {
            $productCounts = Product::query()
                ->join('categories', 'categories.id', '=', 'products.category_id')
                ->join('subcategories', 'subcategories.id', '=', 'products.subcategory_id')
                ->selectRaw('products.subcategory_id, COUNT(*) as products_count')
                ->where('products.is_active', true)
                ->where('products.is_published', true)
                ->where('categories.is_active', true)
                ->where('subcategories.is_active', true)
                ->when($categoryId !== 'all' && $categoryId !== null && $categoryId !== '', function ($query) use ($categoryId) {
                    $query->where('products.category_id', $categoryId);
                })
                ->groupBy('subcategory_id');

            return SubCategory::query()
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
                    DB::raw('subcategories.image_url as image'),
                    DB::raw('COALESCE(pc.products_count, 0) as products_count'),
                ])
                ->leftJoinSub($productCounts, 'pc', function ($join) {
                    $join->on('pc.subcategory_id', '=', 'subcategories.id');
                })
                ->where('subcategories.is_active', true)
                ->when($categoryId !== 'all' && $categoryId !== null && $categoryId !== '', function ($q) use ($categoryId) {
                    $q->where('subcategories.category_id', $categoryId);
                })
                ->whereHas('category', fn ($q) => $q->where('is_active', true))
                ->with(['category:id,name,slug,is_active'])
                ->orderBy('subcategories.sort_order')
                ->orderBy('subcategories.name')
                ->get();
        });

        return $this->ok($data);
    }

    public function show(string $idOrSlug)
    {
        $data = PublicCache::rememberCatalogTaxonomy('subcategories:show:' . $idOrSlug, 900, function () use ($idOrSlug) {
            return SubCategory::query()
                ->select([
                    'id',
                    'category_id',
                    'name',
                    'description',
                    'slug',
                    'provider',
                    'image_url',
                    'image_path',
                    'is_active',
                    DB::raw('image_url as image'),
                ])
                ->where(function ($q) use ($idOrSlug) {
                    $q->where('id', $idOrSlug)->orWhere('slug', $idOrSlug);
                })
                ->where('is_active', true)
                ->with(['category:id,name,slug,is_active'])
                ->first();
        });

        if (!$data) {
            return $this->fail('Subcategory tidak ditemukan', 404);
        }

        return $this->ok($data);
    }
}
