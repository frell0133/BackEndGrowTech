<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthPasswordController extends Controller
{
    use ApiResponse;

    // POST /api/v1/auth/password/forgot
    public function forgot(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $status = Password::sendResetLink($data);

        // NOTE: biar aman dari email enumeration, kamu bisa selalu return ok.
        if ($status !== Password::RESET_LINK_SENT) {
            // versi strict:
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return $this->ok(['message' => 'If the email exists, a reset link has been sent.']);
    }

    // POST /api/v1/auth/password/reset
    public function reset(Request $request)
    {
        $data = $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'min:8', 'confirmed'],
        ]);

        $status = Password::reset(
            $data,
            function ($user, $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->save();

                // revoke sanctum tokens (recommended)
                if (method_exists($user, 'tokens')) {
                    $user->tokens()->delete();
                }
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return $this->ok(['message' => 'Password reset successful']);
    }
}
