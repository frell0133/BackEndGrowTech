<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\SubCategory;
use App\Services\SupabaseStorageService;
use App\Support\ApiResponse;
use App\Support\PublicCache;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminSubCategoryController extends Controller
{
    use ApiResponse;

    private function defaultImagePayload(array $validated): array
    {
        $hasImageUrl = !empty($validated['image_url']);
        $hasImagePath = !empty($validated['image_path']);

        if ($hasImageUrl || $hasImagePath) {
            return $validated;
        }

        $validated['image_url'] = '/logogrowtech.png';
        $validated['image_path'] = 'defaults/logogrowtech.png';

        return $validated;
    }

    /**
     * Saat update parsial (contoh: toggle status), jangan pernah menimpa gambar lama
     * dengan default hanya karena image_url/image_path tidak dikirim dari FE.
     */
    private function preserveExistingImagePayload(array $validated): array
    {
        foreach (['image_url', 'image_path'] as $field) {
            if (!array_key_exists($field, $validated)) {
                continue;
            }

            $value = trim((string) ($validated[$field] ?? ''));
            if ($value === '') {
                unset($validated[$field]);
            }
        }

        return $validated;
    }

    public function index(Request $request)
    {
        $categoryId = $request->query('category_id');
        $search = trim((string) ($request->query('q') ?? $request->query('search', '')));
        $status = strtolower(trim((string) $request->query('status', 'all')));

        $data = SubCategory::with('category')
            ->when($categoryId, fn ($q) => $q->where('category_id', (int) $categoryId))
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($w) use ($search) {
                    $w->where('name', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%")
                        ->orWhere('provider', 'like', "%{$search}%")
                        ->orWhereHas('category', function ($cat) use ($search) {
                            $cat->where('name', 'like', "%{$search}%");
                        });
                });
            })
            ->when(in_array($status, ['active', 'inactive'], true), function ($q) use ($status) {
                $q->where('is_active', $status === 'active');
            })
            ->orderBy('sort_order')
            ->latest('id')
            ->get();

        return $this->ok($data);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'name'        => ['required', 'string', 'max:255'],
            'slug'        => [
                'required',
                'string',
                'max:255',
                Rule::unique('subcategories')->where(
                    fn ($q) => $q->where('category_id', $request->category_id)
                ),
            ],
            'provider'    => ['nullable', 'string', 'max:255'],
            'image_url'   => ['nullable', 'string', 'max:2000'],
            'image_path'  => ['nullable', 'string', 'max:2000'],
            'is_active'   => ['boolean'],
            'sort_order'  => ['required', 'integer', 'min:1'],
            'description' => ['nullable', 'string'],
        ]);

        $sub = SubCategory::create($this->defaultImagePayload($validated));

        PublicCache::bumpCatalog();
        PublicCache::bumpDashboard();

        return $this->ok($sub->load('category'), 201);
    }

    public function update(Request $request, $id)
    {
        $sub = SubCategory::findOrFail($id);

        $validated = $request->validate([
            'category_id' => ['sometimes', 'integer', 'exists:categories,id'],
            'name'        => ['sometimes', 'string', 'max:255'],
            'slug'        => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('subcategories')
                    ->ignore($sub->id)
                    ->where(function ($q) use ($request, $sub) {
                        $catId = $request->input('category_id', $sub->category_id);
                        return $q->where('category_id', $catId);
                    }),
            ],
            'provider'    => ['nullable', 'string', 'max:255'],
            'image_url'   => ['nullable', 'string', 'max:2000'],
            'image_path'  => ['nullable', 'string', 'max:2000'],
            'is_active'   => ['boolean'],
            'sort_order'  => ['sometimes', 'integer', 'min:1'],
            'description' => ['nullable', 'string'],
        ]);

        $sub->update($this->preserveExistingImagePayload($validated));

        PublicCache::bumpCatalog();
        PublicCache::bumpDashboard();

        return $this->ok($sub->load('category'));
    }

    public function destroy($id)
    {
        $sub = SubCategory::findOrFail($id);
        $sub->delete();

        PublicCache::bumpCatalog();
        PublicCache::bumpDashboard();

        return $this->ok(['deleted' => true]);
    }

    public function signLogoUpload(Request $request, SupabaseStorageService $supabase)
    {
        $data = $request->validate([
            'mime' => ['required', 'string', 'starts_with:image/'],
        ]);

        $bucket  = (string) config('services.supabase.bucket_subcategories', 'subcategories');
        $expires = (int) config('services.supabase.sign_expires', 60);

        $path   = $supabase->buildSubCategoryLogoPath($data['mime']);
        $signed = $supabase->createSignedUploadUrl($bucket, $path, $expires);

        return $this->ok([
            'path'      => $signed['path'],
            'signedUrl' => $signed['signedUrl'],
            'publicUrl' => $supabase->publicObjectUrl($bucket, $signed['path']),
        ]);
    }
}