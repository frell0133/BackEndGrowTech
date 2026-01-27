<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\SupabaseStorageService;
use Illuminate\Http\Request;

class UploadController extends Controller
{
    public function sign(Request $request, SupabaseStorageService $supabase)
    {
        $payload = $request->validate([
            'mime' => ['required', 'string', 'starts_with:image/'],
            'type' => ['required', 'string'],
        ]);

        $adminId = auth()->id() ?? 0;
        $bucket  = (string) config('services.supabase.bucket_banners', 'banners');
        $expires = (int) config('services.supabase.sign_expires', 60);

        // sederhana: pakai path banner dulu
        $path = $supabase->buildBannerPath($adminId, $payload['mime']);

        $signed = $supabase->createSignedUploadUrl($bucket, $path, $expires);

        return response()->json([
            'success' => true,
            'data' => [
                'path' => $signed['path'],
                'signed_url' => $signed['signedUrl'],
            ],
        ]);
    }
}
