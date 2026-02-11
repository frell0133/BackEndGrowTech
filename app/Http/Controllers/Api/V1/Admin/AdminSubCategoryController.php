<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SupabaseStorageService
{
    private string $url;
    private string $key;

    public function __construct()
    {
        $this->url = rtrim((string) config('services.supabase.url'), '/');
        $this->key = (string) config('services.supabase.service_role_key');

        if (!$this->url || !$this->key) {
            throw new \RuntimeException('Supabase config missing: SUPABASE_URL / SUPABASE_SERVICE_ROLE_KEY');
        }
    }

    private function assertImageMime(string $mime): void
    {
        if (!str_starts_with($mime, 'image/')) {
            throw new \InvalidArgumentException('Only image mime types are allowed');
        }
    }

    private function imageExtFromMime(string $mime, string $default = 'jpg'): string
    {
        $this->assertImageMime($mime);

        return match ($mime) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => $default,
        };
    }

    /**
     * Create signed upload URL for Supabase Storage.
     * Handle response bentuk:
     * - {"url":"/object/upload/sign/<bucket>/<path>?token=...","token":"..."}
     * - atau {"signedUrl":"...","path":"..."}
     */
    public function createSignedUploadUrl(string $bucket, string $path, int $expiresInSeconds): array
    {
        $path = ltrim($path, '/');
        $endpoint = "{$this->url}/storage/v1/object/upload/sign/{$bucket}/{$path}";

        $res = Http::timeout(15)->withHeaders([
            'Authorization' => 'Bearer '.$this->key,
            'apikey' => $this->key,
            'Content-Type' => 'application/json',
        ])->post($endpoint, [
            'expiresIn' => $expiresInSeconds,
        ]);

        $json = $res->json();

        \Log::info('supabase_sign_upload_response', [
            'endpoint' => $endpoint,
            'status' => $res->status(),
            'body' => $res->body(),
            'json' => $json,
        ]);

        if (!$res->successful()) {
            throw new \RuntimeException("Supabase sign upload failed: {$res->status()} {$res->body()}");
        }

        // Versi baru bisa kasih signedUrl langsung
        $signed = $json['signedUrl'] ?? $json['signedURL'] ?? null;

        if (!$signed) {
            // Versi lama sering kasih "url" relatif
            $relativeUrl = $json['url'] ?? null;
            if (!$relativeUrl) {
                throw new \RuntimeException('Supabase response missing url/signedUrl: '.$res->body());
            }

            $signed = str_starts_with($relativeUrl, 'http')
                ? $relativeUrl
                : $this->url . '/storage/v1' . $relativeUrl;
        }

        return [
            'signedUrl' => $signed,
            'path' => $path,
            'token' => $json['token'] ?? null,
        ];
    }

    public function publicObjectUrl(string $bucket, string $path): string
    {
        $path = ltrim($path, '/');
        return $this->url . "/storage/v1/object/public/{$bucket}/{$path}";
    }

    /**
     * Build path logo subcategory yang rapi & aman.
     * hasil: subcategories/logos/<timestamp>-<random>.<ext>
     */
    public function buildSubCategoryLogoPath(string $mime): string
    {
        $ext  = $this->imageExtFromMime($mime, 'jpg');
        $name = now()->timestamp . '-' . Str::upper(Str::random(8)) . '.' . $ext;

        return "subcategories/logos/{$name}";
    }

    // (fungsi lain kamu seperti buildBannerPath, buildUserAvatarPath, deleteObjects, dll boleh tetap)
}
