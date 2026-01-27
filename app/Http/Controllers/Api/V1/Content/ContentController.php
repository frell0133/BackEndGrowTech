<?php

namespace App\Http\Controllers\Api\V1\Content;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Models\Banner;
use App\Models\Popup;
use App\Models\Page;
use App\Models\Faq;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ContentController extends Controller
{
    use ApiResponse;

    /**
     * Public settings
     * GET /api/v1/content/settings?group=contact
     */
    public function settings(Request $request)
    {
        $group = $request->query('group');

        $q = DB::table('site_settings')
            ->where('is_public', true)
            ->orderBy('group')
            ->orderBy('key');

        if (!empty($group)) {
            $q->where('group', $group);
        }

        $rows = $q->get()->map(function ($r) {
            if (is_string($r->value)) {
                $decoded = json_decode($r->value, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $r->value = $decoded;
                }
            }
            return $r;
        });

        return $this->ok($rows);
    }

    /**
     * Public banners
     * GET /api/v1/content/banners
     */
    public function banners()
    {
        $base = rtrim(config('services.supabase.public_banners_base'), '/');

        $banners = Banner::query()
            ->where('is_active', true)
            ->whereNotNull('image_path')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'image_path', 'sort_order']);

        $data = $banners->map(function ($b) use ($base) {
            return [
                'id' => $b->id,
                'sort_order' => $b->sort_order,
                'image_url' => $base . '/' . ltrim($b->image_path, '/'),
            ];
        })->values();

        return $this->ok($data);
    }

    /**
     * Active popup by target
     * GET /api/v1/content/popup?target=home
     */
    public function popup(Request $request)
    {
        $target = $request->query('target', 'all');

        $popup = Popup::query()
            ->where('is_active', true)
            ->whereIn('target', ['all', $target])
            ->orderByRaw("CASE WHEN target = ? THEN 0 ELSE 1 END", [$target])
            ->orderByDesc('id')
            ->first();

        return $this->ok($popup);
    }

    /**
     * Static page
     * GET /api/v1/content/pages/{slug}
     */
    public function page(string $slug)
    {
        $page = Page::query()
            ->where('slug', $slug)
            ->where('is_published', true)
            ->first();

        if (!$page) return $this->fail('Page tidak ditemukan', 404);

        return $this->ok($page);
    }

    /**
     * FAQ list (PUBLIC)
     * GET /api/v1/content/faqs
     * Only active
     */
    public function faqs()
    {
        $faqs = Faq::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return $this->ok($faqs);
    }
}
