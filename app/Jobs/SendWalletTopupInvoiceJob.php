<?php

namespace App\Jobs;

use App\Models\WalletTopup;
use App\Services\BrevoMailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendWalletTopupInvoiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;
    public string $queue = 'default';

    public function __construct(public int $topupId)
    {
    }

    public function handle(BrevoMailService $brevo): void
    {
        $topup = WalletTopup::query()
            ->with(['user', 'gateway'])
            ->find($this->topupId);

        if (!$topup) {
            Log::warning('SendWalletTopupInvoiceJob: topup not found', [
                'topup_id' => $this->topupId,
            ]);
            return;
        }

        if (!empty($topup->invoice_emailed_at)) {
            Log::info('SendWalletTopupInvoiceJob: already sent', [
                'topup_id' => $topup->id,
            ]);
            return;
        }

        $to = $topup->user?->email;

        if (!$to) {
            try {
                $topup->forceFill([
                    'invoice_email_error' => 'Wallet topup invoice recipient is empty',
                ])->save();
            } catch (\Throwable $ignored) {
            }

            Log::warning('SendWalletTopupInvoiceJob: email empty', [
                'topup_id' => $topup->id,
            ]);

            throw new \RuntimeException('Wallet topup invoice recipient is empty');
        }

        $gatewayName = $topup->gateway?->name
            ?? $topup->gateway?->code
            ?? $topup->gateway_code
            ?? '-';

        $paymentStatus = $topup->status ?? '-';

        try {
            $html = view('emails.wallet-topup-invoice', [
                'topup' => $topup,
                'paymentMethod' => $gatewayName,
                'paymentStatus' => $paymentStatus,
            ])->render();
        } catch (\Throwable $e) {
            try {
                $topup->forceFill([
                    'invoice_email_error' => mb_substr('View render failed: ' . $e->getMessage(), 0, 2000),
                ])->save();
            } catch (\Throwable $ignored) {
            }

            Log::error('SendWalletTopupInvoiceJob: view render failed', [
                'topup_id' => $topup->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $subject = 'Invoice Topup Wallet ' . ($topup->order_id ?? ('#' . $topup->id));

        try {
            $res = $brevo->sendHtml($to, $subject, $html);

            if (!($res['ok'] ?? false)) {
                $body = $res['body'] ?? null;

                try {
                    $topup->forceFill([
                        'invoice_email_error' => is_string($body)
                            ? mb_substr($body, 0, 2000)
                            : mb_substr((string) json_encode($body, JSON_UNESCAPED_UNICODE), 0, 2000),
                    ])->save();
                } catch (\Throwable $ignored) {
                }

                Log::error('SendWalletTopupInvoiceJob: brevo failed', [
                    'topup_id' => $topup->id,
                    'response' => $res,
                ]);

                throw new \RuntimeException('Brevo send failed for wallet topup invoice');
            }

            $topup->forceFill([
                'invoice_emailed_at' => now(),
                'invoice_email_error' => null,
            ])->save();

            Log::info('SendWalletTopupInvoiceJob: success', [
                'topup_id' => $topup->id,
                'to' => $to,
            ]);
        } catch (\Throwable $e) {
            try {
                $topup->forceFill([
                    'invoice_email_error' => mb_substr($e->getMessage(), 0, 2000),
                ])->save();
            } catch (\Throwable $ignored) {
            }

            Log::error('SendWalletTopupInvoiceJob: exception', [
                'topup_id' => $topup->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}