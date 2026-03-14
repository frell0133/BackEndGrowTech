<?php

namespace App\Jobs;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\LedgerService;
use App\Services\OrderFulfillmentService;
use App\Services\ReferralCommissionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessPaidOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 180;

    public function __construct(
        public int $orderId,
        public string $source = 'unknown'
    ) {
        $this->onQueue('default');
    }

    public function handle(
        OrderFulfillmentService $fulfillment,
        LedgerService $ledger,
        ReferralCommissionService $referral
    ): void {
        $order = Order::query()
            ->with(['items.product', 'product', 'payment', 'vouchers'])
            ->find($this->orderId);

        if (!$order) {
            Log::warning('PROCESS PAID ORDER: order not found', [
                'order_id' => $this->orderId,
                'source' => $this->source,
            ]);
            return;
        }

        $status = (string) ($order->status?->value ?? $order->status);

        if (!in_array($status, [
            OrderStatus::PAID->value,
            OrderStatus::FULFILLED->value,
        ], true)) {
            Log::warning('PROCESS PAID ORDER: invalid status', [
                'order_id' => $order->id,
                'status' => $status,
                'source' => $this->source,
            ]);
            return;
        }

        Log::info('PROCESS PAID ORDER START', [
            'order_id' => $order->id,
            'status' => $status,
            'source' => $this->source,
        ]);

        try {
            $referral->handleOrderPaid(
                $order->fresh(),
                $ledger,
                [
                    'source' => $this->source,
                    'queued' => true,
                ]
            );
        } catch (\Throwable $e) {
            Log::error('PROCESS PAID ORDER: referral failed', [
                'order_id' => $order->id,
                'source' => $this->source,
                'error' => $e->getMessage(),
            ]);
        }

        $fulfillmentResult = [
            'ok' => false,
            'message' => 'Fulfillment not executed',
        ];

        try {
            $freshOrder = $order->fresh(['items.product', 'product', 'payment', 'vouchers']);
            $fulfillmentResult = $fulfillment->fulfillPaidOrder($freshOrder);
        } catch (\Throwable $e) {
            Log::error('PROCESS PAID ORDER: fulfillment failed', [
                'order_id' => $order->id,
                'source' => $this->source,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $order = $order->fresh();

        $hasDeliveries = method_exists($order, 'deliveries')
            ? $order->deliveries()->exists()
            : false;

        if (($fulfillmentResult['ok'] ?? false) || ($fulfillmentResult['success'] ?? false) || $hasDeliveries) {
            if ((string) ($order->status?->value ?? $order->status) !== OrderStatus::FULFILLED->value) {
                $order->status = OrderStatus::FULFILLED->value;
                $order->save();
            }
        }

        try {
            $job = SendInvoiceEmailJob::dispatch((int) $order->id)->delay(now()->addSeconds(2));

            if (method_exists($job, 'afterCommit')) {
                $job->afterCommit();
            }
        } catch (\Throwable $e) {
            Log::error('PROCESS PAID ORDER: invoice dispatch failed', [
                'order_id' => $order->id,
                'source' => $this->source,
                'error' => $e->getMessage(),
            ]);
        }

        Log::info('PROCESS PAID ORDER DONE', [
            'order_id' => $order->id,
            'source' => $this->source,
            'fulfillment' => $fulfillmentResult,
            'has_deliveries' => $hasDeliveries,
            'final_status' => (string) ($order->fresh()->status?->value ?? $order->fresh()->status),
        ]);
    }
}
