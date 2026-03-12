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
                    }

                    $finalTopupId = (int) $locked->id;
                    $topupOrderId = (string) $locked->order_id;

                    if (
                        $status === 'paid' &&
                        empty($locked->invoice_emailed_at)
                    ) {
                        $shouldDispatchTopupInvoice = true;
                    }
                });

                if ($shouldDispatchTopupInvoice && $finalTopupId) {
                    $this->dispatchWalletTopupInvoiceAfterCommit(
                        $finalTopupId,
                        $gateway->code . '_topup_paid',
                        $topupOrderId
                    );
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
                    }

                    $lockedOrder->save();
                }

                if ($status === 'refunded') {
                    $lockedOrder->status = OrderStatus::REFUNDED->value;
                    $lockedOrder->save();
                }

                $finalOrderId = (int) $lockedOrder->id;
                $finalInvoiceNumber = (string) $lockedOrder->invoice_number;

                if (
                    $status === 'paid' &&
                    empty($lockedOrder->invoice_emailed_at)
                ) {
                    $shouldDispatchOrderInvoice = true;
                }
            });

            if ($shouldDispatchOrderInvoice && $finalOrderId) {
                $this->dispatchInvoiceForOrder(
                    Order::query()->findOrFail($finalOrderId),
                    $gateway->code . '_paid'
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'ORDER_PROCESSED',
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 400);
        } catch (\Throwable $e) {
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

                if (is_array($result)) {
                    return $result;
                }

                return ['success' => $result !== false];
            } catch (\ArgumentCountError $e) {
                continue;
            }
        }

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
                return;
            } catch (\ArgumentCountError $e) {
                try {
                    $this->{$method}((int) $order->id);
                    return;
                } catch (\Throwable $e2) {
                    return;
                }
            } catch (\Throwable $e) {
                return;
            }
        }
    }
}