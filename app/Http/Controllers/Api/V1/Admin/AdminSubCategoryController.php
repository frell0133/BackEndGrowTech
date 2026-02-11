<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\SubCategory;
use Illuminate\Http\Request;

class AdminSubCategoryController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        $data = SubCategory::query()
            ->with('category:id,name,slug')
            ->when($q !== '', function ($qq) use ($q) {
                $qq->where('name', 'ilike', "%{$q}%")
                   ->orWhere('slug', 'ilike', "%{$q}%");
            })
            ->orderBy('sort_order')
            ->orderBy('id', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => (object)[],
            'error' => null,
        ]);
    }

    public function store(Request $request)
    {
        $v = $request->validate([
            'category_id' => ['required','integer'],
            'name'        => ['required','string','max:120'],
            'slug'        => ['required','string','max:160'],
            'provider'    => ['nullable','string','max:120'],
            'image_path'  => ['nullable','string','max:255'],
            'image_url'   => ['nullable','string'],
            'is_active'   => ['nullable','boolean'],
            'sort_order'  => ['nullable','integer'],
        ]);

        $sub = SubCategory::create([
            'category_id' => $v['category_id'],
            'name'        => $v['name'],
            'slug'        => $v['slug'],
            'provider'    => $v['provider'] ?? null,
            'image_path'  => $v['image_path'] ?? null,
            'image_url'   => $v['image_url'] ?? null,
            'is_active'   => $v['is_active'] ?? true,
            'sort_order'  => $v['sort_order'] ?? 1,
        ]);

        return response()->json([
            'success' => true,
            'data' => $sub->load('category:id,name,slug'),
            'meta' => (object)[],
            'error' => null,
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        $sub = SubCategory::findOrFail($id);

        $v = $request->validate([
            'category_id' => ['sometimes','integer'],
            'name'        => ['sometimes','string','max:120'],
            'slug'        => ['sometimes','string','max:160'],
            'provider'    => ['nullable','string','max:120'],
            'image_path'  => ['nullable','string','max:255'],
            'image_url'   => ['nullable','string'],
            'is_active'   => ['nullable','boolean'],
            'sort_order'  => ['nullable','integer'],
        ]);

        $sub->fill($v);
        $sub->save();

        return response()->json([
            'success' => true,
            'data' => $sub->load('category:id,name,slug'),
            'meta' => (object)[],
            'error' => null,
        ]);
    }

    public function destroy(int $id)
    {
        $sub = SubCategory::findOrFail($id);
        $sub->delete();

        return response()->json([
            'success' => true,
            'data' => ['deleted' => true],
            'meta' => (object)[],
            'error' => null,
        ]);
    }
}
