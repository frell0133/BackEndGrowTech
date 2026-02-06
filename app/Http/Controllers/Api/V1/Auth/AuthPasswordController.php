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

class AuthPasswordController extends Controller
{
    use ApiResponse;

    /**
     * Kirim link reset password (Brevo API)
     */
    public function forgot(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = \App\Models\User::where('email', $request->email)->first();

        // ❗ Jangan bocorkan apakah email ada atau tidak
        if ($user) {
            $token = Password::createToken($user);

            // ✅ SESUAI DENGAN STRUKTUR FE (app/reset-password/page.jsx)
            $resetUrl = rtrim(config('app.frontend_url'), '/')
                . '/reset-password'
                . '?token=' . urlencode($token)
                . '&email=' . urlencode($user->email);

            $html = view('emails.reset-password', [
                'resetUrl' => $resetUrl,
            ])->render();

            $response = Http::withHeaders([
                'api-key' => config('services.brevo.key'),
                'Content-Type' => 'application/json',
            ])->post('https://api.brevo.com/v3/smtp/email', [
                'sender' => [
                    'email' => config('services.brevo.sender_email'),
                    'name' => config('services.brevo.sender_name'),
                ],
                'to' => [
                    ['email' => $user->email],
                ],
                'subject' => 'Reset Password',
                'htmlContent' => $html,
            ]);

            if ($response->failed()) {
                \Log::error('Brevo reset password failed', [
                    'email' => $user->email,
                    'response' => $response->body(),
                ]);
            }
        }

        return $this->ok([
            'message' => 'Jika email terdaftar, link reset password akan dikirim.',
        ]);
    }

    /**
     * Proses reset password
     */
    public function reset(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
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