<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Hash;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;

class AuthPasswordController extends Controller
{
    use ApiResponse;


    public function forgot(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $user = \App\Models\User::where('email', $request->email)->first();

        if ($user) {
            $token = Password::createToken($user);

            $resetUrl = config('app.frontend_url') .
                "/reset-password?token={$token}&email=" .
                urlencode($user->email);

            Http::withHeaders([
                'api-key' => config('services.brevo.key'),
                'Content-Type' => 'application/json',
            ])->post('https://api.brevo.com/v3/smtp/email', [
                'sender' => [
                    'email' => config('services.brevo.sender'),
                    'name' => 'GrowTech',
                ],
                'to' => [
                    ['email' => $user->email],
                ],
                'subject' => 'Reset Password',
                'htmlContent' => "
                    <p>Klik link berikut:</p>
                    <a href='{$resetUrl}'>Reset Password</a>
                ",
            ]);
        }

        return $this->ok([
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
