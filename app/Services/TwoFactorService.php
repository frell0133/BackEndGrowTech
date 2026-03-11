<?php

namespace App\Services;

use App\Models\AuthChallenge;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TwoFactorService
{
    private const OTP_TTL_SECONDS = 300; // 5 menit
    private const RESEND_COOLDOWN_SECONDS = 60; // 60 detik
    private const MAX_ATTEMPTS = 5;

    public function startChallenge(User $user, string $purpose, array $options = []): array
    {
        $email = strtolower(trim((string) ($options['email'] ?? $user->email ?? '')));
        $provider = (string) ($options['provider'] ?? 'manual');
        $remember = (bool) ($options['remember'] ?? false);
        $meta = (array) ($options['meta'] ?? []);

        if (!$this->canSendOtpTo($email)) {
            return [
                'ok' => false,
                'status' => 422,
                'message' => 'Email user tidak valid untuk menerima OTP.',
                'details' => ['email' => $email],
            ];
        }

        AuthChallenge::query()
            ->where('user_id', $user->id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $otp = $this->generateOtp();

        $challenge = AuthChallenge::create([
            'challenge_id' => Str::random(64),
            'user_id' => $user->id,
            'purpose' => $purpose,
            'channel' => 'email',
            'email' => $email,
            'otp_hash' => Hash::make($otp),
            'expires_at' => now()->addSeconds(self::OTP_TTL_SECONDS),
            'resend_available_at' => now()->addSeconds(self::RESEND_COOLDOWN_SECONDS),
            'attempt_count' => 0,
            'max_attempts' => self::MAX_ATTEMPTS,
            'resend_count' => 0,
            'remember' => $remember,
            'provider' => $provider,
            'meta' => $meta,
        ]);

        $mail = $this->sendOtpEmail($email, $purpose, $otp);

        if (!$mail['ok']) {
            $challenge->delete();

            return [
                'ok' => false,
                'status' => 500,
                'message' => 'Gagal mengirim OTP ke email.',
                'details' => $mail['body'] ?? null,
            ];
        }

        return [
            'ok' => true,
            'status' => 200,
            'challenge_id' => $challenge->challenge_id,
            'expires_in' => self::OTP_TTL_SECONDS,
            'email_hint' => $this->maskEmail($email),
        ];
    }

    public function resendChallenge(string $challengeId): array
    {
        $challenge = AuthChallenge::query()
            ->with('user')
            ->where('challenge_id', $challengeId)
            ->first();

        if (!$challenge) {
            return [
                'ok' => false,
                'status' => 404,
                'message' => 'Challenge tidak ditemukan.',
            ];
        }

        if ($challenge->consumed_at) {
            return [
                'ok' => false,
                'status' => 422,
                'message' => 'Challenge sudah dipakai. Silakan login ulang.',
            ];
        }

        if ($challenge->expires_at && now()->greaterThan($challenge->expires_at)) {
            $challenge->update(['consumed_at' => now()]);

            return [
                'ok' => false,
                'status' => 422,
                'message' => 'OTP sudah kadaluarsa. Silakan login ulang.',
            ];
        }

        if ($challenge->resend_available_at && now()->lessThan($challenge->resend_available_at)) {
            $seconds = now()->diffInSeconds($challenge->resend_available_at);

            return [
                'ok' => false,
                'status' => 429,
                'message' => "Tunggu {$seconds} detik sebelum kirim ulang OTP.",
            ];
        }

        if (!$this->canSendOtpTo((string) $challenge->email)) {
            return [
                'ok' => false,
                'status' => 422,
                'message' => 'Email challenge tidak valid untuk menerima OTP.',
            ];
        }

        $otp = $this->generateOtp();
        $mail = $this->sendOtpEmail((string) $challenge->email, (string) $challenge->purpose, $otp);

        if (!$mail['ok']) {
            return [
                'ok' => false,
                'status' => 500,
                'message' => 'Gagal mengirim ulang OTP.',
                'details' => $mail['body'] ?? null,
            ];
        }

        $challenge->update([
            'otp_hash' => Hash::make($otp),
            'expires_at' => now()->addSeconds(self::OTP_TTL_SECONDS),
            'resend_available_at' => now()->addSeconds(self::RESEND_COOLDOWN_SECONDS),
            'attempt_count' => 0,
            'resend_count' => (int) $challenge->resend_count + 1,
        ]);

        return [
            'ok' => true,
            'status' => 200,
            'challenge_id' => $challenge->challenge_id,
            'expires_in' => self::OTP_TTL_SECONDS,
            'email_hint' => $this->maskEmail((string) $challenge->email),
            'message' => 'OTP berhasil dikirim ulang.',
        ];
    }

    public function verifyChallenge(string $challengeId, string $code): array
    {
        $challenge = AuthChallenge::query()
            ->with('user')
            ->where('challenge_id', $challengeId)
            ->first();

        if (!$challenge) {
            return [
                'ok' => false,
                'status' => 404,
                'message' => 'Challenge tidak ditemukan.',
            ];
        }

        if ($challenge->consumed_at) {
            return [
                'ok' => false,
                'status' => 422,
                'message' => 'Challenge sudah dipakai. Silakan login ulang.',
            ];
        }

        if ($challenge->expires_at && now()->greaterThan($challenge->expires_at)) {
            $challenge->update(['consumed_at' => now()]);

            return [
                'ok' => false,
                'status' => 422,
                'message' => 'OTP sudah kadaluarsa. Silakan login ulang.',
            ];
        }

        if ((int) $challenge->attempt_count >= (int) $challenge->max_attempts) {
            $challenge->update(['consumed_at' => now()]);

            return [
                'ok' => false,
                'status' => 422,
                'message' => 'Batas percobaan OTP sudah habis. Silakan login ulang.',
            ];
        }

        $challenge->increment('attempt_count');
        $challenge->refresh();

        if (!Hash::check($code, (string) $challenge->otp_hash)) {
            $remaining = max(0, (int) $challenge->max_attempts - (int) $challenge->attempt_count);

            if ($remaining <= 0) {
                $challenge->update(['consumed_at' => now()]);

                return [
                    'ok' => false,
                    'status' => 422,
                    'message' => 'Kode OTP salah. Percobaan habis, silakan login ulang.',
                ];
            }

            return [
                'ok' => false,
                'status' => 422,
                'message' => "Kode OTP salah. Sisa percobaan: {$remaining}.",
            ];
        }

        $challenge->update(['consumed_at' => now()]);

        return [
            'ok' => true,
            'status' => 200,
            'challenge' => $challenge->fresh(['user']),
        ];
    }

    private function generateOtp(): string
    {
        return (string) random_int(100000, 999999);
    }

    private function canSendOtpTo(string $email): bool
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $email = strtolower(trim($email));

        if (str_ends_with($email, '.local')) {
            return false;
        }

        return true;
    }

    private function sendOtpEmail(string $toEmail, string $purpose, string $otp): array
    {
        $apiKey = (string) config('services.brevo.key');
        $senderEmail = (string) config('services.brevo.sender_email');
        $senderName = (string) config('services.brevo.sender_name', config('app.name', 'GrowTech'));

        if ($apiKey === '') {
            Log::error('2FA OTP failed: BREVO_API_KEY kosong');

            return [
                'ok' => false,
                'status' => 0,
                'body' => ['message' => 'BREVO_API_KEY is missing'],
            ];
        }

        if ($senderEmail === '') {
            Log::error('2FA OTP failed: BREVO_SENDER_EMAIL kosong');

            return [
                'ok' => false,
                'status' => 0,
                'body' => ['message' => 'BREVO_SENDER_EMAIL is missing'],
            ];
        }

        $html = view('emails.auth-otp', [
            'appName' => config('app.name', 'GrowTech'),
            'otp' => $otp,
            'purposeLabel' => $this->purposeLabel($purpose),
            'expiresInMinutes' => (int) ceil(self::OTP_TTL_SECONDS / 60),
        ])->render();

        $response = Http::timeout(20)
            ->connectTimeout(5)
            ->withHeaders([
                'api-key' => $apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
            ->post('https://api.brevo.com/v3/smtp/email', [
                'sender' => [
                    'email' => $senderEmail,
                    'name' => $senderName,
                ],
                'to' => [
                    ['email' => $toEmail],
                ],
                'subject' => $this->subjectForPurpose($purpose),
                'htmlContent' => $html,
            ]);

        if ($response->failed()) {
            Log::error('2FA OTP send failed', [
                'to' => $toEmail,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [
                'ok' => false,
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ];
        }

        Log::info('2FA OTP sent', [
            'to' => $toEmail,
            'status' => $response->status(),
            'purpose' => $purpose,
        ]);

        return [
            'ok' => true,
            'status' => $response->status(),
            'body' => $response->json(),
        ];
    }

    private function purposeLabel(string $purpose): string
    {
        return match ($purpose) {
            'register' => 'Verifikasi Registrasi',
            'login' => 'Verifikasi Login',
            'social' => 'Verifikasi Login Sosial',
            default => 'Verifikasi Akun',
        };
    }

    private function subjectForPurpose(string $purpose): string
    {
        $appName = (string) config('app.name', 'GrowTech');

        return match ($purpose) {
            'register' => "{$appName} - OTP Verifikasi Registrasi",
            'login' => "{$appName} - OTP Login",
            'social' => "{$appName} - OTP Login Sosial",
            default => "{$appName} - OTP Verifikasi",
        };
    }

    private function maskEmail(string $email): string
    {
        [$name, $domain] = explode('@', $email, 2);

        $visible = Str::length($name) <= 2
            ? Str::substr($name, 0, 1)
            : Str::substr($name, 0, 2);

        $masked = $visible . str_repeat('*', max(2, Str::length($name) - Str::length($visible)));

        return $masked . '@' . $domain;
    }
}