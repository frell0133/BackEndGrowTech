<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Services\SupabaseStorageService;
use App\Services\TrustedDeviceService;
use App\Support\ApiResponse;
use App\Support\RuntimeCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UserProfileController extends Controller
{
    use ApiResponse;

    private const PROFILE_TTL = 15;
    private const PROFILE_VERSION_PREFIX = 'profile:version:user:';

    public function showProfile(Request $request, SupabaseStorageService $supabase)
    {
        $user = $request->user();
        if (!$user) {
            return $this->fail('Unauthenticated', 401);
        }

        $version = $this->currentProfileVersion((int) $user->id);
        $cacheKey = sprintf('profile:user:%d:v:%d', (int) $user->id, $version);

        $payload = RuntimeCache::remember($cacheKey, self::PROFILE_TTL, function () use ($user, $supabase) {
            $bucket = (string) config('services.supabase.bucket_avatars', 'avatars');

            $avatarUrl = null;
            if (!empty($user->avatar_path)) {
                $avatarUrl = $supabase->publicObjectUrl($bucket, $user->avatar_path);
            } elseif (!empty($user->avatar)) {
                $avatarUrl = $user->avatar;
            }

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'tier' => $user->tier ?? 'member',
                'avatar' => $user->avatar,
                'avatar_url' => $avatarUrl,
                'avatar_path' => $user->avatar_path,
                'full_name' => $user->full_name,
                'address' => $user->address,
            ];
        });

        return $this->ok($payload);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return $this->fail('Unauthenticated', 401);
        }

        $validated = $request->validate([
            'full_name' => ['nullable', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:1000'],
        ]);

        $user->update($validated);
        $this->bumpProfileVersion((int) $user->id);

        return $this->ok($user->fresh(), ['message' => 'Profil berhasil diperbarui']);
    }

    public function updatePassword(Request $request, TrustedDeviceService $trustedDeviceService)
    {
        $user = $request->user();
        if (!$user) {
            return $this->fail('Unauthenticated', 401);
        }

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (!Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Password lama salah.'],
            ]);
        }

        if (Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['Password baru tidak boleh sama dengan password lama.'],
            ]);
        }

        $user->password = $data['password'];
        $user->save();
        $user->tokens()->delete();
        $trustedDeviceService->revokeAllForUser($user);
        $this->bumpProfileVersion((int) $user->id);

        $response = $this->ok(['changed' => true], ['message' => 'Password berhasil diubah']);

        return $trustedDeviceService->clearTrustedDeviceCookie($response);
    }

    public function signAvatarUpload(Request $request, SupabaseStorageService $supabase)
    {
        $user = $request->user();
        if (!$user) {
            return $this->fail('Unauthenticated', 401);
        }

        $data = $request->validate([
            'mime' => ['required', 'string', 'starts_with:image/'],
        ]);

        $bucket = (string) config('services.supabase.bucket_avatars', 'avatars');
        $expires = (int) config('services.supabase.sign_expires', 60);

        $path = $supabase->buildUserAvatarPath($user->id, $data['mime']);
        $signed = $supabase->createSignedUploadUrl($bucket, $path, $expires);

        return $this->ok([
            'path' => $signed['path'],
            'signed_url' => $signed['signedUrl'],
            'public_url' => $supabase->publicObjectUrl($bucket, $signed['path']),
        ]);
    }

    public function updateAvatar(Request $request, SupabaseStorageService $supabase)
    {
        $user = $request->user();
        if (!$user) {
            return $this->fail('Unauthenticated', 401);
        }

        $data = $request->validate([
            'avatar_path' => ['required', 'string'],
            'avatar_url' => ['nullable', 'string'],
            'avatar' => ['nullable', 'string'],
        ]);

        $publicUrl = $data['avatar_url'] ?? $data['avatar'] ?? null;
        if (!$publicUrl) {
            return $this->fail('avatar_url/avatar is required', 422);
        }

        $oldPath = $user->avatar_path;

        $user->avatar_path = $data['avatar_path'];
        $user->avatar = $publicUrl;
        $user->save();
        $user->refresh();
        $this->bumpProfileVersion((int) $user->id);

        if ($oldPath && $oldPath !== $data['avatar_path']) {
            $bucket = (string) config('services.supabase.bucket_avatars', 'avatars');
            try {
                $supabase->deleteObjects($bucket, [$oldPath]);
            } catch (\Throwable $e) {
            }
        }

        $bucket = (string) config('services.supabase.bucket_avatars', 'avatars');
        $avatarUrl = !empty($user->avatar_path)
            ? $supabase->publicObjectUrl($bucket, $user->avatar_path)
            : ($user->avatar ?: null);

        $payload = $user->toArray();
        $payload['avatar_url'] = $avatarUrl;

        return $this->ok($payload, ['message' => 'Avatar berhasil diperbarui']);
    }

    public function deleteAvatar(Request $request, SupabaseStorageService $supabase)
    {
        $user = $request->user();
        if (!$user) {
            return $this->fail('Unauthenticated', 401);
        }

        $bucket = (string) config('services.supabase.bucket_avatars', 'avatars');
        $oldPath = $user->avatar_path;

        $user->avatar = null;
        $user->avatar_path = null;
        $user->save();
        $this->bumpProfileVersion((int) $user->id);

        if ($oldPath) {
            try {
                $supabase->deleteObjects($bucket, [$oldPath]);
            } catch (\Throwable $e) {
            }
        }

        return $this->ok(['deleted' => true], ['message' => 'Avatar dihapus']);
    }

    private function currentProfileVersion(int $userId): int
    {
        $key = self::PROFILE_VERSION_PREFIX . $userId;
        $value = RuntimeCache::get($key);

        if (!$value) {
            RuntimeCache::forever($key, 1);
            return 1;
        }

        return (int) $value;
    }

    private function bumpProfileVersion(int $userId): void
    {
        $key = self::PROFILE_VERSION_PREFIX . $userId;

        if (!RuntimeCache::has($key)) {
            RuntimeCache::forever($key, 1);
        }

        RuntimeCache::increment($key);
    }
}
