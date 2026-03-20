<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Models\License;
use App\Models\Product;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $search = trim((string) $request->query('q', ''));
        $perPage = max(1, min((int) $request->query('per_page', 20), 50));

        $categoryId = $request->query('category_id');
        $subcategoryId = $request->query('subcategory_id');

        $sort = strtolower((string) $request->query('sort', 'latest'));
        $dir = strtolower((string) $request->query('dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        $sort = match ($sort) {
            'terlaris' => 'bestseller',
            'favorit' => 'favorite',
            default => $sort,
        };

        $query = Product::query()
            ->select([
                'id',
                'category_id',
                'subcategory_id',
                'name',
                'slug',
                'type',
                'description',
                'tier_pricing',
                'duration_days',
                'price',
                'is_active',
                'is_published',
                'rating',
                'rating_count',
                'purchases_count',
                'popularity_score',
                'created_at',
            ])
            ->with([
                'category:id,name,slug',
                'subcategory:id,category_id,name,description,slug,provider,image_url,image_path',
            ])
            ->withCount([
                'licenses as available_stock' => function ($q) {
                    $q->where('status', License::STATUS_AVAILABLE);
                },
            ])
            ->when($sort === 'favorite', fn ($q) => $q->withCount('favorites'))
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            ->when($subcategoryId, fn ($q) => $q->where('subcategory_id', $subcategoryId))
            ->when($search !== '', fn ($q) => $q->where(function ($w) use ($search) {
                $w->where('name', 'ilike', "%{$search}%")
                    ->orWhere('slug', 'ilike', "%{$search}%");
            }))
            ->where('is_active', true)
            ->where('is_published', true);

        switch ($sort) {
            case 'bestseller':
                $query->orderBy('purchases_count', $dir)
                    ->orderBy('popularity_score', $dir)
                    ->orderByDesc('id');
                break;

            case 'popular':
                $query->orderBy('popularity_score', $dir)
                    ->orderBy('purchases_count', $dir)
                    ->orderByDesc('id');
                break;

            case 'rating':
                $query->orderBy('rating', $dir)
                    ->orderBy('rating_count', $dir)
                    ->orderByDesc('id');
                break;

            case 'favorite':
                $query->orderBy('favorites_count', $dir)
                    ->orderBy('popularity_score', $dir)
                    ->orderByDesc('id');
                break;

            case 'latest':
            default:
                $query->latest();
                break;
        }

        return $this->ok($query->paginate($perPage));
    }

    public function show(Product $product)
    {
        $product->load([
            'category:id,name,slug',
            'subcategory:id,category_id,name,description,slug,provider,image_url,image_path',
        ]);

        $product->loadCount([
            'licenses as available_stock' => function ($q) {
                $q->where('status', License::STATUS_AVAILABLE);
            },
            'favorites',
        ]);

        if (!$product->is_active || !$product->is_published) {
            return $this->fail('Product not found', 404);
        }

        return $this->ok($product);
    }
}