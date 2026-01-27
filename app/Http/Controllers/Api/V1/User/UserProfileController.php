<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

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
}
