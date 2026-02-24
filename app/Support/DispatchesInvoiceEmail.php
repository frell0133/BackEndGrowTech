<?php

namespace App\Support;

use App\Jobs\SendInvoiceEmailJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

trait DispatchesInvoiceEmail
{
    /**
     * Dispatch invoice email setelah transaksi commit (QUEUE ONLY).
     *
     * Kenapa queue-only:
     * - Menghindari request payment timeout (500) saat API email lambat
     * - Menghindari "sudah sukses tapi client lihat error"
     * - Lebih aman untuk wallet dan midtrans webhook
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
                // Dispatch queue gagal (jarang, tapi tetap dicatat)
                Log::error('INVOICE DISPATCH FAILED', [
                    'source' => $source,
                    'order_id' => $orderId,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }
}