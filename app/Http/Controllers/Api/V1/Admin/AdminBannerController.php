<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BannerSignImageRequest;
use App\Http\Requests\Admin\BannerUpdateImageRequest;
use App\Models\Banner;
use App\Services\SupabaseStorageService;
use App\Support\PublicCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminBannerController extends Controller
{
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

    public function store(Request $request)
    {
        $payload = $request->validate([
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active'  => ['sometimes', 'boolean'],
            'image_path' => ['sometimes', 'nullable', 'string'],
        ]);

        $banner = Banner::create([
            'sort_order' => $payload['sort_order'] ?? 0,
            'is_active'  => $payload['is_active'] ?? true,
            'image_path' => $payload['image_path'] ?? null,
        ]);

        PublicCache::bumpContent();

        return response()->json([
            'success' => true,
            'data' => $banner,
        ], 201);
    }

    public function update(Request $request, Banner $banner)
    {
        $payload = $request->validate([
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active'  => ['sometimes', 'boolean'],
            'image_path' => ['sometimes', 'nullable', 'string'],
        ]);

        $banner->fill($payload)->save();

        PublicCache::bumpContent();

        return response()->json([
            'success' => true,
            'data' => $banner,
        ]);
    }


    public function reorder(Request $request)
    {
        $payload = $request->validate([
            'banners' => ['required', 'array', 'min:1'],
            'banners.*.id' => ['required', 'integer', 'exists:banners,id'],
            'banners.*.sort_order' => ['required', 'integer', 'min:0'],
        ]);

        DB::transaction(function () use ($payload) {
            foreach ($payload['banners'] as $item) {
                Banner::query()
                    ->whereKey((int) $item['id'])
                    ->update(['sort_order' => (int) $item['sort_order']]);
            }
        });

        PublicCache::bumpContent();

        $items = Banner::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $items,
        ]);
    }

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
    }

    public function updateImage(BannerUpdateImageRequest $request, Banner $banner)
    {
        $banner->image_path = $request->input('image_path');
        $banner->save();

        PublicCache::bumpContent();

        return response()->json([
            'success' => true,
            'data' => $banner,
        ]);
    }

    public function destroy(Banner $banner)
    {
        $banner->delete();

        PublicCache::bumpContent();

        return response()->json([
            'success' => true,
            'data' => true,
        ]);
    }
}