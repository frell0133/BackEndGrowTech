<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Models\Subcategory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminSubCategoryController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $categoryId = $request->query('category_id');
        $q = $request->query('q');

        $data = Subcategory::query()
            ->with('category:id,name,slug')
            ->when($categoryId, fn($qq) => $qq->where('category_id', $categoryId))
            ->when($q, fn($qq) => $qq->where('name','ilike',"%{$q}%")->orWhere('slug','ilike',"%{$q}%"))
            ->orderBy('sort_order')
            ->orderBy('id','desc')
            ->get();

        return $this->ok($data);
    }

    public function store(Request $request)
    {
        $v = $request->validate([
            'category_id' => ['required','exists:categories,id'],
            'name' => ['required','string','max:120'],
            'slug' => ['nullable','string','max:120'],
            'provider' => ['nullable','string','max:120'],
            'is_active' => ['nullable','boolean'],
            'sort_order' => ['nullable','integer'],
        ]);

        $v['slug'] = $v['slug'] ?? Str::slug($v['name']);
        $v['is_active'] = $v['is_active'] ?? true;
        $v['sort_order'] = $v['sort_order'] ?? 0;

        // pastikan unique(category_id, slug)
        $exists = Subcategory::where('category_id',$v['category_id'])->where('slug',$v['slug'])->exists();
        if ($exists) return $this->fail('Subcategory slug already exists for this category', 422);

        $sub = Subcategory::create($v);
        return $this->ok($sub->load('category:id,name,slug'));
    }

    public function update(Request $request, $id)
    {
        $sub = Subcategory::find($id);
        if (!$sub) return $this->fail('Subcategory not found', 404);

        $v = $request->validate([
            'category_id' => ['sometimes','exists:categories,id'],
            'name' => ['sometimes','string','max:120'],
            'slug' => ['sometimes','string','max:120'],
            'provider' => ['sometimes','nullable','string','max:120'],
            'is_active' => ['sometimes','boolean'],
            'sort_order' => ['sometimes','integer'],
        ]);

        $sub->fill($v);

        // validate unique(category_id, slug)
        $categoryId = $sub->category_id;
        $slug = $sub->slug;
        $exists = Subcategory::where('category_id',$categoryId)
            ->where('slug',$slug)
            ->where('id','!=',$sub->id)
            ->exists();
        if ($exists) return $this->fail('Subcategory slug already exists for this category', 422);

        $sub->save();
        return $this->ok($sub->load('category:id,name,slug'));
    }

    public function destroy($id)
    {
        $sub = Subcategory::find($id);
        if (!$sub) return $this->fail('Subcategory not found', 404);

        $sub->delete();
        return $this->ok(['deleted' => true]);
    }
}
