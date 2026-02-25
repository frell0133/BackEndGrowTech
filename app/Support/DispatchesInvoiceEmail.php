<?php

namespace App\Support;

use App\Jobs\SendInvoiceEmailJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

trait DispatchesInvoiceEmail
{
    /**
     * Queue invoice email setelah transaction commit.
     * Jangan kirim sync di request supaya endpoint payment tidak timeout (500).
     */
    protected function dispatchInvoiceEmailAfterCommit(
        int $orderId,
        string $source = 'unknown',
        ?string $invoiceNumber = null
    ): void {
        DB::afterCommit(function () use ($orderId, $source, $invoiceNumber) {
            Log::info('INVOICE DISPATCH', [
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
                    'source' => $source,
                    'order_id' => $orderId,
                ]);
            } catch (\Throwable $e) {
                Log::error('INVOICE DISPATCH FAILED', [
                    'source' => $source,
                    'order_id' => $orderId,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }
} 