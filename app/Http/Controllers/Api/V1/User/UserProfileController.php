<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use App\Services\SupabaseStorageService;
use Illuminate\Support\Facades\DB;

class UserProfileController extends Controller
{
    use ApiResponse;

    /**
     * GET /api/v1/auth/me/profile
     * Menampilkan profil user yang sedang login.
     */
    public function showProfile(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return $this->fail('Unauthenticated', 401);
        }

        return $this->ok($user);
    }

    /**
     * PATCH /api/v1/auth/me/profile
     * Update profil dasar user (full_name, address).
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return $this->fail('Unauthenticated', 401);
        }

        $validated = $request->validate([
            'full_name' => ['nullable', 'string', 'max:150'],
            'address'   => ['nullable', 'string', 'max:1000'],
        ]);

        // update yang dikirim saja
        $user->update($validated);

        return $this->ok($user, ['message' => 'Profil berhasil diperbarui']);
    }

    /**
     * PATCH /api/v1/auth/me/password
     * Update password user:
     * - wajib current_password
     * - password baru min 8 char + confirmed (butuh password_confirmation)
     */
    public function updatePassword(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return $this->fail('Unauthenticated', 401);
        }

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        // 1) validasi password lama
        if (!Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Password lama salah.'],
            ]);
        }

        // 2) optional: cegah password baru sama dengan password lama
        if (Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['Password baru tidak boleh sama dengan password lama.'],
            ]);
        }

        // 3) simpan password baru (di User casts() sudah 'hashed', jadi auto hash)
        $user->password = $data['password'];
        $user->save();

        // 4) OPTIONAL (lebih aman): logout semua token setelah ganti password
        // Kalau kamu ingin user otomatis logout dari semua device:
        //  $user->tokens()->delete();

        return $this->ok(['changed' => true], ['message' => 'Password berhasil diubah']);
    }
    public function signAvatarUpload(Request $request, SupabaseStorageService $supabase)
    {
        $user = $request->user();
        if (!$user) return $this->fail('Unauthenticated', 401);

        $data = $request->validate([
            'mime' => ['required','string','starts_with:image/'],
        ]);

        $bucket  = (string) config('services.supabase.bucket_avatars', 'avatars');
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
        if (!$user) return $this->fail('Unauthenticated', 401);

        // ✅ terima dua kemungkinan nama field dari FE: avatar_url atau avatar
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

        // ✅ simpan ke DB
        $user->avatar_path = $data['avatar_path'];
        $user->avatar      = $publicUrl;
        $user->save();
        $user->refresh();

        // (opsional) hapus file lama
        if ($oldPath && $oldPath !== $data['avatar_path']) {
            $bucket = (string) config('services.supabase.bucket_avatars', 'avatars');
            try { $supabase->deleteObjects($bucket, [$oldPath]); } catch (\Throwable $e) {}
        }

        // ✅ balikin juga avatar_url agar FE gampang
        $payload = $user->toArray();
        $payload['avatar_url'] = $user->avatar;

        // ✅ tambahan debug biar kamu yakin DB yang kepake apa
        $payload['_debug'] = [
            'db' => config('database.default'),
            'host' => config('database.connections.'.config('database.default').'.host'),
            'database' => config('database.connections.'.config('database.default').'.database'),
            'user_id' => $user->id,
        ];

        return $this->ok($payload, ['message' => 'Avatar berhasil diperbarui']);
    }

    public function deleteAvatar(Request $request, SupabaseStorageService $supabase)
    {
        $user = $request->user();
        if (!$user) return $this->fail('Unauthenticated', 401);

        $bucket = (string) config('services.supabase.bucket_avatars', 'avatars');
        $oldPath = $user->avatar_path;

        $user->avatar = null;
        $user->avatar_path = null;
        $user->save();

        if ($oldPath) {
            try { $supabase->deleteObjects($bucket, [$oldPath]); } catch (\Throwable $e) { /* abaikan */ }
        }

        return $this->ok(['deleted' => true], ['message' => 'Avatar dihapus']);
    }

}
