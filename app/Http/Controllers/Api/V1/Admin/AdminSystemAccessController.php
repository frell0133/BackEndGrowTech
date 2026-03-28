<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\SystemAccessService;
use App\Support\ApiResponse;
use App\Support\PublicCache;
use Illuminate\Http\Request;

class AdminSystemAccessController extends Controller
{
    use ApiResponse;

    public function index()
    {
        $rows = Setting::query()
            ->where('group', 'system')
            ->orderBy('key')
            ->get();

        return $this->ok($rows);
    }

    public function upsert(Request $request)
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:128'],
            'value' => ['required', 'array'],
            'value.enabled' => ['required', 'boolean'],
            'value.message' => ['nullable', 'string', 'max:1000'],
        ]);

        $allowedKeys = [
            'public_access',
            'user_auth_access',
            'user_area_access',
            'catalog_access',
            'checkout_access',
            'topup_access',
        ];

        if (!in_array($data['key'], $allowedKeys, true)) {
            return $this->fail('System access key tidak valid.', 422);
        }

        $row = Setting::updateOrCreate(
            ['group' => 'system', 'key' => $data['key']],
            [
                'value' => [
                    'enabled' => (bool) ($data['value']['enabled'] ?? false),
                    'message' => (string) ($data['value']['message'] ?? ''),
                ],
                'is_public' => false,
            ]
        );

        $this->flushAccessCaches();

        return $this->ok($row);
    }

    public function destroy(Request $request)
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:128'],
        ]);

        $deleted = Setting::query()
            ->where('group', 'system')
            ->where('key', $data['key'])
            ->delete();

        if ($deleted) {
            $this->flushAccessCaches();
        }

        return $this->ok([
            'deleted' => (bool) $deleted,
            'key' => $data['key'],
        ]);
    }

    private function flushAccessCaches(): void
    {
        SystemAccessService::bumpCacheVersion();
        PublicCache::bumpContent();
    }
}
