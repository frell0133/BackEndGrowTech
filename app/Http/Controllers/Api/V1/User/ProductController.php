<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $q = $request->query('q');
        $perPage = (int) $request->query('per_page', 20);

        $products = Product::query()
            ->when($q, fn ($qr) => $qr->where('name', 'ilike', "%{$q}%"))
            ->latest()
            ->paginate($perPage);

        return $this->ok($products);
    }

    public function show(Product $product)
    {
        return $this->ok($product);
    }
}
