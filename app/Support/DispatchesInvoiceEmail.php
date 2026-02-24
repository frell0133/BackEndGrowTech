<?php

namespace App\Support;

use App\Jobs\SendInvoiceEmailJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

trait DispatchesInvoiceEmail
{
    protected function dispatchInvoiceEmailAfterCommit(int $orderId, string $source = 'unknown'): void
    {
        DB::afterCommit(function () use ($orderId, $source) {
            Log::info('INVOICE DISPATCH', [
                'source' => $source,
                'order_id' => $orderId,
            ]);

            $job = SendInvoiceEmailJob::dispatch($orderId)->delay(now()->addSeconds(5));

            if (method_exists($job, 'afterCommit')) {
                $job->afterCommit();
            }
        });
    }
}