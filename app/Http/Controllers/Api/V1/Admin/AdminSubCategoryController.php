<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\SubCategory;
use App\Services\SupabaseStorageService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminSubCategoryController extends Controller
{
    use ApiResponse;

    /**
     * GET /api/v1/admin/subcategories
     */
    public function index()
    {
        $data = SubCategory::with('category')
            ->orderBy('sort_order')
            ->latest('id')
            ->get();

        return $this->ok($data);
    }

    /**
     * POST /api/v1/admin/subcategories
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'category_id' => ['required','integer','exists:categories,id'],
            'name'        => ['required','string','max:255'],
            'slug'        => [
                'required','string','max:255',
                Rule::unique('subcategories')->where(fn($q) => $q->where('category_id', $request->category_id)),
            ],
            'provider'    => ['nullable','string','max:255'],
            'image_url'   => ['nullable','string','max:2000'],
            'image_path'  => ['nullable','string','max:2000'],
            'is_active'   => ['boolean'],
            'sort_order'  => ['required','integer','min:1'],
        ]);

        $sub = SubCategory::create($validated);

        return $this->ok($sub->load('category'), 201);
    }

    /**
     * PATCH /api/v1/admin/subcategories/{id}
     */
    public function update(Request $request, $id)
    {
        $sub = SubCategory::findOrFail($id);

        $validated = $request->validate([
            'category_id' => ['sometimes','integer','exists:categories,id'],
            'name'        => ['sometimes','string','max:255'],
            'slug'        => [
                'sometimes','string','max:255',
                Rule::unique('subcategories')->ignore($sub->id)->where(function ($q) use ($request, $sub) {
                    $catId = $request->input('category_id', $sub->category_id);
                    return $q->where('category_id', $catId);
                }),
            ],
            'provider'    => ['nullable','string','max:255'],
            'image_url'   => ['nullable','string','max:2000'],
            'image_path'  => ['nullable','string','max:2000'],
            'is_active'   => ['boolean'],
            'sort_order'  => ['sometimes','integer','min:1'],
        ]);

        $sub->update($validated);

        return $this->ok($sub->load('category'));
    }

    /**
     * DELETE /api/v1/admin/subcategories/{id}
     */
    public function destroy($id)
    {
        $sub = SubCategory::findOrFail($id);
        $sub->delete();

        return $this->ok(['deleted' => true]);
    }

    /**
     * POST /api/v1/admin/subcategories/logo/sign
     * body: { "mime": "image/jpeg" }
     */
    public function signLogoUpload(Request $request, SupabaseStorageService $supabase)
    {
        $data = $request->validate([
            'mime' => ['required','string','starts_with:image/'],
        ]);

        $bucket  = (string) config('services.supabase.bucket_subcategories', 'subcategories');
        $expires = (int) config('services.supabase.sign_expires', 60);

        $path   = $supabase->buildSubCategoryLogoPath($data['mime']);
        $signed = $supabase->createSignedUploadUrl($bucket, $path, $expires);

        return $this->ok([
            // ✅ FE enak
            'path'      => $signed['path'],
            'signedUrl' => $signed['signedUrl'],
            'publicUrl' => $supabase->publicObjectUrl($bucket, $signed['path']),
        ]);
    }
}
