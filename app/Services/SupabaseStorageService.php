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

    /**
     * Helper: pastikan mime image dan mapping ext yang aman.
     */
    private function assertImageMime(string $mime): void
    {
        if (!str_starts_with($mime, 'image/')) {
            throw new \InvalidArgumentException('Only image mime types are allowed');
        }
    }

    /**
     * Helper: convert mime -> ext yang dipakai untuk file.
     */
    private function imageExtFromMime(string $mime, string $default = 'jpg'): string
    {
        $this->assertImageMime($mime);

        // Normalisasi mime umum
        return match ($mime) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => $default,
        };
    }

    /**
     * Create signed upload URL for Supabase Storage.
     * Supabase bisa return:
     *  - {"url":"/object/upload/sign/<bucket>/<path>?token=...","token":"..."}
     *  - atau {"signedUrl":"...","path":"..."} (tergantung versi)
     *
     * Kita convert jadi FULL URL siap PUT.
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

        // Handle berbagai format response
        $signed = $json['signedUrl'] ?? $json['signedURL'] ?? null;

        if (!$signed) {
            // Supabase sign upload seringnya balikin "url" relatif
            $relativeUrl = $json['url'] ?? null;
            if (!$relativeUrl) {
                throw new \RuntimeException('Supabase response missing signed upload url: '.$res->body());
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

    /**
     * Create signed download URL (kalau bucket private).
     */
    public function createSignedDownloadUrl(string $bucket, string $path, int $expiresInSeconds): string
    {
        $path = ltrim($path, '/');
        $endpoint = "{$this->url}/storage/v1/object/sign/{$bucket}/{$path}";

        $res = Http::timeout(15)->withHeaders([
            'Authorization' => 'Bearer '.$this->key,
            'apikey' => $this->key,
            'Content-Type' => 'application/json',
        ])->post($endpoint, [
            'expiresIn' => $expiresInSeconds,
        ]);

        $json = $res->json();

        \Log::info('supabase_sign_download_response', [
            'endpoint' => $endpoint,
            'status' => $res->status(),
            'body' => $res->body(),
            'json' => $json,
        ]);

        if (!$res->successful()) {
            throw new \RuntimeException("Supabase sign download failed: {$res->status()} {$res->body()}");
        }

        $signed = $json['signedURL'] ?? $json['signedUrl'] ?? $json['url'] ?? null;
        if (!$signed) {
            throw new \RuntimeException('Supabase response missing signed download url: '.$res->body());
        }

        if (!str_starts_with($signed, 'http')) {
            $signed = $this->url . '/storage/v1' . $signed;
        }

        return $signed;
    }

    /**
     * Delete objects by paths.
     */
    public function deleteObjects(string $bucket, array $paths): void
    {
        $endpoint = "{$this->url}/storage/v1/object/{$bucket}";

        $res = Http::timeout(15)->withHeaders([
            'Authorization' => 'Bearer '.$this->key,
            'apikey' => $this->key,
            'Content-Type' => 'application/json',
        ])->post($endpoint, [
            'prefixes' => array_values(array_filter(array_map(fn($p) => ltrim((string)$p, '/'), $paths))),
        ]);

        \Log::info('supabase_delete_response', [
            'endpoint' => $endpoint,
            'status' => $res->status(),
            'body' => $res->body(),
        ]);

        if (!$res->successful()) {
            throw new \RuntimeException("Supabase delete failed: {$res->status()} {$res->body()}");
        }
    }

    /**
     * Public URL object.
     */
    public function publicObjectUrl(string $bucket, string $path): string
    {
        $path = ltrim($path, '/');
        return $this->url . "/storage/v1/object/public/{$bucket}/{$path}";
    }

    /**
     * Build path for banner images.
     */
    public function buildBannerPath(int|string $adminId, string $mime): string
    {
        $ext = $this->imageExtFromMime($mime, 'jpg');
        $file = now()->timestamp . '-' . Str::uuid() . '.' . $ext;

        return "admin/{$adminId}/banners/{$file}";
    }

    /**
     * Build path for setting icon images.
     */
    public function buildSettingIconPath(int|string $adminId, string $mime): string
    {
        $ext = $this->imageExtFromMime($mime, 'png');
        $file = now()->timestamp . '-' . Str::uuid() . '.' . $ext;

        return "admin/{$adminId}/settings/icons/{$file}";
    }

    /**
     * Build path for user avatar images.
     */
    public function buildUserAvatarPath(int|string $userId, string $mime): string
    {
        $ext = $this->imageExtFromMime($mime, 'jpg');
        $file = now()->timestamp . '-' . Str::uuid() . '.' . $ext;

        return "users/{$userId}/avatar/{$file}";
    }

    // =========================================================
    // ✅ TAMBAHAN BARU: SUBCATEGORIES (yang kamu minta)
    // =========================================================

    /**
     * Build path for subcategory logo.
     * Rapi: subcategories/logos/<timestamp>-<random>.<ext>
     */
    public function buildSubCategoryLogoPath(string $mime): string
    {
        $ext = $this->imageExtFromMime($mime, 'jpg');
        $file = now()->timestamp . '-' . Str::upper(Str::random(8)) . '.' . $ext;

        return "subcategories/logos/{$file}";
    }

    /**
     * Helper lengkap buat flow subcategory:
     * - tentukan bucket dari config (fallback 'subcategories')
     * - generate path
     * - sign upload
     * - return signedUrl + publicUrl
     *
     * Balikan sudah cocok untuk FE:
     * { path, signedUrl, publicUrl }
     */
    public function signSubCategoryLogoUpload(string $mime, int $expiresInSeconds = 60): array
    {
        $bucket = (string) config('services.supabase.bucket_subcategories', 'subcategories');
        $path = $this->buildSubCategoryLogoPath($mime);

        $signed = $this->createSignedUploadUrl($bucket, $path, $expiresInSeconds);

        return [
            'path' => $signed['path'],
            'signedUrl' => $signed['signedUrl'],
            'publicUrl' => $this->publicObjectUrl($bucket, $signed['path']),
        ];
    }
}
