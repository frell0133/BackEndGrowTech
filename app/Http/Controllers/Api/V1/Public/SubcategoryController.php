<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\SubCategory;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SubcategoryController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $categoryId = $request->query('category_id');

        $data = SubCategory::query()
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
                DB::raw('image_url as image'), // ✅ biar FE tetap bisa pakai "image"
            ])
            ->where('is_active', true)
            ->when($categoryId, fn($q) => $q->where('category_id', $categoryId))
            ->whereHas('category', fn($q) => $q->where('is_active', true))
            ->whereHas('products', function ($q) {
                $q->where('is_active', true)->where('is_published', true);
            })
            ->with(['category:id,name,slug'])
            ->withCount(['products as products_count' => function ($q) {
                $q->where('is_active', true)->where('is_published', true);
            }])
            ->orderBy('name')
            ->get();

        return $this->ok($data);
    }
}
