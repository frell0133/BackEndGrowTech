<?php

namespace App\Http\Controllers\Api\V1\Content;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Models\Faq;
use App\Models\Page;
use App\Models\Popup;
use App\Services\SystemAccessService;
use App\Support\ApiResponse;
use App\Support\PublicCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ContentController extends Controller
{
    use ApiResponse;

    private function noStoreHeaders(): array
    {
        return [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ];
    }

    public function settings(Request $request)
    {
        $group = trim((string) $request->query('group', 'all'));
        $group = $group !== '' ? $group : 'all';

        if (in_array($group, ['website', 'system', 'contact', 'all'], true)) {
            $rows = DB::table('site_settings')
                ->where('is_public', true)
                ->when($group !== 'all', fn ($q) => $q->where('group', $group))
                ->orderBy('group')
                ->orderBy('key')
                ->get()
                ->map(function ($r) {
                    if (is_string($r->value)) {
                        $decoded = json_decode($r->value, true);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            $r->value = $decoded;
                        }
                    }

                    return $r;
                });

            return response()->json([
                'success' => true,
                'data' => $rows,
                'meta' => [
                    'generated_at' => now()->toIso8601String(),
                    'group' => $group,
                ],
                'error' => null,
            ], 200, $this->noStoreHeaders());
        }

        $cacheKey = 'settings:' . $group;

        $rows = PublicCache::rememberContent($cacheKey, 300, function () use ($group) {
            $q = DB::table('site_settings')
                ->where('is_public', true)
                ->orderBy('group')
                ->orderBy('key');

            if ($group !== 'all') {
                $q->where('group', $group);
            }

            return $q->get()->map(function ($r) {
                if (is_string($r->value)) {
                    $decoded = json_decode($r->value, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $r->value = $decoded;
                    }
                }

                return $r;
            });
        });

        return $this->ok($rows);
    }

    public function featureAccess(SystemAccessService $access)
    {
        $data = $access->featurePayload([
            'public_access',
            'user_auth_access',
            'catalog_access',
            'checkout_access',
            'topup_access',
        ], true);

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'generated_at' => now()->toIso8601String(),
            ],
            'error' => null,
        ], 200, $this->noStoreHeaders());
    }

    public function banners()
    {
        $data = PublicCache::rememberContent('banners', 300, function () {
            $base = rtrim(config('services.supabase.public_banners_base'), '/');

            $banners = Banner::query()
                ->where('is_active', true)
                ->whereNotNull('image_path')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'image_path', 'sort_order']);

            return $banners->map(function ($b) use ($base) {
                return [
                    'id' => $b->id,
                    'sort_order' => $b->sort_order,
                    'image_url' => $base . '/' . ltrim($b->image_path, '/'),
                ];
            })->values();
        });

        return response()->json([
            'success' => true,
            'data' => $data,
            'error' => null,
        ], 200, $this->noStoreHeaders());
    }

    public function popup(Request $request)
    {
        $target = (string) $request->query('target', 'all');

        $popup = PublicCache::rememberContent('popup:' . $target, 60, function () use ($target) {
            return Popup::query()
                ->where('is_active', true)
                ->whereIn('target', ['all', $target])
                ->orderByRaw("CASE WHEN target = ? THEN 0 ELSE 1 END", [$target])
                ->orderByDesc('id')
                ->first();
        });

        return $this->ok($popup);
    }

    public function page(string $slug)
    {
        $page = Page::query()
            ->where('slug', $slug)
            ->where('is_published', true)
            ->first();

        if (!$page) {
            return $this->fail('Page tidak ditemukan', 404);
        }

        return $this->ok($page);
    }

    public function faqs()
    {
        $faqs = PublicCache::rememberContent('faqs', 300, function () {
            return Faq::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();
        });

        return response()->json([
            'success' => true,
            'data' => $faqs,
            'error' => null,
        ], 200, $this->noStoreHeaders());
    }
}
