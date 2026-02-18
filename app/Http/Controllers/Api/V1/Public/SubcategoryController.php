<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\Subcategory;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class SubcategoryController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $categoryId = $request->query('category_id');

        $data = Subcategory::query()
            ->select('id', 'category_id', 'name', 'slug', 'provider', 'image', 'is_active')
            ->where('is_active', true)
            ->when($categoryId, fn($q) => $q->where('category_id', $categoryId))
            ->whereHas('category', fn($q) => $q->where('is_active', true))
            ->whereHas('products', function ($q) {
                $q->where('is_active', true)->where('is_published', true);
            })
            ->with([
                'category:id,name,slug',
            ])
            ->withCount(['products as products_count' => function ($q) {
                $q->where('is_active', true)->where('is_published', true);
            }])
            ->orderBy('name')
            ->get();

        return $this->ok($data);
    }
}
