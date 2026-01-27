<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
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
        $data = $request->validate([
            'group' => ['required','string','max:64'],
            'key' => ['required','string','max:128'],
            'value' => ['nullable'],
            'is_public' => ['required','boolean'],
        ]);

        DB::table('site_settings')->updateOrInsert(
            ['group' => $data['group'], 'key' => $data['key']],
            [
                'value' => $data['value'] === null ? null : json_encode($data['value']),
                'is_public' => $data['is_public'],
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return $this->ok(['saved' => true]);
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
    }

    // POST /api/v1/admin/settings/icon/sign
    public function signIconUpload(SettingIconSignRequest $request, SupabaseStorageService $supabase)
    {
        $adminId = auth()->id() ?? 0;

        // pakai bucket_photos (default: photos)
        $bucket  = (string) config('services.supabase.bucket_icons', 'icons');
        $expires = (int) config('services.supabase.sign_expires', 60);

        $mime = $request->input('mime');
        $path = $supabase->buildSettingIconPath($adminId, $mime);

        $signed = $supabase->createSignedUploadUrl($bucket, $path, $expires);

        return response()->json([
            'success' => true,
            'data' => [
                'path' => $signed['path'],
                'signed_url' => $signed['signedUrl'],
                'public_url' => $supabase->publicObjectUrl($bucket, $signed['path']),
            ],
        ]);
    }

}
