<?php

namespace App\Support;

use App\Jobs\SendInvoiceEmailJob;
use App\Jobs\SendWalletTopupInvoiceJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

trait DispatchesInvoiceEmail
{
    protected function dispatchInvoiceEmailAfterCommit(
        int $orderId,
        string $source = 'unknown',
        ?string $invoiceNumber = null
    ): void {
        DB::afterCommit(function () use ($orderId, $source, $invoiceNumber) {
            Log::info('INVOICE DISPATCH REQUESTED', [
                'type' => 'order',
                'source' => $source,
                'order_id' => $orderId,
                'invoice_number' => $invoiceNumber,
            ]);

            try {
                $job = SendInvoiceEmailJob::dispatch($orderId)->delay(now()->addSeconds(3));

                if (method_exists($job, 'afterCommit')) {
                    $job->afterCommit();
                }

                Log::info('INVOICE QUEUED', [
                    'type' => 'order',
                    'source' => $source,
                    'order_id' => $orderId,
                ]);
            } catch (\Throwable $e) {
                Log::error('INVOICE DISPATCH FAILED', [
                    'type' => 'order',
                    'source' => $source,
                    'order_id' => $orderId,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    protected function dispatchInvoiceEmail(int $orderId, string $source = 'unknown'): void
    {
        $this->dispatchInvoiceEmailAfterCommit($orderId, $source);
    }

    protected function queueInvoiceEmailAfterCommit(
        int $orderId,
        string $source = 'unknown',
        ?string $invoiceNumber = null
    ): void {
        $this->dispatchInvoiceEmailAfterCommit($orderId, $source, $invoiceNumber);
    }

    protected function dispatchWalletTopupInvoiceAfterCommit(
        int $topupId,
        string $source = 'unknown',
        ?string $orderId = null
    ): void {
        DB::afterCommit(function () use ($topupId, $source, $orderId) {
            Log::info('TOPUP INVOICE DISPATCH REQUESTED', [
                'type' => 'wallet_topup',
                'source' => $source,
                'topup_id' => $topupId,
                'order_id' => $orderId,
            ]);

            try {
                $job = SendWalletTopupInvoiceJob::dispatch($topupId)->delay(now()->addSeconds(3));

                if (method_exists($job, 'afterCommit')) {
                    $job->afterCommit();
                }

                Log::info('TOPUP INVOICE QUEUED', [
                    'type' => 'wallet_topup',
                    'source' => $source,
                    'topup_id' => $topupId,
                ]);
            } catch (\Throwable $e) {
                Log::error('TOPUP INVOICE DISPATCH FAILED', [
                    'type' => 'wallet_topup',
                    'source' => $source,
                    'topup_id' => $topupId,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }
}