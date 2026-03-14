<?php

namespace App\Support;

use App\Jobs\SendInvoiceEmailJob;
use App\Jobs\SendWalletTopupInvoiceJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

trait DispatchesInvoiceEmail
{
    protected function dispatchOrderInvoiceJob(
        int $orderId,
        string $source = 'unknown',
        ?string $invoiceNumber = null
    ): void {
        Log::info('INVOICE DISPATCH REQUESTED', [
            'type' => 'order',
            'source' => $source,
            'order_id' => $orderId,
            'invoice_number' => $invoiceNumber,
            'mode' => 'queue_after_commit',
        ]);

        try {
            $job = SendInvoiceEmailJob::dispatch($orderId)->delay(now()->addSeconds(2));

            if (method_exists($job, 'afterCommit')) {
                $job->afterCommit();
            }

            Log::info('INVOICE DISPATCHED', [
                'type' => 'order',
                'source' => $source,
                'order_id' => $orderId,
                'invoice_number' => $invoiceNumber,
                'mode' => 'queue_after_commit',
            ]);
        } catch (\Throwable $e) {
            Log::error('INVOICE DISPATCH FAILED', [
                'type' => 'order',
                'source' => $source,
                'order_id' => $orderId,
                'invoice_number' => $invoiceNumber,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function dispatchTopupInvoiceJob(
        int $topupId,
        string $source = 'unknown',
        ?string $orderId = null
    ): void {
        Log::info('TOPUP INVOICE DISPATCH REQUESTED', [
            'type' => 'wallet_topup',
            'source' => $source,
            'topup_id' => $topupId,
            'order_id' => $orderId,
            'mode' => 'queue_after_commit',
        ]);

        try {
            $job = SendWalletTopupInvoiceJob::dispatch($topupId)->delay(now()->addSeconds(2));

            if (method_exists($job, 'afterCommit')) {
                $job->afterCommit();
            }

            Log::info('TOPUP INVOICE DISPATCHED', [
                'type' => 'wallet_topup',
                'source' => $source,
                'topup_id' => $topupId,
                'order_id' => $orderId,
                'mode' => 'queue_after_commit',
            ]);
        } catch (\Throwable $e) {
            Log::error('TOPUP INVOICE DISPATCH FAILED', [
                'type' => 'wallet_topup',
                'source' => $source,
                'topup_id' => $topupId,
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function dispatchInvoiceEmailAfterCommit(
        int $orderId,
        string $source = 'unknown',
        ?string $invoiceNumber = null
    ): void {
        if (DB::transactionLevel() > 0) {
            DB::afterCommit(function () use ($orderId, $source, $invoiceNumber) {
                $this->dispatchOrderInvoiceJob($orderId, $source, $invoiceNumber);
            });
            return;
        }

        $this->dispatchOrderInvoiceJob($orderId, $source, $invoiceNumber);
    }

    protected function dispatchInvoiceEmail(
        int $orderId,
        string $source = 'unknown'
    ): void {
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
        if (DB::transactionLevel() > 0) {
            DB::afterCommit(function () use ($topupId, $source, $orderId) {
                $this->dispatchTopupInvoiceJob($topupId, $source, $orderId);
            });
            return;
        }

        $this->dispatchTopupInvoiceJob($topupId, $source, $orderId);
    }

    protected function dispatchInvoiceForTopup(
        int $topupId,
        string $reason = 'unknown',
        ?string $orderId = null
    ): void {
        foreach (['dispatchWalletTopupInvoiceAfterCommit', 'dispatchTopupInvoiceJob'] as $method) {
            if (!method_exists($this, $method)) {
                continue;
            }

            try {
                $this->{$method}($topupId, $reason, $orderId);

                Log::info('dispatchInvoiceForTopup success', [
                    'method' => $method,
                    'topup_id' => $topupId,
                    'order_id' => $orderId,
                    'reason' => $reason,
                ]);

                return;
            } catch (\Throwable $e) {
                Log::error('dispatchInvoiceForTopup failed', [
                    'method' => $method,
                    'topup_id' => $topupId,
                    'order_id' => $orderId,
                    'reason' => $reason,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::error('dispatchInvoiceForTopup: no dispatch method succeeded', [
            'topup_id' => $topupId,
            'order_id' => $orderId,
            'reason' => $reason,
        ]);
    }
}
