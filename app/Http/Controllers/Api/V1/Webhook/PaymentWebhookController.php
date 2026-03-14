<?php

namespace App\Http\Controllers\Api\V1\Webhook;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\WalletTopup;
use App\Services\LedgerService;
use App\Services\OrderFulfillmentService;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\ReferralCommissionService;
use App\Support\DispatchesInvoiceEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PaymentWebhookController extends Controller
{
    use DispatchesInvoiceEmail;

    public function handleMidtrans(
        Request $request,
        PaymentGatewayManager $gatewayManager,
        LedgerService $ledger,
        OrderFulfillmentService $fulfillment
    ) {
        return $this->processWebhook('midtrans', $request, $gatewayManager, $ledger, $fulfillment);
    }

    public function handleDuitku(
        Request $request,
        PaymentGatewayManager $gatewayManager,
        LedgerService $ledger,
        OrderFulfillmentService $fulfillment
    ) {
        return $this->processWebhook('duitku', $request, $gatewayManager, $ledger, $fulfillment);
    }

    public function handle(
        string $gateway_code,
        Request $request,
        PaymentGatewayManager $gatewayManager,
        LedgerService $ledger,
        OrderFulfillmentService $fulfillment
    ) {
        return $this->processWebhook($gateway_code, $request, $gatewayManager, $ledger, $fulfillment);
    }

    protected function processWebhook(
        string $gatewayKey,
        Request $request,
        PaymentGatewayManager $gatewayManager,
        LedgerService $ledger,
        OrderFulfillmentService $fulfillment
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

                    $locked->gateway_code = $gateway->code;
                    $locked->external_id = $externalId !== '' ? $externalId : $locked->external_id;
                    $locked->status = $status;
                    $locked->raw_callback = $payload;

                    if ($status === 'paid' && !$locked->paid_at) {
                        $locked->paid_at = $event['paid_at'] ?? now();
                    }

                    $locked->save();

                    if ($status === 'paid' && !$locked->posted_to_ledger_at) {
                        $ledger->topup(
                            (int) $locked->user_id,
                            (int) round($amount > 0 ? $amount : (float) $locked->amount),
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
                    $this->dispatchWalletTopupInvoiceAfterCommit(
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

            DB::transaction(function () use (
                $order,
                $gateway,
                $status,
                $externalId,
                $payload,
                $amount,
                $ledger,
                $fulfillment,
                &$finalOrderId,
                &$finalInvoiceNumber,
                &$shouldDispatchOrderInvoice
            ) {
                $lockedOrder = Order::query()
                    ->where('id', $order->id)
                    ->with(['items.product', 'product', 'payment'])
                    ->lockForUpdate()
                    ->first();

                if (!$lockedOrder) {
                    throw new \RuntimeException('Order not found during transaction');
                }

                $alreadyPaid = in_array((string) ($lockedOrder->status?->value ?? $lockedOrder->status), [
                    OrderStatus::PAID->value,
                    OrderStatus::FULFILLED->value,
                ], true);

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

                if ($status === 'paid' && !$alreadyPaid) {
                    $lockedOrder->status = OrderStatus::PAID->value;
                    $lockedOrder->save();

                    Log::info('ORDER MARKED PAID', [
                        'order_id' => $lockedOrder->id,
                        'invoice_number' => $lockedOrder->invoice_number,
                        'gateway' => $gateway->code,
                    ]);

                    app(ReferralCommissionService::class)->handleOrderPaid(
                        $lockedOrder->fresh(),
                        $ledger,
                        [
                            'gateway' => $gateway->code,
                            'webhook' => true,
                            'external_id' => $externalId,
                        ]
                    );

                    $result = $this->runFulfillment(
                        $fulfillment,
                        $lockedOrder->fresh(['items.product', 'product', 'payment'])
                    );

                    $hasDeliveries = method_exists($lockedOrder, 'deliveries')
                        ? $lockedOrder->deliveries()->exists()
                        : false;

                    if (($result['success'] ?? false) || ($result['ok'] ?? false) || $hasDeliveries) {
                        $lockedOrder->status = OrderStatus::FULFILLED->value;

                        Log::info('ORDER MARKED FULFILLED', [
                            'order_id' => $lockedOrder->id,
                            'invoice_number' => $lockedOrder->invoice_number,
                            'has_deliveries' => $hasDeliveries,
                            'result' => $result,
                        ]);
                    }

                    $lockedOrder->save();
                }

                if ($status === 'refunded') {
                    $lockedOrder->status = OrderStatus::REFUNDED->value;
                    $lockedOrder->save();

                    Log::info('ORDER MARKED REFUNDED', [
                        'order_id' => $lockedOrder->id,
                        'invoice_number' => $lockedOrder->invoice_number,
                    ]);
                }

                $finalOrderId = (int) $lockedOrder->id;
                $finalInvoiceNumber = (string) $lockedOrder->invoice_number;

                if ($status === 'paid' && empty($lockedOrder->invoice_emailed_at)) {
                    $shouldDispatchOrderInvoice = true;
                }

                Log::info('ORDER WEBHOOK PROCESSED IN TX', [
                    'order_id' => $lockedOrder->id,
                    'invoice_number' => $lockedOrder->invoice_number,
                    'status' => $status,
                    'should_dispatch_order_invoice' => $shouldDispatchOrderInvoice,
                    'invoice_emailed_at' => $lockedOrder->invoice_emailed_at,
                ]);
            });

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

    protected function runFulfillment(OrderFulfillmentService $fulfillment, Order $order): array
    {
        foreach (['fulfillPaidOrder', 'handlePaidOrder', 'processPaidOrder', 'fulfill'] as $method) {
            if (!method_exists($fulfillment, $method)) {
                continue;
            }

            try {
                $result = $fulfillment->{$method}($order);

                Log::info('ORDER FULFILLMENT METHOD EXECUTED', [
                    'method' => $method,
                    'order_id' => $order->id,
                    'invoice_number' => $order->invoice_number,
                    'result_type' => gettype($result),
                ]);

                if (is_array($result)) {
                    return $result;
                }

                return ['success' => $result !== false];
            } catch (\ArgumentCountError $e) {
                Log::warning('ORDER FULFILLMENT ARGUMENT COUNT ERROR', [
                    'method' => $method,
                    'order_id' => $order->id,
                    'invoice_number' => $order->invoice_number,
                    'error' => $e->getMessage(),
                ]);

                continue;
            } catch (\Throwable $e) {
                Log::error('ORDER FULFILLMENT EXECUTION FAILED', [
                    'method' => $method,
                    'order_id' => $order->id,
                    'invoice_number' => $order->invoice_number,
                    'error' => $e->getMessage(),
                ]);

                return ['success' => false, 'error' => $e->getMessage()];
            }
        }

        Log::warning('ORDER FULFILLMENT NO METHOD MATCHED', [
            'order_id' => $order->id,
            'invoice_number' => $order->invoice_number,
        ]);

        return ['success' => false];
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