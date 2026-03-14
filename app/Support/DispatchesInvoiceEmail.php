<?php

namespace App\Support;

use App\Jobs\SendInvoiceEmailJob;
use App\Jobs\SendWalletTopupInvoiceJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

trait DispatchesInvoiceEmail
{
    protected function afterCommitOrNow(callable $callback): void
    {
        if (DB::transactionLevel() > 0) {
            DB::afterCommit($callback);
            return;
        }

        $callback();
    }

    protected function runOrderInvoiceNow(
        int $orderId,
        string $source = 'unknown',
        ?string $invoiceNumber = null
    ): void {
        Log::info('INVOICE EXECUTION REQUESTED', [
            'type' => 'order',
            'source' => $source,
            'order_id' => $orderId,
            'invoice_number' => $invoiceNumber,
            'mode' => 'sync_after_commit',
        ]);

        try {
            $job = new SendInvoiceEmailJob($orderId);
            app()->call([$job, 'handle']);

            Log::info('INVOICE EXECUTION FINISHED', [
                'type' => 'order',
                'source' => $source,
                'order_id' => $orderId,
                'invoice_number' => $invoiceNumber,
                'mode' => 'sync_after_commit',
            ]);
        } catch (\Throwable $e) {
            Log::error('INVOICE EXECUTION FAILED', [
                'type' => 'order',
                'source' => $source,
                'order_id' => $orderId,
                'invoice_number' => $invoiceNumber,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function runWalletTopupInvoiceNow(
        int $topupId,
        string $source = 'unknown',
        ?string $orderId = null
    ): void {
        Log::info('TOPUP INVOICE EXECUTION REQUESTED', [
            'type' => 'wallet_topup',
            'source' => $source,
            'topup_id' => $topupId,
            'order_id' => $orderId,
            'mode' => 'sync_after_commit',
        ]);

        try {
            $job = new SendWalletTopupInvoiceJob($topupId);
            app()->call([$job, 'handle']);

            Log::info('TOPUP INVOICE EXECUTION FINISHED', [
                'type' => 'wallet_topup',
                'source' => $source,
                'topup_id' => $topupId,
                'order_id' => $orderId,
                'mode' => 'sync_after_commit',
            ]);
        } catch (\Throwable $e) {
            Log::error('TOPUP INVOICE EXECUTION FAILED', [
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
        $this->afterCommitOrNow(function () use ($orderId, $source, $invoiceNumber) {
            $this->runOrderInvoiceNow($orderId, $source, $invoiceNumber);
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
        $this->afterCommitOrNow(function () use ($topupId, $source, $orderId) {
            $this->runWalletTopupInvoiceNow($topupId, $source, $orderId);
        });
    }
}
