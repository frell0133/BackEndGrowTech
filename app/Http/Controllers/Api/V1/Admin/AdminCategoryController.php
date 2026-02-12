<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminCategoryController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $q = $request->query('q');

        $data = Category::query()
            ->when($q, fn($qq) => $qq->where('name','ilike',"%{$q}%")->orWhere('slug','ilike',"%{$q}%"))
            ->orderBy('sort_order')
            ->orderBy('id','desc')
            ->get();

        return $this->ok($data);
    }

    public function store(Request $request)
    {
        $v = $request->validate([
            'name' => ['required','string','max:120'],
            'slug' => ['nullable','string','max:120'],
            'redirect_link' => ['nullable','string','max:500'],
            'is_active' => ['nullable','boolean'],
            'sort_order' => ['nullable','integer'],
        ]);

        $baseSlug = $v['slug'] ? Str::slug($v['slug']) : Str::slug($v['name']);
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
        return $this->ok($cat);
    }

    public function update(Request $request, $id)
    {
        $cat = Category::find($id);
        if (!$cat) return $this->fail('Category not found', 404);

        $v = $request->validate([
            'name' => ['sometimes','string','max:120'],
            'slug' => ['sometimes','string','max:120','unique:categories,slug,'.$cat->id],
            'redirect_link' => ['sometimes','nullable','string','max:500'],
            'is_active' => ['sometimes','boolean'],
            'sort_order' => ['sometimes','integer'],
        ]);

        $cat->fill($v)->save();
        return $this->ok($cat);
    }

    public function destroy($id)
    {
        $cat = Category::find($id);
        if (!$cat) return $this->fail('Category not found', 404);

        $cat->delete();
        return $this->ok(['deleted' => true]);
    }
}
