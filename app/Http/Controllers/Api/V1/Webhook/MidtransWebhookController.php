<?php

namespace App\Http\Controllers\Api\V1\Webhook;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\WalletTopup;
use App\Models\ReferralSetting;
use App\Models\ReferralTransaction;
use App\Services\LedgerService;
use App\Services\OrderFulfillmentService;
use App\Services\ReferralCommissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Support\DispatchesInvoiceEmail;

class MidtransWebhookController extends Controller
{
    use DispatchesInvoiceEmail;

    public function handle(Request $request, LedgerService $ledger, OrderFulfillmentService $fulfillment)
    {
        $payload = $request->all();

        Log::info('MIDTRANS WEBHOOK HIT RAW', [
            'ip' => $request->ip(),
            'url' => $request->fullUrl(),
            'payload' => $payload,
        ]);

        // ===== 1) ambil field penting =====
        $orderId            = (string) ($payload['order_id'] ?? '');
        $statusCode         = (string) ($payload['status_code'] ?? '');
        $grossAmount        = (string) ($payload['gross_amount'] ?? '');
        $signatureKey       = (string) ($payload['signature_key'] ?? '');
        $transactionStatus  = (string) ($payload['transaction_status'] ?? '');
        $fraudStatus        = (string) ($payload['fraud_status'] ?? '');
        $paymentType        = (string) ($payload['payment_type'] ?? '');
        $transactionId      = (string) ($payload['transaction_id'] ?? '');
        $settlementTime     = (string) ($payload['settlement_time'] ?? '');

        if ($orderId === '' || $statusCode === '' || $grossAmount === '' || $signatureKey === '') {
            Log::warning('MIDTRANS WEBHOOK INVALID PAYLOAD', ['payload' => $payload]);
            return response()->json([
                'success' => true,
                'ignored' => true,
                'message' => 'Invalid payload (ignored)',
            ], 200);
        }

        // ===== 2) server key =====
        $serverKey = (string) config('services.midtrans.server_key', env('MIDTRANS_SERVER_KEY', ''));
        if ($serverKey === '') {
            Log::error('MIDTRANS WEBHOOK SERVER KEY EMPTY', ['order_id' => $orderId]);
            return response()->json([
                'success' => true,
                'ignored' => true,
                'message' => 'Server key not configured (ignored)',
            ], 200);
        }

        // ===== 3) verify signature =====
        $expected = hash('sha512', $orderId . $statusCode . $grossAmount . $serverKey);
        if (!hash_equals($expected, $signatureKey)) {
            Log::warning('MIDTRANS WEBHOOK INVALID SIGNATURE', [
                'order_id' => $orderId,
                'expected' => $expected,
                'got' => $signatureKey,
            ]);

            return response()->json([
                'success' => true,
                'ignored' => true,
                'message' => 'Invalid signature (ignored)',
            ], 200);
        }

        // ===== 4) paid? =====
        $isPaid =
            ($transactionStatus === 'settlement') ||
            ($transactionStatus === 'capture' && $fraudStatus === 'accept');

        $mapped = match ($transactionStatus) {
            'settlement'      => 'paid',
            'capture'         => $isPaid ? 'paid' : 'pending',
            'pending'         => 'pending',
            'deny'            => 'failed',
            'cancel'          => 'failed',
            'expire'          => 'expired',
            'refund'          => 'refunded',
            'partial_refund'  => 'refunded',
            default           => 'pending',
        };

        /**
         * ==========================================================
         * A) TOPUP
         * ==========================================================
         */
        $topup = WalletTopup::where('order_id', $orderId)->first();
        if ($topup) {
            try {
                $finalTopupId = null;
                $shouldDispatchTopupInvoice = false;
                $topupOrderId = null;

                DB::transaction(function () use (
                    $topup,
                    $payload,
                    $mapped,
                    $isPaid,
                    $orderId,
                    $ledger,
                    &$finalTopupId,
                    &$shouldDispatchTopupInvoice,
                    &$topupOrderId
                ) {
                    $lockedTopup = WalletTopup::where('id', $topup->id)->lockForUpdate()->first();

                    if (!$lockedTopup) {
                        throw new \RuntimeException('Wallet topup not found during transaction');
                    }

                    $alreadyPosted = in_array((string) $lockedTopup->status, ['paid', 'success', 'completed'], true)
                        || !empty($lockedTopup->posted_to_ledger_at);

                   $existingRaw = is_array($lockedTopup->raw_callback) ? $lockedTopup->raw_callback : [];

                    $lockedTopup->raw_callback = array_merge($existingRaw, [
                        'webhook' => $payload,
                    ]);
                    $lockedTopup->external_id = $payload['transaction_id'] ?? $lockedTopup->external_id;

                    if (!$alreadyPosted) {
                        $lockedTopup->status = $mapped;
                        $lockedTopup->save();

                        if ($isPaid && $mapped === 'paid') {
                            $userId = (int) $lockedTopup->user_id;
                            $amount = (int) $lockedTopup->amount;

                            $ledger->topup(
                                $userId,
                                $amount,
                                (string) $lockedTopup->order_id,
                                'Topup via Midtrans'
                            );

                            $lockedTopup->status = 'paid';
                            $lockedTopup->posted_to_ledger_at = now();
                            $lockedTopup->save();

                            Log::info('MIDTRANS TOPUP PAID -> WALLET CREDITED', [
                                'order_id' => $orderId,
                                'user_id' => $userId,
                                'amount' => $amount,
                            ]);
                        }
                    } else {
                        if (empty($lockedTopup->status)) {
                            $lockedTopup->status = 'paid';
                        }

                        $lockedTopup->save();

                        Log::info('MIDTRANS DUPLICATE TOPUP (ALREADY POSTED)', [
                            'order_id' => $orderId,
                            'status' => $lockedTopup->status,
                            'posted_to_ledger_at' => $lockedTopup->posted_to_ledger_at,
                        ]);
                    }

                    $finalTopupId = (int) $lockedTopup->id;
                    $topupOrderId = (string) $lockedTopup->order_id;

                    $isPaidLike = in_array((string) $lockedTopup->status, ['paid', 'success', 'completed'], true)
                        || !empty($lockedTopup->posted_to_ledger_at);

                    if ($isPaidLike && empty($lockedTopup->invoice_emailed_at)) {
                        $shouldDispatchTopupInvoice = true;
                    }

                    Log::info('MIDTRANS TOPUP FINAL STATE', [
                        'topup_id' => $lockedTopup->id,
                        'order_id' => $lockedTopup->order_id,
                        'status' => $lockedTopup->status,
                        'posted_to_ledger_at' => $lockedTopup->posted_to_ledger_at,
                        'should_dispatch_topup_invoice' => $shouldDispatchTopupInvoice,
                    ]);
                });

                if ($shouldDispatchTopupInvoice && $finalTopupId) {
                    $this->dispatchInvoiceForTopup(
                        (int) $finalTopupId,
                        'midtrans_topup_paid',
                        (string) $topupOrderId
                    );

                    Log::info('MIDTRANS TOPUP INVOICE DISPATCHED', [
                        'topup_id' => $finalTopupId,
                        'order_id' => $topupOrderId,
                    ]);
                } else {
                    Log::info('MIDTRANS TOPUP INVOICE NOT DISPATCHED', [
                        'topup_id' => $finalTopupId,
                        'order_id' => $topupOrderId,
                        'reason' => 'already_emailed_or_not_paid',
                    ]);
                }

                return response()->json(['success' => true, 'message' => 'OK (topup)'], 200);
            } catch (\Throwable $e) {
                Log::error('MIDTRANS TOPUP WEBHOOK ERROR', [
                    'order_id' => $orderId,
                    'err' => $e->getMessage(),
                ]);

                return response()->json([
                    'success' => true,
                    'ignored' => true,
                    'message' => 'Topup internal error (ignored)',
                ], 200);
            }
        }

        /**
         * ==========================================================
         * B) ORDER PRODUCT + REFERRAL + FULFILL
         * ==========================================================
         */
        $order = Order::query()
            ->where('invoice_number', $orderId)
            ->orWhereHas('payment', function ($q) use ($orderId) {
                $q->where('external_id', $orderId);
            })
            ->with('payment')
            ->first();

        if (!$order) {
            Log::warning('MIDTRANS NO MATCH TOPUP/ORDER (IGNORED)', [
                'order_id' => $orderId,
                'status_code' => $statusCode,
                'gross_amount' => $grossAmount,
                'transaction_status' => $transactionStatus,
                'payment_type' => $paymentType,
                'note' => 'No match on Order.invoice_number OR Payment.external_id',
            ]);

            return response()->json([
                'success' => true,
                'ignored' => true,
                'message' => 'No matching topup/order (ignored)',
            ], 200);
        }

        try {
            DB::transaction(function () use (
                $order,
                $payload,
                $mapped,
                $isPaid,
                $orderId,
                $ledger,
                $fulfillment,
                $transactionStatus,
                $paymentType,
                $transactionId,
                $settlementTime
            ) {
                $lockedOrder = Order::query()->where('id', $order->id)->lockForUpdate()->first();

                $current = $lockedOrder->status?->value ?? (string) $lockedOrder->status;

                if (in_array($current, [
                    OrderStatus::PAID->value,
                    OrderStatus::FULFILLED->value,
                    OrderStatus::REFUNDED->value,
                ], true)) {
                    Log::info('MIDTRANS DUPLICATE ORDER (ALREADY FINAL)', [
                        'midtrans_order_id' => $orderId,
                        'status' => $current,
                    ]);
                    return;
                }

                $newOrderStatus = match ($mapped) {
                    'paid'     => OrderStatus::PAID->value,
                    'pending'  => OrderStatus::PENDING->value,
                    'failed'   => OrderStatus::FAILED->value,
                    'expired'  => OrderStatus::EXPIRED->value,
                    'refunded' => OrderStatus::REFUNDED->value,
                    default    => OrderStatus::PENDING->value,
                };

                $lockedOrder->payment_gateway_code = 'midtrans';
                $lockedOrder->status = $newOrderStatus;

                if (property_exists($lockedOrder, 'midtrans_payload')) {
                    $lockedOrder->midtrans_payload = $payload;
                }

                $lockedOrder->save();

                $payment = $lockedOrder->payment;

                $paymentStatus = match ($mapped) {
                    'paid'     => PaymentStatus::PAID->value,
                    'pending'  => PaymentStatus::PENDING->value,
                    'failed'   => PaymentStatus::FAILED->value,
                    'expired'  => PaymentStatus::EXPIRED->value,
                    'refunded' => PaymentStatus::REFUNDED->value,
                    default    => PaymentStatus::PENDING->value,
                };

                if (!$payment) {
                    $payment = Payment::create([
                        'order_id' => (int) $lockedOrder->id,
                        'gateway_code' => 'midtrans',
                        'external_id' => $orderId,
                        'amount' => (float) ($lockedOrder->amount ?? 0),
                        'status' => $paymentStatus,
                        'raw_callback' => $payload,
                    ]);
                } else {
                    $payment->update([
                        'gateway_code' => 'midtrans',
                        'external_id' => $payment->external_id ?: $orderId,
                        'status' => $paymentStatus,
                        'raw_callback' => $payload,
                    ]);
                }

                if (!$isPaid || $mapped !== 'paid') {
                    if (in_array($mapped, ['failed', 'expired', 'refunded'], true)) {
                        app(ReferralCommissionService::class)->invalidateOrderReferral((int) $lockedOrder->id, [
                            'source' => 'midtrans_webhook',
                            'status' => $mapped,
                        ]);
                    }

                    return;
                }

                app(ReferralCommissionService::class)->handleOrderPaid(
                    $lockedOrder->fresh(),
                    $ledger,
                    [
                        'source' => 'midtrans_webhook',
                        'payment_type' => $paymentType,
                    ]
                );
            });

            return response()->json(['success' => true, 'message' => 'OK (order)'], 200);
        } catch (\Throwable $e) {
            Log::error('MIDTRANS ORDER WEBHOOK ERROR', [
                'order_id' => $orderId,
                'err' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => true,
                'ignored' => true,
                'message' => 'Order internal error (ignored)',
            ], 200);
        }
    }
}