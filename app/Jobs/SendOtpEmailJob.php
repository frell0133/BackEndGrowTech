<?php

namespace App\Jobs;

use App\Models\AuthChallenge;
use App\Services\TwoFactorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class SendOtpEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(
        public int $challengeDbId,
        public string $toEmail,
        public string $purpose,
        public string $encryptedOtp,
    ) {
        $this->onQueue('mail');
    }

    public function handle(TwoFactorService $twoFactor): void
    {
        $challenge = AuthChallenge::query()->find($this->challengeDbId);

        if (!$challenge) {
            Log::warning('SendOtpEmailJob: challenge not found', [
                'challenge_db_id' => $this->challengeDbId,
                'purpose' => $this->purpose,
            ]);
            return;
        }

        if ($challenge->consumed_at) {
            Log::info('SendOtpEmailJob: challenge already consumed, skip', [
                'challenge_db_id' => $challenge->id,
                'purpose' => $this->purpose,
            ]);
            return;
        }

        if ($challenge->expires_at && now()->greaterThan($challenge->expires_at)) {
            Log::info('SendOtpEmailJob: challenge expired, skip', [
                'challenge_db_id' => $challenge->id,
                'purpose' => $this->purpose,
            ]);
            return;
        }

        $otp = Crypt::decryptString($this->encryptedOtp);

        if (!Hash::check($otp, (string) $challenge->otp_hash)) {
            Log::info('SendOtpEmailJob: stale OTP payload detected, skip', [
                'challenge_db_id' => $challenge->id,
                'purpose' => $this->purpose,
            ]);
            return;
        }

        $result = $twoFactor->deliverOtpEmail($this->toEmail, $this->purpose, $otp);

        if (!($result['ok'] ?? false)) {
            $details = $result['body'] ?? null;

            Log::error('SendOtpEmailJob: delivery failed', [
                'challenge_db_id' => $challenge->id,
                'to' => $this->toEmail,
                'purpose' => $this->purpose,
                'details' => is_string($details) ? $details : json_encode($details, JSON_UNESCAPED_UNICODE),
            ]);

            throw new \RuntimeException('Queued OTP email delivery failed');
        }
    }
}
