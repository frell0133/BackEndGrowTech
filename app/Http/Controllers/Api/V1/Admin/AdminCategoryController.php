<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Support\ApiResponse;
use App\Support\PublicCache;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminCategoryController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $status = strtolower(trim((string) $request->query('status', 'all')));

        $data = Category::query()
            ->when($q !== '', function ($qq) use ($q) {
                $qq->where(function ($w) use ($q) {
                    $w->where('name', 'ilike', "%{$q}%")
                        ->orWhere('slug', 'ilike', "%{$q}%")
                        ->orWhere('redirect_link', 'ilike', "%{$q}%");
                });
            })
            ->when($status === 'active', fn ($qq) => $qq->where('is_active', true))
            ->when($status === 'inactive', fn ($qq) => $qq->where('is_active', false))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->orderByDesc('id')
            ->get();

        return $this->ok($data);
    }

    public function store(Request $request)
    {
        $v = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:120'],
            'redirect_link' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        $baseSlug = Str::slug($v['slug'] ?? $v['name']);
        $slug = $baseSlug;

        $i = 2;
        while (Category::where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . $i;
            $i++;
        }

        $v['slug'] = $slug;
        $v['is_active'] = $v['is_active'] ?? true;
        $v['sort_order'] = $v['sort_order'] ?? 0;

        $cat = Category::create($v);

        PublicCache::bumpCatalog();
        PublicCache::bumpDashboard();

        return $this->ok($cat);
    }

    public function update(Request $request, $id)
    {
        $cat = Category::find($id);
        if (!$cat) {
            return $this->fail('Category not found', 404);
        }

        $v = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'slug' => ['sometimes', 'string', 'max:120', 'unique:categories,slug,' . $cat->id],
            'redirect_link' => ['sometimes', 'nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer'],
        ]);

        $cat->fill($v)->save();

        PublicCache::bumpCatalog();
        PublicCache::bumpDashboard();

        return $this->ok($cat);
    }

    public function destroy($id)
    {
        $cat = Category::find($id);
        if (!$cat) {
            return $this->fail('Category not found', 404);
        }

        $cat->delete();

        PublicCache::bumpCatalog();
        PublicCache::bumpDashboard();

        return $this->ok(['deleted' => true]);
    }
}
