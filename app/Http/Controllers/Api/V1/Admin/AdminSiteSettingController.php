<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Support\PublicCache;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Requests\Admin\SettingIconSignRequest;
use App\Services\SupabaseStorageService;


class AdminSiteSettingController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $group = $request->query('group');

        $q = DB::table('site_settings')->orderBy('group')->orderBy('key');
        if ($group) {
            $q->where('group', $group);
        }

        $rows = $q->get()->map(function ($r) {
            // jsonb di postgres kadang sudah object, tapi aman kita decode kalau string
            if (is_string($r->value)) {
                $r->value = json_decode($r->value, true);
            }
            return $r;
        });

        return $this->ok($rows);
    }

    public function upsert(Request $request)
    {
        $group = (string) $request->input('group');
        $key   = (string) $request->input('key');

        $rules = [
            'group' => ['required','string','max:64'],
            'key' => ['required','string','max:128'],
            'value' => ['required'],
            'is_public' => ['required','boolean'],
        ];

        // Contact rules
        if ($group === 'contact') {
            $rules['value'] = ['required','array'];
            $rules['value.name'] = ['required','string'];
            $rules['value.link'] = ['required','string'];
            $rules['value.display'] = ['nullable','string'];
            $rules['value.icon_path'] = ['required','string'];
            $rules['value.icon_url']  = ['required','string'];
        }

        // ✅ Payment rules (fee_percent / tax_percent)
        if ($group === 'payment' && in_array($key, ['fee_percent', 'tax_percent'], true)) {
            // kita prefer format { "percent": 0.7 }
            $rules['value'] = ['required','array'];
            $rules['value.percent'] = ['required','numeric','min:0','max:100'];
        }

        $data = $request->validate($rules);

        // simpan value sebagai JSON string
        $value = $data['value'];
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        DB::table('site_settings')->updateOrInsert(
            ['group' => $data['group'], 'key' => $data['key']],
            [
                'value' => $value,
                'is_public' => $data['is_public'],
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        
        return response()->json(['success' => true, 'data' => ['saved' => true]]);

        PublicCache::bumpContent();
        
    }

    public function destroy(Request $request)
    {
        $data = $request->validate([
            'group' => ['required','string'],
            'key' => ['required','string'],
        ]);

        DB::table('site_settings')
            ->where('group', $data['group'])
            ->where('key', $data['key'])
            ->delete();

        return $this->ok(['deleted' => true]);
        
        PublicCache::bumpContent();
        
    }

    // POST /api/v1/admin/settings/icon/sign
    public function signIconUpload(Request $request, SupabaseStorageService $supabase)
    {
        $data = $request->validate([
            'mime' => ['required','string','starts_with:image/'],
        ]);

        $adminId = auth()->id() ?? 0;

        $bucket  = (string) config('services.supabase.bucket_icons', 'icons');
        $expires = (int) config('services.supabase.sign_expires', 60);

        $path = $supabase->buildSettingIconPath($adminId, $data['mime']);
        $signed = $supabase->createSignedUploadUrl($bucket, $path, $expires);

        return $this->ok([
            'path' => $signed['path'],
            'signed_url' => $signed['signedUrl'],
            'public_url' => $supabase->publicObjectUrl($bucket, $signed['path']),
        ]);
    }

    

}
