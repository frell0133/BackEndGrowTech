<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    use ApiResponse;

    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required','string','max:120'],
            'email' => ['required','email','max:190','unique:users,email'],
            'password' => ['required','string','min:8','confirmed'],
            'referral_code' => ['nullable','string','max:50'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => 'user',
            'tier' => 'member',
        ]);

        $token = $user->createToken('api-token')->plainTextToken;

        return $this->ok([
            'user' => $user->only('id','name','email','role','tier'),
            'token' => $token,
            'token_type' => 'Bearer',
        ]);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required','email'],
            'password' => ['required'],
            'remember' => ['nullable','boolean'],
        ]);

        $remember = (bool)($credentials['remember'] ?? false);
        unset($credentials['remember']);

        if (!Auth::attempt($credentials, $remember)) {
            throw ValidationException::withMessages([
                'email' => ['Email atau password salah'],
            ]);
        }

        $user = Auth::user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'data' => null,
                'meta' => (object)[],
                'error' => ['message' => 'Unauthenticated'],
            ], 401);
        }

        $tokenName = $remember ? 'api-token-remember' : 'api-token';
        $token = $user->createToken($tokenName)->plainTextToken;

        return $this->ok([
            'user' => $user->only('id','name','email','role','tier'),
            'token' => $token,
            'token_type' => 'Bearer',
        ]);
    }

    public function logout(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'data' => null,
                'meta' => (object)[],
                'error' => ['message' => 'Unauthenticated'],
            ], 401);
        }

        $token = $user->currentAccessToken();
        if ($token) {
            $token->delete();
        }

        return $this->ok(['message' => 'Logged out']);
    }

    public function me(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'data' => null,
                'meta' => (object)[],
                'error' => ['message' => 'Unauthenticated'],
            ], 401);
        }

        return $this->ok($user->only('id','name','email','role','tier'));
    }
}