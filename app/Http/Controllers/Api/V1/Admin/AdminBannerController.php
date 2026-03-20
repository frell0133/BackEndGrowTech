<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BannerSignImageRequest;
use App\Http\Requests\Admin\BannerUpdateImageRequest;
use App\Models\Banner;
use App\Services\SupabaseStorageService;
use Illuminate\Http\Request;
use App\Support\PublicCache;

class AdminBannerController extends Controller
{
    // GET /api/v1/admin/banners
    public function index(Request $request)
    {
        $perPage = (int) $request->get('per_page', 15);

        $items = Banner::query()
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $items,
        ]);
    }

    // POST /api/v1/admin/banners
    // Create banner row only (image_path optional, biasanya null dulu)
    public function store(Request $request)
    {
        $payload = $request->validate([
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active'  => ['sometimes', 'boolean'],
            'image_path' => ['sometimes', 'nullable', 'string'], // optional
        ]);

        $banner = Banner::create([
            'sort_order' => $payload['sort_order'] ?? 0,
            'is_active'  => $payload['is_active'] ?? true,
            'image_path' => $payload['image_path'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'data' => $banner,
        ], 201);
        PublicCache::bumpContent();
    }

    // PATCH /api/v1/admin/banners/{banner}
    public function update(Request $request, Banner $banner)
    {
        $payload = $request->validate([
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active'  => ['sometimes', 'boolean'],
        ]);

        $banner->fill($payload)->save();

        return response()->json([
            'success' => true,
            'data' => $banner,
        ]);
    }

    // POST /api/v1/admin/banners/image/sign
    public function signImageUpload(BannerSignImageRequest $request, SupabaseStorageService $supabase)
    {
        $adminId = auth()->id() ?? 0;

        $bucket  = (string) config('services.supabase.bucket_banners', 'banners');
        $expires = (int) config('services.supabase.sign_expires', 60);

        $mime = $request->input('mime');
        $path = $supabase->buildBannerPath($adminId, $mime);

        $signed = $supabase->createSignedUploadUrl($bucket, $path, $expires);

        return response()->json([
            'success' => true,
            'data' => [
                'path' => $signed['path'],
                'signed_url' => $signed['signedUrl'],
            ],
        ]);

        PublicCache::bumpContent();

    }

    // PATCH /api/v1/admin/banners/{banner}/image
    public function updateImage(BannerUpdateImageRequest $request, Banner $banner)
    {
        $banner->image_path = $request->input('image_path');
        $banner->save();

        return response()->json([
            'success' => true,
            'data' => $banner,
        ]);
        PublicCache::bumpContent();
    }
    

    // DELETE /api/v1/admin/banners/{banner}
    public function destroy(Banner $banner)
    {
        $banner->delete();

        return response()->json([
            'success' => true,
            'data' => true,
        ]);
        PublicCache::bumpContent();
    }
}
