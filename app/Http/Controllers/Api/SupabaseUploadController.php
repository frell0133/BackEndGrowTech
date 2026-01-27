<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SupabaseStorageService;
use Illuminate\Http\Request;

class SupabaseUploadController extends Controller
{
public function sign(Request $request, SupabaseStorageService $service)
    {
        $payload = $request->validate([
            'mime' => ['required','string','starts_with:image/'],
        ]);

        $userId  = auth()->id() ?? 0;
        $bucket  = (string) config('services.supabase.bucket_banners', 'banners');
        $expires = (int) config('services.supabase.sign_expires', 60);

        $path = $service->buildBannerPath($userId, $payload['mime']);
        $signed = $service->createSignedUploadUrl($bucket, $path, $expires);

        return response()->json([
            'success' => true,
            'data' => [
                'path' => $signed['path'],
                'signed_url' => $signed['signedUrl'],
            ]
        ]);
    }
}
