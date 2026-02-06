<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Hash;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Str;

class AuthPasswordController extends Controller
{
    use ApiResponse;

    public function forgot(Request $request)
    {
        $data = $request->validate([
            'email' => ['required','email'],
        ]);

        $status = Password::sendResetLink($data);

        // jangan bocorin apakah email ada / tidak
        return $this->ok([
            'status' => $status,
            'message' => 'Jika email terdaftar, link reset password akan dikirim.',
        ]);
    }

    public function reset(Request $request)
    {
        $data = $request->validate([
            'email' => ['required','email'],
            'token' => ['required','string'],
            'password' => ['required','string','min:8','confirmed'],
        ]);

        $status = Password::reset(
            $data,
            function ($user) use ($data) {
                $user->forceFill([
                    'password' => Hash::make($data['password']),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return $this->fail('Reset password gagal', 422, [
                'status' => $status,
            ]);
        }

        return $this->ok([
            'status' => $status,
            'message' => 'Password berhasil direset. Silakan login kembali.',
        ]);
    }
}
