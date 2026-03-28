<?php

namespace App\Http\Controllers\Api\V1\Webhook;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessPaidOrderJob;
use App\Models\Order;
use App\Models\Payment;
use App\Models\WalletTopup;
use App\Services\LedgerService;
use App\Services\Payments\PaymentGatewayManager;
use App\Support\DispatchesInvoiceEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PaymentWebhookController extends Controller
{
    use DispatchesInvoiceEmail;

    public function handleMidtrans(
        Request $request,
        PaymentGatewayManager $gatewayManager,
        LedgerService $ledger
    ) {
        return $this->processWebhook('midtrans', $request, $gatewayManager, $ledger);
    }

    public function handleDuitku(
        Request $request,
        PaymentGatewayManager $gatewayManager,
        LedgerService $ledger
    ) {
        return $this->processWebhook('duitku', $request, $gatewayManager, $ledger);
    }

    public function handle(
        string $gateway_code,
        Request $request,
        PaymentGatewayManager $gatewayManager,
        LedgerService $ledger
    ) {
        return $this->processWebhook($gateway_code, $request, $gatewayManager, $ledger);
    }

    protected function processWebhook(
        string $gatewayKey,
        Request $request,
        PaymentGatewayManager $gatewayManager,
        LedgerService $ledger
    ) {
        try {
            $gateway = $gatewayManager->resolveWebhookGateway($gatewayKey);

            if (!$gateway) {
                return response()->json([
                    'success' => false,
                    'message' => 'Gateway tidak ditemukan',
                ], 404);
            }

            $event = $gatewayManager->driverFor($gateway)->parseWebhook($gateway, $request);

            $merchantOrderId = (string) ($event['merchant_order_id'] ?? '');
            $externalId = (string) ($event['external_id'] ?? $merchantOrderId);
            $status = (string) ($event['status'] ?? 'pending');
            $amount = (float) ($event['amount'] ?? 0);
            $payload = is_array($event['payload'] ?? null) ? $event['payload'] : $request->all();

            Log::info('PAYMENT WEBHOOK RECEIVED', [
                'gateway' => $gateway->code,
                'merchant_order_id' => $merchantOrderId,
                'external_id' => $externalId,
                'status' => $status,
                'amount' => $amount,
            ]);

            if ($merchantOrderId === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'merchant_order_id kosong',
                ], 422);
            }

            $topup = WalletTopup::query()
                ->where('order_id', $merchantOrderId)
                ->first();

            if ($topup) {
                $finalTopupId = null;
                $shouldDispatchTopupInvoice = false;
                $topupOrderId = null;

                DB::transaction(function () use (
                    $topup,
                    $gateway,
                    $status,
                    $externalId,
                    $payload,
                    $amount,
                    $event,
                    $ledger,
                    &$finalTopupId,
                    &$shouldDispatchTopupInvoice,
                    &$topupOrderId
                ) {
                    $locked = WalletTopup::query()
                        ->where('id', $topup->id)
                        ->lockForUpdate()
                        ->first();

                    if (!$locked) {
                        throw new \RuntimeException('Wallet topup not found during transaction');
                    }

                    $existingRaw = is_array($locked->raw_callback) ? $locked->raw_callback : [];

                    $locked->gateway_code = $gateway->code;
                    $locked->external_id = $externalId !== '' ? $externalId : $locked->external_id;
                    $locked->status = $status;
                    $locked->raw_callback = array_merge($existingRaw, [
                        'webhook' => $payload,
                    ]);

                    if ($status === 'paid' && !$locked->paid_at) {
                        $locked->paid_at = $event['paid_at'] ?? now();
                    }

                    $locked->save();

                    if ($status === 'paid' && !$locked->posted_to_ledger_at) {
                        $ledgerAmount = (int) round((float) $locked->amount);

                        $ledger->topup(
                            (int) $locked->user_id,
                            $ledgerAmount,
                            (string) $locked->order_id,
                            'Topup via ' . (string) ($gateway->name ?? $gateway->code)
                        );

                        $locked->posted_to_ledger_at = now();
                        $locked->paid_at = $locked->paid_at ?: now();
                        $locked->save();

                        Log::info('TOPUP LEDGER POSTED', [
                            'topup_id' => $locked->id,
                            'user_id' => $locked->user_id,
                            'order_id' => $locked->order_id,
                            'gateway' => $gateway->code,
                            'wallet_credit_amount' => $ledgerAmount,
                            'gateway_paid_amount' => $amount,
                            'gateway_fee_amount' => (float) ($locked->gateway_fee_amount ?? 0),
                        ]);
                    }

                    $finalTopupId = (int) $locked->id;
                    $topupOrderId = (string) $locked->order_id;

                    if ($status === 'paid' && empty($locked->invoice_emailed_at)) {
                        $shouldDispatchTopupInvoice = true;
                    }

                    Log::info('TOPUP WEBHOOK PROCESSED IN TX', [
                        'topup_id' => $locked->id,
                        'order_id' => $locked->order_id,
                        'status' => $status,
                        'should_dispatch_topup_invoice' => $shouldDispatchTopupInvoice,
                    ]);
                });

                if ($shouldDispatchTopupInvoice && $finalTopupId) {
                    $this->dispatchInvoiceForTopup(
                        $finalTopupId,
                        $gateway->code . '_topup_paid',
                        $topupOrderId
                    );
                } else {
                    Log::info('TOPUP INVOICE NOT DISPATCHED', [
                        'topup_id' => $finalTopupId,
                        'status' => $status,
                        'reason' => $status !== 'paid'
                            ? 'status_not_paid'
                            : 'already_emailed_or_invalid_topup',
                    ]);
                }

                return response()->json([
                    'success' => true,
                    'message' => 'TOPUP_PROCESSED',
                ], 200);
            }

            $order = Order::query()
                ->where('invoice_number', $merchantOrderId)
                ->first();

            if (!$order && $externalId !== '') {
                $payment = Payment::query()
                    ->where('external_id', $externalId)
                    ->first();

                if ($payment) {
                    $order = Order::query()->find($payment->order_id);
                }
            }

            if (!$order) {
                Log::warning('ORDER WEBHOOK IGNORED NOT FOUND', [
                    'gateway' => $gateway->code,
                    'merchant_order_id' => $merchantOrderId,
                    'external_id' => $externalId,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'IGNORED_NOT_FOUND',
                ], 200);
            }

            $finalOrderId = null;
            $finalInvoiceNumber = null;
            $shouldDispatchOrderInvoice = false;
            $shouldDispatchPaidOrderJob = false;
            $paidOrderJobSource = $gateway->code . '_webhook_paid';

            DB::transaction(function () use (
                $order,
                $gateway,
                $status,
                $externalId,
                $payload,
                $amount,
                &$finalOrderId,
                &$finalInvoiceNumber,
                &$shouldDispatchOrderInvoice,
                &$shouldDispatchPaidOrderJob,
                $paidOrderJobSource
            ) {
                $lockedOrder = Order::query()
                    ->where('id', $order->id)
                    ->lockForUpdate()
                    ->first();

                if (!$lockedOrder) {
                    throw new \RuntimeException('Order not found during transaction');
                }

                $currentStatus = (string) ($lockedOrder->status?->value ?? $lockedOrder->status);

                $payment = Payment::query()->firstOrNew([
                    'order_id' => (int) $lockedOrder->id,
                ]);

                $grossAmount = $amount > 0
                    ? $amount
                    : ((float) $lockedOrder->amount + (float) ($lockedOrder->gateway_fee_amount ?? 0));

                $payment->order_id = (int) $lockedOrder->id;
                $payment->gateway_code = $gateway->code;
                $payment->external_id = $externalId !== '' ? $externalId : (string) $lockedOrder->invoice_number;
                $payment->amount = $grossAmount;
                $payment->status = match ($status) {
                    'paid' => PaymentStatus::PAID->value,
                    'failed' => PaymentStatus::FAILED->value,
                    'expired' => PaymentStatus::EXPIRED->value,
                    'refunded' => PaymentStatus::REFUNDED->value,
                    default => PaymentStatus::PENDING->value,
                };
                $payment->raw_callback = $payload;
                $payment->save();

                $lockedOrder->payment_gateway_code = $gateway->code;

                if ($status === 'paid' && !in_array($currentStatus, [
                    OrderStatus::PAID->value,
                    OrderStatus::FULFILLED->value,
                ], true)) {
                    $lockedOrder->status = OrderStatus::PAID->value;
                }

                if ($status === 'refunded') {
                    $lockedOrder->status = OrderStatus::REFUNDED->value;
                }

                if ($lockedOrder->isDirty()) {
                    $lockedOrder->save();
                }

                if ($status === 'paid' && $currentStatus !== OrderStatus::FULFILLED->value) {
                    Log::info('ORDER MARKED / CONFIRMED PAID', [
                        'order_id' => $lockedOrder->id,
                        'invoice_number' => $lockedOrder->invoice_number,
                        'gateway' => $gateway->code,
                        'previous_status' => $currentStatus,
                    ]);
                }

                if ($status === 'refunded') {
                    Log::info('ORDER MARKED REFUNDED', [
                        'order_id' => $lockedOrder->id,
                        'invoice_number' => $lockedOrder->invoice_number,
                    ]);
                }

                $finalOrderId = (int) $lockedOrder->id;
                $finalInvoiceNumber = (string) $lockedOrder->invoice_number;

                $shouldDispatchPaidOrderJob = $status === 'paid'
                    && !in_array($currentStatus, [
                        OrderStatus::FULFILLED->value,
                        OrderStatus::REFUNDED->value,
                    ], true);

                $shouldDispatchOrderInvoice = $status === 'paid'
                    && empty($lockedOrder->invoice_emailed_at);

                Log::info('ORDER WEBHOOK PROCESSED IN TX', [
                    'order_id' => $lockedOrder->id,
                    'invoice_number' => $lockedOrder->invoice_number,
                    'status' => $status,
                    'previous_status' => $currentStatus,
                    'should_dispatch_paid_order_job' => $shouldDispatchPaidOrderJob,
                    'paid_order_job_source' => $paidOrderJobSource,
                    'should_dispatch_order_invoice' => $shouldDispatchOrderInvoice,
                    'invoice_emailed_at' => $lockedOrder->invoice_emailed_at,
                ]);
            });

            if ($shouldDispatchPaidOrderJob && $finalOrderId) {
                $dispatched = $this->dispatchPaidOrderJobOnce(
                    $finalOrderId,
                    $paidOrderJobSource,
                    $finalInvoiceNumber
                );

                if (!$dispatched) {
                    Log::info('PROCESS PAID ORDER JOB NOT DISPATCHED', [
                        'order_id' => $finalOrderId,
                        'invoice_number' => $finalInvoiceNumber,
                        'status' => $status,
                        'reason' => 'dispatch_lock_active',
                        'source' => $paidOrderJobSource,
                    ]);
                }
            } else {
                Log::info('PROCESS PAID ORDER JOB NOT DISPATCHED', [
                    'order_id' => $finalOrderId,
                    'invoice_number' => $finalInvoiceNumber,
                    'status' => $status,
                    'reason' => $status !== 'paid'
                        ? 'status_not_paid'
                        : 'already_fulfilled_or_refunded',
                ]);
            }

            if ($shouldDispatchOrderInvoice && $finalOrderId) {
                $this->dispatchInvoiceForOrder(
                    Order::query()->findOrFail($finalOrderId),
                    $gateway->code . '_paid'
                );
            } else {
                Log::info('ORDER INVOICE NOT DISPATCHED', [
                    'order_id' => $finalOrderId,
                    'invoice_number' => $finalInvoiceNumber,
                    'status' => $status,
                    'reason' => $status !== 'paid'
                        ? 'status_not_paid'
                        : 'already_emailed_or_invalid_order',
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'ORDER_PROCESSED',
            ], 200);
        } catch (ValidationException $e) {
            Log::error('PAYMENT WEBHOOK VALIDATION ERROR', [
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 400);
        } catch (\Throwable $e) {
            Log::error('PAYMENT WEBHOOK FATAL ERROR', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    protected function dispatchPaidOrderJobOnce(
        int $orderId,
        string $source,
        ?string $invoiceNumber = null
    ): bool {
        $lockKey = 'dispatch:process_paid_order:' . $orderId;
        $lockSeconds = 180;
        $lock = Cache::lock($lockKey, $lockSeconds);

        if (!$lock->get()) {
            Log::warning('PROCESS PAID ORDER JOB DUPLICATE DISPATCH BLOCKED', [
                'order_id' => $orderId,
                'invoice_number' => $invoiceNumber,
                'source' => $source,
                'queue' => 'fulfillment',
                'lock_key' => $lockKey,
                'lock_ttl_seconds' => $lockSeconds,
            ]);

            return false;
        }

        try {
            $job = ProcessPaidOrderJob::dispatch($orderId, $source)->delay(now()->addSecond());

            if (method_exists($job, 'afterCommit')) {
                $job->afterCommit();
            }

            Log::info('PROCESS PAID ORDER JOB DISPATCHED', [
                'order_id' => $orderId,
                'invoice_number' => $invoiceNumber,
                'source' => $source,
                'queue' => 'fulfillment',
                'lock_key' => $lockKey,
                'lock_ttl_seconds' => $lockSeconds,
            ]);

            return true;
        } catch (\Throwable $e) {
            try {
                $lock->release();
            } catch (\Throwable $ignored) {
            }

            Log::error('PROCESS PAID ORDER JOB DISPATCH FAILED', [
                'order_id' => $orderId,
                'invoice_number' => $invoiceNumber,
                'source' => $source,
                'queue' => 'fulfillment',
                'lock_key' => $lockKey,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    protected function dispatchInvoiceForOrder(Order $order, string $reason): void
    {
        foreach (['dispatchInvoiceEmailAfterCommit', 'dispatchInvoiceEmail', 'queueInvoiceEmailAfterCommit'] as $method) {
            if (!method_exists($this, $method)) {
                continue;
            }

            try {
                $this->{$method}((int) $order->id, $reason, (string) $order->invoice_number);

                Log::info('dispatchInvoiceForOrder success', [
                    'method' => $method,
                    'order_id' => $order->id,
                    'invoice_number' => $order->invoice_number,
                    'reason' => $reason,
                ]);

                return;
            } catch (\ArgumentCountError $e) {
                try {
                    $this->{$method}((int) $order->id);

                    Log::info('dispatchInvoiceForOrder success with fallback args', [
                        'method' => $method,
                        'order_id' => $order->id,
                        'invoice_number' => $order->invoice_number,
                        'reason' => $reason,
                    ]);

                    return;
                } catch (\Throwable $e2) {
                    Log::error('dispatchInvoiceForOrder fallback failed', [
                        'method' => $method,
                        'order_id' => $order->id,
                        'invoice_number' => $order->invoice_number,
                        'reason' => $reason,
                        'error' => $e2->getMessage(),
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error('dispatchInvoiceForOrder failed', [
                    'method' => $method,
                    'order_id' => $order->id,
                    'invoice_number' => $order->invoice_number,
                    'reason' => $reason,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::error('dispatchInvoiceForOrder: no dispatch method succeeded', [
            'order_id' => $order->id,
            'invoice_number' => $order->invoice_number,
            'reason' => $reason,
        ]);
    }
}