<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    use ApiResponse;

    /**
     * GET /api/v1/products
     *
     * Query params:
     * - q=...
     * - per_page=20
     * - category_id=...
     * - subcategory_id=...
     * - sort=latest|bestseller|popular|rating|favorite
     *   alias: terlaris=bestseller, favorit=favorite
     * - dir=asc|desc (default desc)
     */
    public function index(Request $request)
    {
        $q = $request->query('q');
        $perPage = (int) $request->query('per_page', 20);

        $categoryId = $request->query('category_id');
        $subcategoryId = $request->query('subcategory_id');

        $sort = strtolower((string) $request->query('sort', 'latest'));
        $dir  = strtolower((string) $request->query('dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        // alias biar enak dipakai FE (bahasa indonesia)
        $sort = match ($sort) {
            'terlaris' => 'bestseller',
            'favorit'  => 'favorite',
            default    => $sort,
        };

        $qr = Product::query()
            ->with([
                'category:id,name,slug',
                'subcategory:id,category_id,name,description,slug,provider,image_url,image_path'
            ])
            ->withCount([
                'licenses as available_stock' => function ($q) {
                    $q->where('status', \App\Models\License::STATUS_AVAILABLE);
                }
            ])
            // hanya hitung favorites kalau memang dibutuhkan untuk sorting favorite
            ->when($sort === 'favorite', fn ($q) => $q->withCount('favorites'))
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            ->when($subcategoryId, fn ($q) => $q->where('subcategory_id', $subcategoryId))
            ->when($q, fn ($q2) => $q2->where('name', 'ilike', "%{$q}%"))
            ->where('is_active', true)
            ->where('is_published', true);

        // Sorting utama
        switch ($sort) {
            case 'bestseller':
                // TERLARIS: murni qty terjual (purchases_count)
                $qr->orderBy('purchases_count', $dir)
                   ->orderBy('popularity_score', $dir)
                   ->orderByDesc('id');
                break;

            case 'popular':
                // POPULAR: gabungan rating + purchases (popularity_score)
                $qr->orderBy('popularity_score', $dir)
                   ->orderBy('purchases_count', $dir)
                   ->orderByDesc('id');
                break;

            case 'rating':
                // TOP RATED: rating tertinggi, tie-break pakai rating_count
                $qr->orderBy('rating', $dir)
                   ->orderBy('rating_count', $dir)
                   ->orderByDesc('id');
                break;

            case 'favorite':
                // MOST FAVORITED: like terbanyak (favorites_count)
                $qr->orderBy('favorites_count', $dir)
                   ->orderBy('popularity_score', $dir)
                   ->orderByDesc('id');
                break;

            case 'latest':
            default:
                $qr->latest();
                break;
        }

        return $this->ok($qr->paginate($perPage));
    }

    public function show(Product $product)
    {
        $product->load([
            'category:id,name,slug',
            'subcategory:id,category_id,name,description,slug,provider,image_url,image_path'
        ]);

        $product->loadCount([
            'licenses as available_stock' => function ($q) {
                $q->where('status', \App\Models\License::STATUS_AVAILABLE);
            },
            // opsional: detail produk bisa nampilin berapa yang favorite
            'favorites',
        ]);

        if (!$product->is_active || !$product->is_published) {
            return $this->fail('Product not found', 404);
        }

        return $this->ok($product);
    }
}