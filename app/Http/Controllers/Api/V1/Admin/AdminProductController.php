<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\SubCategory;
use App\Support\ApiResponse;
use App\Support\PublicCache;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminProductController extends Controller
{
    use ApiResponse;

    private const TIER_KEYS = ['member', 'reseller', 'vip'];

    private function ensureValidSubCategory(?int $categoryId, ?int $subCategoryId): ?\Illuminate\Http\JsonResponse
    {
        if (!$categoryId || !$subCategoryId) {
            return null;
        }

        $matched = SubCategory::query()
            ->where('id', $subCategoryId)
            ->where('category_id', $categoryId)
            ->exists();

        if (!$matched) {
            return $this->fail('Subkategori tidak cocok dengan kategori yang dipilih', 422);
        }

        return null;
    }

    private function normalizeTierMap(?array $value): array
    {
        $normalized = [];

        foreach (self::TIER_KEYS as $key) {
            $raw = $value[$key] ?? 0;
            $normalized[$key] = max(0, (int) round((float) $raw));
        }

        return $normalized;
    }

    public function index(Request $request)
    {
        $q = trim((string) ($request->query('q') ?? $request->query('search', '')));
        $categoryId = $request->query('category_id');
        $subcategoryId = $request->query('subcategory_id');
        $perPage = max(1, min((int) $request->query('per_page', 20), 100));

        $paginator = Product::query()
            ->select([
                'id',
                'category_id',
                'subcategory_id',
                'name',
                'slug',
                'type',
                'duration_days',
                'tier_pricing',
                'tier_profit',
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
                'subcategory:id,category_id,name,slug,provider,image_url,image_path',
            ])
            ->when($categoryId, fn ($qq) => $qq->where('category_id', $categoryId))
            ->when($subcategoryId, fn ($qq) => $qq->where('subcategory_id', $subcategoryId))
            ->when($q !== '', function ($qq) use ($q) {
                $qq->where(function ($w) use ($q) {
                    $w->where('name', 'ilike', "%{$q}%")
                        ->orWhere('slug', 'ilike', "%{$q}%");
                });
            })
            ->orderByDesc('id')
            ->paginate($perPage);

        return $this->ok(
            $paginator->items(),
            [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ]
        );
    }

    public function show($id)
    {
        $product = Product::query()
            ->with([
                'category:id,name,slug',
                'subcategory:id,category_id,name,slug,provider,image_url,image_path',
            ])
            ->find($id);

        if (!$product) {
            return $this->fail('Product not found', 404);
        }

        return $this->ok($product);
    }

    public function store(Request $request)
    {
        $v = $request->validate([
            'category_id' => ['required', 'exists:categories,id'],
            'subcategory_id' => ['required', 'exists:subcategories,id'],
            'name' => ['required', 'string', 'max:180'],
            'slug' => ['nullable', 'string', 'max:180', 'unique:products,slug'],
            'type' => ['required', 'string', 'max:60'],
            'duration_days' => ['nullable', 'integer', 'min:1'],
            'description' => ['nullable', 'string'],
            'tier_pricing' => ['required', 'array'],
            'tier_pricing.member' => ['nullable', 'numeric', 'min:0'],
            'tier_pricing.reseller' => ['nullable', 'numeric', 'min:0'],
            'tier_pricing.vip' => ['nullable', 'numeric', 'min:0'],
            'tier_profit' => ['nullable', 'array'],
            'tier_profit.member' => ['nullable', 'numeric', 'min:0'],
            'tier_profit.reseller' => ['nullable', 'numeric', 'min:0'],
            'tier_profit.vip' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'is_published' => ['nullable', 'boolean'],
            'track_stock' => ['nullable', 'boolean'],
            'stock_min_alert' => ['nullable', 'integer', 'min:0'],
            'rating' => ['nullable', 'numeric', 'min:0', 'max:5'],
            'rating_count' => ['nullable', 'integer', 'min:0'],
        ]);

        $v['slug'] = $v['slug'] ?? Str::slug($v['name']);
        $v['is_active'] = $v['is_active'] ?? true;
        $v['is_published'] = $v['is_published'] ?? false;
        $v['track_stock'] = $v['track_stock'] ?? true;
        $v['stock_min_alert'] = $v['stock_min_alert'] ?? 0;
        $v['rating'] = $v['rating'] ?? 0;
        $v['rating_count'] = $v['rating_count'] ?? 0;
        $v['tier_pricing'] = $this->normalizeTierMap((array) ($v['tier_pricing'] ?? []));
        $v['tier_profit'] = $this->normalizeTierMap((array) ($v['tier_profit'] ?? []));

        if ($error = $this->ensureValidSubCategory((int) $v['category_id'], (int) $v['subcategory_id'])) {
            return $error;
        }

        $p = Product::create($v);

        PublicCache::bumpCatalog();
        PublicCache::bumpDashboard();

        return $this->ok($p->load(
            'category:id,name,slug',
            'subcategory:id,category_id,name,slug,provider,image_url,image_path'
        ));
    }

    public function update(Request $request, $id)
    {
        $p = Product::find($id);
        if (!$p) {
            return $this->fail('Product not found', 404);
        }

        $v = $request->validate([
            'category_id' => ['sometimes', 'exists:categories,id'],
            'subcategory_id' => ['sometimes', 'exists:subcategories,id'],
            'name' => ['sometimes', 'string', 'max:180'],
            'slug' => ['sometimes', 'string', 'max:180', 'unique:products,slug,' . $p->id],
            'type' => ['sometimes', 'string', 'max:60'],
            'duration_days' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'description' => ['sometimes', 'nullable', 'string'],
            'tier_pricing' => ['sometimes', 'array'],
            'tier_pricing.member' => ['nullable', 'numeric', 'min:0'],
            'tier_pricing.reseller' => ['nullable', 'numeric', 'min:0'],
            'tier_pricing.vip' => ['nullable', 'numeric', 'min:0'],
            'tier_profit' => ['sometimes', 'array'],
            'tier_profit.member' => ['nullable', 'numeric', 'min:0'],
            'tier_profit.reseller' => ['nullable', 'numeric', 'min:0'],
            'tier_profit.vip' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'is_published' => ['sometimes', 'boolean'],
            'track_stock' => ['sometimes', 'boolean'],
            'stock_min_alert' => ['sometimes', 'integer', 'min:0'],
            'rating' => ['sometimes', 'numeric', 'min:0', 'max:5'],
            'rating_count' => ['sometimes', 'integer', 'min:0'],
        ]);

        if (array_key_exists('name', $v) && !array_key_exists('slug', $v)) {
            $v['slug'] = Str::slug($v['name']);
        }

        if (array_key_exists('tier_pricing', $v)) {
            $v['tier_pricing'] = $this->normalizeTierMap((array) $v['tier_pricing']);
        }

        if (array_key_exists('tier_profit', $v)) {
            $v['tier_profit'] = $this->normalizeTierMap((array) $v['tier_profit']);
        }

        $nextCategoryId = array_key_exists('category_id', $v) ? (int) $v['category_id'] : (int) $p->category_id;
        $nextSubCategoryId = array_key_exists('subcategory_id', $v) ? (int) $v['subcategory_id'] : (int) $p->subcategory_id;

        if ($error = $this->ensureValidSubCategory($nextCategoryId, $nextSubCategoryId)) {
            return $error;
        }

        $p->fill($v)->save();

        PublicCache::bumpCatalog();
        PublicCache::bumpDashboard();

        return $this->ok($p->load(
            'category:id,name,slug',
            'subcategory:id,category_id,name,slug,provider,image_url,image_path'
        ));
    }

    public function publish(Request $request, $id)
    {
        $p = Product::find($id);
        if (!$p) {
            return $this->fail('Product not found', 404);
        }

        $v = $request->validate([
            'is_published' => ['required', 'boolean'],
        ]);

        $p->update([
            'is_published' => $v['is_published'],
        ]);

        PublicCache::bumpCatalog();
        PublicCache::bumpDashboard();

        return $this->ok($p);
    }

    public function destroy($id)
    {
        $p = Product::find($id);
        if (!$p) {
            return $this->fail('Product not found', 404);
        }

        $p->update([
            'is_active' => false,
            'is_published' => false,
        ]);

        PublicCache::bumpCatalog();
        PublicCache::bumpDashboard();

        return $this->ok(['deleted' => false, 'deactivated' => true]);
    }
}
