<?php

namespace App\Support;

use App\Jobs\SendInvoiceEmailJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

trait DispatchesInvoiceEmail
{
    /**
     * Dispatch invoice email setelah transaksi commit.
     *
     * Strategi:
     * - Tetap kirim ke queue (normal production flow)
     * - Tambahkan fallback sync agar tetap terkirim walau queue worker belum jalan
     *
     * Aman dari double send karena SendInvoiceEmailJob sudah cek invoice_emailed_at.
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
                // 1) dispatch async ke queue (flow normal)
                $job = SendInvoiceEmailJob::dispatch($orderId)->delay(now()->addSeconds(5));

                if (method_exists($job, 'afterCommit')) {
                    $job->afterCommit();
                }

                // 2) fallback sync (jika queue worker belum aktif)
                // Anti double-send ditangani di job (invoice_emailed_at)
                SendInvoiceEmailJob::dispatchSync($orderId);

                Log::info('INVOICE DISPATCH SYNC FALLBACK DONE', [
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