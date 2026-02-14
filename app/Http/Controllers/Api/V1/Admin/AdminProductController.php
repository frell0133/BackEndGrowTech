<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class AdminProductController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $q = $request->query('q');
        $categoryId = $request->query('category_id');
        $subcategoryId = $request->query('subcategory_id');

        $data = Product::query()
            ->with([
                'category:id,name,slug',
                // ✅ include logo subkategori
                'subcategory:id,category_id,name,slug,provider,image_url,image_path'
            ])
            ->when($categoryId, fn($qq) => $qq->where('category_id', $categoryId))
            ->when($subcategoryId, fn($qq) => $qq->where('subcategory_id', $subcategoryId))
            ->when($q, function ($qq) use ($q) {
                $qq->where(function ($w) use ($q) {
                    $w->where('name','ilike',"%{$q}%")
                      ->orWhere('slug','ilike',"%{$q}%");
                });
            })
            ->orderBy('id','desc')
            ->get();

        return $this->ok($data);
    }

    public function store(Request $request)
    {
        $v = $request->validate([
            'category_id' => ['required','exists:categories,id'],
            'subcategory_id' => ['required','exists:subcategories,id'],
            'name' => ['required','string','max:180'],
            'slug' => ['nullable','string','max:180','unique:products,slug'],
            'type' => ['required','string','max:60'], // ACCOUNT_CREDENTIAL / LICENSE_KEY
            'duration_days' => ['nullable','integer','min:1'],
            'description' => ['nullable','string'],
            'tier_pricing' => ['required','array'], // {"member":19500,"reseller":18500}
            'is_active' => ['nullable','boolean'],
            'is_published' => ['nullable','boolean'],
        ]);

        $v['slug'] = $v['slug'] ?? Str::slug($v['name']);
        $v['is_active'] = $v['is_active'] ?? true;
        $v['is_published'] = $v['is_published'] ?? false;

        $p = Product::create($v);

        // ✅ include logo subkategori saat return
        return $this->ok($p->load(
            'category:id,name,slug',
            'subcategory:id,category_id,name,slug,provider,image_url,image_path'
        ));
    }

    public function update(Request $request, $id)
    {
        $p = Product::find($id);
        if (!$p) return $this->fail('Product not found', 404);

        $v = $request->validate([
            'category_id' => ['sometimes','exists:categories,id'],
            'subcategory_id' => ['sometimes','exists:subcategories,id'],
            'name' => ['sometimes','string','max:180'],
            'slug' => ['sometimes','string','max:180','unique:products,slug,'.$p->id],
            'type' => ['sometimes','string','max:60'],
            'duration_days' => ['sometimes','nullable','integer','min:1'],
            'description' => ['sometimes','nullable','string'],
            'tier_pricing' => ['sometimes','array'],
            'is_active' => ['sometimes','boolean'],
            'is_published' => ['sometimes','boolean'],
        ]);

        // optional: auto slug kalau name diubah tapi slug tidak dikirim
        if (array_key_exists('name', $v) && !array_key_exists('slug', $v)) {
            $v['slug'] = Str::slug($v['name']);
        }

        $p->fill($v)->save();

        // ✅ include logo subkategori saat return
        return $this->ok($p->load(
            'category:id,name,slug',
            'subcategory:id,category_id,name,slug,provider,image_url,image_path'
        ));
    }

    public function destroy($id)
    {
        $p = Product::find($id);
        if (!$p) return $this->fail('Product not found', 404);

        try {
            DB::transaction(function () use ($p) {
                // Opsional: kalau kamu punya relasi, bisa cek dulu
                // misal: if ($p->licenses()->exists()) throw new \RuntimeException("Has licenses");

                $p->delete();
            });

            return $this->ok(['deleted' => true]);
        } catch (QueryException $e) {
            // PostgreSQL FK violation = 23503
            if (($e->errorInfo[0] ?? null) === '23503') {
                return $this->fail(
                    'Product tidak bisa dihapus karena masih dipakai oleh data lain (mis. order/license). Hapus/lepaskan relasinya dulu.',
                    409,
                    ['sqlstate' => $e->errorInfo[0] ?? null]
                );
            }

            // lainnya
            return $this->fail('Delete failed', 500, [
                'sqlstate' => $e->errorInfo[0] ?? null,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
