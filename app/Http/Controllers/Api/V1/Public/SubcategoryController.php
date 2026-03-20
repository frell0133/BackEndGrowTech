<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
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

        $data = PublicCache::rememberCatalog('subcategories:index:' . $categoryId, 300, function () use ($categoryId) {
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
                ->where('is_active', true)
                ->when($categoryId !== 'all' && $categoryId !== null && $categoryId !== '', function ($q) use ($categoryId) {
                    $q->where('category_id', $categoryId);
                })
                ->whereHas('category', fn ($q) => $q->where('is_active', true))
                ->whereHas('products', function ($q) {
                    $q->where('is_active', true)->where('is_published', true);
                })
                ->with(['category:id,name,slug'])
                ->withCount(['products as products_count' => function ($q) {
                    $q->where('is_active', true)->where('is_published', true);
                }])
                ->orderBy('name')
                ->get();
        });

        return $this->ok($data);
    }

    public function show(string $idOrSlug)
    {
        $data = PublicCache::rememberCatalog('subcategories:show:' . $idOrSlug, 300, function () use ($idOrSlug) {
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
                ->with(['category:id,name,slug'])
                ->first();
        });

        if (!$data) {
            return $this->fail('Subcategory tidak ditemukan', 404);
        }

        return $this->ok($data);
    }
}