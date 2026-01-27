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
     * Create signed upload URL for Supabase Storage.
     * Supabase returns: {"url":"/object/upload/sign/<bucket>/<path>?token=...","token":"..."}
     * We convert it into a FULL URL: https://<project>.supabase.co/storage/v1 + url
     */
    public function createSignedUploadUrl(string $bucket, string $path, int $expiresInSeconds): array
    {
        $endpoint = "{$this->url}/storage/v1/object/upload/sign/{$bucket}/" . ltrim($path, '/');

        $res = Http::timeout(15)->withHeaders([
            'Authorization' => 'Bearer '.$this->key,
            'apikey' => $this->key,
            'Content-Type' => 'application/json',
        ])->post($endpoint, [
            'expiresIn' => $expiresInSeconds,
        ]);

        $json = $res->json();

        // LOG (biar gampang debug)
        \Log::info('supabase_sign_upload_response', [
            'endpoint' => $endpoint,
            'status' => $res->status(),
            'body' => $res->body(),
            'json' => $json,
        ]);

        if (!$res->successful()) {
            throw new \RuntimeException("Supabase sign upload failed: {$res->status()} {$res->body()}");
        }

        // Supabase sign upload balikin "url" (relative)
        $relativeUrl = $json['url'] ?? null;
        if (!$relativeUrl) {
            throw new \RuntimeException('Supabase response missing url: '.$res->body());
        }

        // Jadikan FULL URL yang bisa langsung dipakai PUT
        // relativeUrl biasanya mulai dengan /object/...
        $signedUrl = str_starts_with($relativeUrl, 'http')
            ? $relativeUrl
            : $this->url . '/storage/v1' . $relativeUrl;

        return [
            'signedUrl' => $signedUrl,
            'path' => $path,
            'token' => $json['token'] ?? null,
        ];
    }

    /**
     * Create signed download URL (kalau bucket private dan kamu mau generate link download sementara).
     * Biasanya Supabase balikin {"signedURL":"..."} atau {"signedUrl":"..."} tergantung versi,
     * tapi bisa juga balikin "url". Kita handle semua.
     */
    public function createSignedDownloadUrl(string $bucket, string $path, int $expiresInSeconds): string
    {
        $endpoint = "{$this->url}/storage/v1/object/sign/{$bucket}/" . ltrim($path, '/');

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

        // Beberapa versi balikin signedURL/signedUrl, beberapa balikin url
        $signed = $json['signedURL'] ?? $json['signedUrl'] ?? $json['url'] ?? null;
        if (!$signed) {
            throw new \RuntimeException('Supabase response missing signed download url: '.$res->body());
        }

        // Kalau relatif, jadikan full
        if (!str_starts_with($signed, 'http')) {
            $signed = $this->url . '/storage/v1' . $signed;
        }

        return $signed;
    }

    /**
     * Delete objects by paths.
     * Endpoint: POST /storage/v1/object/{bucket} with body {"prefixes":[...]}
     */
    public function deleteObjects(string $bucket, array $paths): void
    {
        $endpoint = "{$this->url}/storage/v1/object/{$bucket}";

        $res = Http::timeout(15)->withHeaders([
            'Authorization' => 'Bearer '.$this->key,
            'apikey' => $this->key,
            'Content-Type' => 'application/json',
        ])->post($endpoint, [
            'prefixes' => array_values(array_filter($paths)),
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
     * Build path for banner images.
     */
    public function buildBannerPath(int|string $adminId, string $mime): string
    {
        if (!str_starts_with($mime, 'image/')) {
            throw new \InvalidArgumentException('Only image mime types are allowed');
        }

        $ext = explode('/', $mime)[1] ?? 'jpg';
        $file = now()->timestamp . '-' . Str::uuid() . '.' . $ext;

        return "admin/{$adminId}/banners/{$file}";
    }
}
