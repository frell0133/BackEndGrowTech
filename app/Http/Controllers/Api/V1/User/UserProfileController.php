<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use App\Services\SupabaseStorageService;

class UserProfileController extends Controller
{
    use ApiResponse;

    /**
     * GET /api/v1/auth/me/profile
     * Menampilkan profil user yang sedang login.
     * ✅ Selalu return avatar_url (Supabase > Provider > null)
     */
    public function showProfile(Request $request, SupabaseStorageService $supabase)
    {
        $user = $request->user();
        if (!$user) return $this->fail('Unauthenticated', 401);

        $bucket = (string) config('services.supabase.bucket_avatars', 'avatars');

        $avatarUrl = null;
        if (!empty($user->avatar_path)) {
            $avatarUrl = $supabase->publicObjectUrl($bucket, $user->avatar_path);
        } elseif (!empty($user->avatar)) {
            $avatarUrl = $user->avatar; // OAuth (Google/Discord)
        }

        return $this->ok([
            'id'         => $user->id,
            'name'       => $user->name,
            'email'      => $user->email,
            'role'       => $user->role,
            'tier'       => $user->tier ?? 'member',
            'avatar'     => $user->avatar,      // 🔥 PASTIKAN TERKIRIM
            'avatar_url' => $avatarUrl,         // 🔥 URL FINAL
            'avatar_path'=> $user->avatar_path, // optional
            'full_name'  => $user->full_name,
            'address'    => $user->address,
        ]);
    }

    /**
     * PATCH /api/v1/auth/me/profile
     * Update profil dasar user (full_name, address).
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();
        if (!$user) return $this->fail('Unauthenticated', 401);

        $validated = $request->validate([
            'full_name' => ['nullable', 'string', 'max:150'],
            'address'   => ['nullable', 'string', 'max:1000'],
        ]);

        $user->update($validated);

        return $this->ok($user->fresh(), ['message' => 'Profil berhasil diperbarui']);
    }

    /**
     * PATCH /api/v1/auth/me/password
     * Update password user
     */
    public function updatePassword(Request $request)
    {
        $user = $request->user();
        if (!$user) return $this->fail('Unauthenticated', 401);

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

        return $this->ok(['changed' => true], ['message' => 'Password berhasil diubah']);
    }

    /**
     * POST /api/v1/auth/me/avatar/sign
     * Minta signed upload URL untuk Supabase Storage
     */
    public function signAvatarUpload(Request $request, SupabaseStorageService $supabase)
    {
        $user = $request->user();
        if (!$user) return $this->fail('Unauthenticated', 401);

        $data = $request->validate([
            'mime' => ['required','string','starts_with:image/'],
        ]);

        $bucket  = (string) config('services.supabase.bucket_avatars', 'avatars');
        $expires = (int) config('services.supabase.sign_expires', 60);

        $path   = $supabase->buildUserAvatarPath($user->id, $data['mime']);
        $signed = $supabase->createSignedUploadUrl($bucket, $path, $expires);

        return $this->ok([
            'path' => $signed['path'],
            'signed_url' => $signed['signedUrl'],
            'public_url' => $supabase->publicObjectUrl($bucket, $signed['path']),
        ]);
    }

    /**
     * PATCH /api/v1/auth/me/avatar
     * Simpan avatar_path + avatar(public url) ke DB, hapus file lama opsional
     */
    public function updateAvatar(Request $request, SupabaseStorageService $supabase)
    {
        $user = $request->user();
        if (!$user) return $this->fail('Unauthenticated', 401);

        $data = $request->validate([
            'avatar_path' => ['required','string'],
            'avatar_url'  => ['nullable','string'],
            'avatar'      => ['nullable','string'],
        ]);

        $publicUrl = $data['avatar_url'] ?? $data['avatar'] ?? null;
        if (!$publicUrl) {
            return $this->fail('avatar_url/avatar is required', 422);
        }

        $oldPath = $user->avatar_path;

        $user->avatar_path = $data['avatar_path'];
        $user->avatar      = $publicUrl;
        $user->save();
        $user->refresh();

        // hapus file lama (opsional)
        if ($oldPath && $oldPath !== $data['avatar_path']) {
            $bucket = (string) config('services.supabase.bucket_avatars', 'avatars');
            try { $supabase->deleteObjects($bucket, [$oldPath]); } catch (\Throwable $e) {}
        }

        // ✅ return avatar_url final supaya FE gampang
        $bucket = (string) config('services.supabase.bucket_avatars', 'avatars');
        $avatarUrl = !empty($user->avatar_path)
            ? $supabase->publicObjectUrl($bucket, $user->avatar_path)
            : ($user->avatar ?: null);

        $payload = $user->toArray();
        $payload['avatar_url'] = $avatarUrl;

        return $this->ok($payload, ['message' => 'Avatar berhasil diperbarui']);
    }

    /**
     * DELETE /api/v1/auth/me/avatar
     */
    public function deleteAvatar(Request $request, SupabaseStorageService $supabase)
    {
        $user = $request->user();
        if (!$user) return $this->fail('Unauthenticated', 401);

        $bucket  = (string) config('services.supabase.bucket_avatars', 'avatars');
        $oldPath = $user->avatar_path;

        $user->avatar = null;
        $user->avatar_path = null;
        $user->save();

        if ($oldPath) {
            try { $supabase->deleteObjects($bucket, [$oldPath]); } catch (\Throwable $e) {}
        }

        return $this->ok(['deleted' => true], ['message' => 'Avatar dihapus']);
    }
}
