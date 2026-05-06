<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Models\AuthChallenge;
use App\Models\User;
use App\Services\TwoFactorService;
use App\Support\ApiResponse;
use App\Support\RuntimeCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserEmailChangeController extends Controller
{
    use ApiResponse;

    private const OLD_TOKEN_TTL = 600; // 10 menit
    private const PROFILE_VERSION_PREFIX = 'profile:version:user:';

    public function requestCurrent(Request $request, TwoFactorService $twoFactor)
    {
        $user = $this->currentUser($request);
        $this->ensureManualAccount($user);

        $result = $twoFactor->startChallenge($user, 'email_change_current', [
            'email' => $this->normalizeEmail((string) $user->email),
            'provider' => 'manual',
            'meta' => [
                'step' => 'current_email',
                'current_email' => $this->normalizeEmail((string) $user->email),
            ],
        ]);

        if (!$result['ok']) {
            return $this->fail(
                $result['message'] ?? 'Gagal mengirim OTP ke email lama.',
                $result['status'] ?? 422,
                $result['details'] ?? null
            );
        }

        return $this->ok([
            'challenge_id' => $result['challenge_id'],
            'expires_in' => $result['expires_in'],
            'email_hint' => $result['email_hint'],
        ], [
            'message' => 'OTP verifikasi berhasil dikirim ke email lama kamu.',
        ]);
    }

    public function verifyCurrent(Request $request, TwoFactorService $twoFactor)
    {
        $user = $this->currentUser($request);
        $this->ensureManualAccount($user);

        $data = $request->validate([
            'challenge_id' => ['required', 'string'],
            'code' => ['required', 'digits:6'],
        ], [
            'code.digits' => 'Kode OTP email lama harus 6 digit.',
        ]);

        $result = $twoFactor->verifyChallenge((string) $data['challenge_id'], (string) $data['code']);

        if (!$result['ok']) {
            return $this->fail(
                $result['message'] ?? 'OTP email lama tidak valid.',
                $result['status'] ?? 422,
                $result['details'] ?? null
            );
        }

        /** @var AuthChallenge $challenge */
        $challenge = $result['challenge'];
        $this->ensureChallengeBelongsToUser($challenge, $user, 'email_change_current');

        $challengeEmail = $this->normalizeEmail((string) $challenge->email);
        $currentEmail = $this->normalizeEmail((string) $user->email);

        if ($challengeEmail !== $currentEmail) {
            return $this->fail('OTP email lama tidak cocok dengan email akun saat ini. Silakan ulangi proses.', 422);
        }

        $token = Str::random(80);
        RuntimeCache::put($this->oldEmailTokenKey((int) $user->id, $token), [
            'user_id' => (int) $user->id,
            'current_email' => $currentEmail,
            'verified_at' => now()->toIso8601String(),
        ], self::OLD_TOKEN_TTL);

        return $this->ok([
            'old_email_token' => $token,
            'expires_in' => self::OLD_TOKEN_TTL,
        ], [
            'message' => 'Email lama berhasil diverifikasi. Sekarang masukkan email baru.',
        ]);
    }

    public function requestNew(Request $request, TwoFactorService $twoFactor)
    {
        $user = $this->currentUser($request);
        $this->ensureManualAccount($user);

        $data = $request->validate([
            'old_email_token' => ['required', 'string'],
            'new_email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
        ], [
            'new_email.required' => 'Email baru wajib diisi.',
            'new_email.email' => 'Format email baru tidak valid.',
            'new_email.unique' => 'Email baru sudah digunakan oleh akun lain.',
        ]);

        $oldToken = (string) $data['old_email_token'];
        $oldProof = $this->getValidOldEmailProof($user, $oldToken);
        $newEmail = $this->normalizeEmail((string) $data['new_email']);
        $currentEmail = $this->normalizeEmail((string) $user->email);

        if ($newEmail === $currentEmail) {
            return $this->fail('Email baru tidak boleh sama dengan email lama.', 422);
        }

        if (($oldProof['current_email'] ?? null) !== $currentEmail) {
            RuntimeCache::forget($this->oldEmailTokenKey((int) $user->id, $oldToken));
            return $this->fail('Verifikasi email lama tidak cocok dengan email akun saat ini. Silakan ulangi proses.', 422);
        }

        $result = $twoFactor->startChallenge($user, 'email_change_new', [
            'email' => $newEmail,
            'provider' => 'manual',
            'meta' => [
                'step' => 'new_email',
                'old_email_token' => $oldToken,
                'old_email' => $currentEmail,
                'new_email' => $newEmail,
            ],
        ]);

        if (!$result['ok']) {
            return $this->fail(
                $result['message'] ?? 'Gagal mengirim OTP ke email baru.',
                $result['status'] ?? 422,
                $result['details'] ?? null
            );
        }

        return $this->ok([
            'challenge_id' => $result['challenge_id'],
            'expires_in' => $result['expires_in'],
            'email_hint' => $result['email_hint'],
            'new_email' => $newEmail,
        ], [
            'message' => 'OTP verifikasi berhasil dikirim ke email baru.',
        ]);
    }

    public function verifyNew(Request $request, TwoFactorService $twoFactor)
    {
        $user = $this->currentUser($request);
        $this->ensureManualAccount($user);

        $data = $request->validate([
            'challenge_id' => ['required', 'string'],
            'old_email_token' => ['required', 'string'],
            'code' => ['required', 'digits:6'],
        ], [
            'code.digits' => 'Kode OTP email baru harus 6 digit.',
        ]);

        $oldToken = (string) $data['old_email_token'];
        $oldProof = $this->getValidOldEmailProof($user, $oldToken);

        $result = $twoFactor->verifyChallenge((string) $data['challenge_id'], (string) $data['code']);

        if (!$result['ok']) {
            return $this->fail(
                $result['message'] ?? 'OTP email baru tidak valid.',
                $result['status'] ?? 422,
                $result['details'] ?? null
            );
        }

        /** @var AuthChallenge $challenge */
        $challenge = $result['challenge'];
        $this->ensureChallengeBelongsToUser($challenge, $user, 'email_change_new');

        $meta = (array) ($challenge->meta ?? []);
        $newEmail = $this->normalizeEmail((string) ($meta['new_email'] ?? $challenge->email ?? ''));
        $challengeOldToken = (string) ($meta['old_email_token'] ?? '');
        $currentEmail = $this->normalizeEmail((string) $user->email);

        if ($challengeOldToken === '' || !hash_equals($oldToken, $challengeOldToken)) {
            return $this->fail('Token verifikasi email lama tidak cocok. Silakan ulangi proses.', 422);
        }

        if (($oldProof['current_email'] ?? null) !== $currentEmail) {
            RuntimeCache::forget($this->oldEmailTokenKey((int) $user->id, $oldToken));
            return $this->fail('Email akun sudah berubah. Silakan ulangi proses ganti email.', 422);
        }

        if ($newEmail === '' || $newEmail === $currentEmail) {
            return $this->fail('Email baru tidak valid atau sama dengan email lama.', 422);
        }

        if (User::query()->where('email', $newEmail)->whereKeyNot($user->id)->exists()) {
            return $this->fail('Email baru sudah digunakan oleh akun lain.', 422);
        }

        $updatedUser = DB::transaction(function () use ($user, $newEmail) {
            /** @var User $lockedUser */
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $this->ensureManualAccount($lockedUser);

            if (User::query()->where('email', $newEmail)->whereKeyNot($lockedUser->id)->exists()) {
                throw ValidationException::withMessages([
                    'new_email' => ['Email baru sudah digunakan oleh akun lain.'],
                ]);
            }

            $lockedUser->forceFill([
                'email' => $newEmail,
                'email_verified_at' => now(),
            ])->save();

            return $lockedUser->fresh();
        });

        RuntimeCache::forget($this->oldEmailTokenKey((int) $user->id, $oldToken));
        $this->bumpProfileVersion((int) $user->id);

        return $this->ok($updatedUser, [
            'message' => 'Email berhasil diganti dan sudah diverifikasi.',
            'email_changed' => true,
        ]);
    }

    private function currentUser(Request $request): User
    {
        /** @var User|null $user */
        $user = $request->user();

        if (!$user) {
            abort(response()->json([
                'success' => false,
                'data' => null,
                'meta' => (object) [],
                'error' => ['message' => 'Unauthenticated', 'details' => null],
            ], 401));
        }

        return $user;
    }

    private function ensureManualAccount(User $user): void
    {
        $provider = strtolower(trim((string) ($user->provider ?? '')));

        if (in_array($provider, ['google', 'discord'], true)) {
            throw ValidationException::withMessages([
                'email' => ['Email akun Google/Discord tidak dapat diubah. Gunakan email dari provider login tersebut.'],
            ]);
        }
    }

    private function ensureChallengeBelongsToUser(AuthChallenge $challenge, User $user, string $purpose): void
    {
        if ((int) $challenge->user_id !== (int) $user->id || (string) $challenge->purpose !== $purpose) {
            throw ValidationException::withMessages([
                'challenge_id' => ['Challenge OTP tidak sesuai dengan proses ganti email. Silakan ulangi proses.'],
            ]);
        }
    }

    private function getValidOldEmailProof(User $user, string $token): array
    {
        $proof = RuntimeCache::get($this->oldEmailTokenKey((int) $user->id, $token));

        if (!is_array($proof) || (int) ($proof['user_id'] ?? 0) !== (int) $user->id) {
            throw ValidationException::withMessages([
                'old_email_token' => ['Verifikasi email lama sudah habis atau belum dilakukan. Silakan minta OTP email lama lagi.'],
            ]);
        }

        return $proof;
    }

    private function oldEmailTokenKey(int $userId, string $token): string
    {
        return 'email-change:old-verified:' . $userId . ':' . hash('sha256', $token);
    }

    private function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
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
