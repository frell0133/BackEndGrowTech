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
            return response()->json(['success' => true, 'ignored' => true, 'message' => 'Invalid payload (ignored)'], 200);
        }

        // ===== 2) server key =====
        $serverKey = (string) config('services.midtrans.server_key', env('MIDTRANS_SERVER_KEY', ''));
        if ($serverKey === '') {
            Log::error('MIDTRANS WEBHOOK SERVER KEY EMPTY', ['order_id' => $orderId]);
            return response()->json(['success' => true, 'ignored' => true, 'message' => 'Server key not configured (ignored)'], 200);
        }

        // ===== 3) verify signature =====
        $expected = hash('sha512', $orderId . $statusCode . $grossAmount . $serverKey);
        if (!hash_equals($expected, $signatureKey)) {
            Log::warning('MIDTRANS WEBHOOK INVALID SIGNATURE', [
                'order_id' => $orderId,
                'expected' => $expected,
                'got' => $signatureKey,
            ]);
            return response()->json(['success' => true, 'ignored' => true, 'message' => 'Invalid signature (ignored)'], 200);
        }

        // ===== 4) paid? =====
        $isPaid =
            ($transactionStatus === 'settlement') ||
            ($transactionStatus === 'capture' && $fraudStatus === 'accept');

        // map ke status internal (string)
        $mapped = match ($transactionStatus) {
            'settlement' => 'paid',
            'capture'    => $isPaid ? 'paid' : 'pending',
            'pending'    => 'pending',
            'deny'       => 'failed',
            'cancel'     => 'failed',
            'expire'     => 'expired',
            'refund'     => 'refunded',
            'partial_refund' => 'refunded',
            default      => 'pending',
        };

        /**
         * ==========================================================
         * A) TOPUP
         * ==========================================================
         */
        $topup = WalletTopup::where('order_id', $orderId)->first();
        if ($topup) {
            try {
                DB::transaction(function () use ($topup, $payload, $mapped, $isPaid, $orderId, $ledger) {

                    $lockedTopup = WalletTopup::where('id', $topup->id)->lockForUpdate()->first();

                    // idempotent: kalau sudah paid jangan dobel posting
                    if (in_array($lockedTopup->status, ['paid', 'success', 'completed'], true) || $lockedTopup->posted_to_ledger_at) {
                        Log::info('MIDTRANS DUPLICATE TOPUP (ALREADY POSTED)', [
                            'order_id' => $orderId,
                            'status' => $lockedTopup->status,
                            'posted_to_ledger_at' => $lockedTopup->posted_to_ledger_at,
                        ]);
                        return;
                    }

                    $lockedTopup->raw_callback = $payload;
                    $lockedTopup->external_id = $payload['transaction_id'] ?? null;
                    $lockedTopup->status = $mapped;
                    $lockedTopup->save();

                    if (!$isPaid || $mapped !== 'paid') {
                        return;
                    }

                    $userId = (int) $lockedTopup->user_id;
                    $amount = (int) $lockedTopup->amount;

                    // posting ledger
                    $ledger->topup(
                        $userId,
                        $amount,
                        $lockedTopup->order_id,  // idempotency key
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
                });

                return response()->json(['success' => true, 'message' => 'OK (topup)'], 200);
            } catch (\Throwable $e) {
                Log::error('MIDTRANS TOPUP WEBHOOK ERROR', [
                    'order_id' => $orderId,
                    'err' => $e->getMessage(),
                ]);
                return response()->json(['success' => true, 'ignored' => true, 'message' => 'Topup internal error (ignored)'], 200);
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

                $current = $lockedOrder->status?->value ?? (string)$lockedOrder->status;

                // idempotent: kalau sudah final, stop
                if (in_array($current, [OrderStatus::PAID->value, OrderStatus::FULFILLED->value, OrderStatus::REFUNDED->value], true)) {
                    Log::info('MIDTRANS DUPLICATE ORDER (ALREADY FINAL)', [
                        'midtrans_order_id' => $orderId,
                        'status' => $current,
                    ]);
                    return;
                }

                // map ke enum order status
                $newOrderStatus = match ($mapped) {
                    'paid' => OrderStatus::PAID->value,
                    'pending' => OrderStatus::PENDING->value,
                    'failed' => OrderStatus::FAILED->value,
                    'expired' => OrderStatus::EXPIRED->value,
                    'refunded' => OrderStatus::REFUNDED->value,
                    default => OrderStatus::PENDING->value,
                };

                // update order
                $lockedOrder->payment_gateway_code = 'midtrans';
                $lockedOrder->status = $newOrderStatus;
                if (property_exists($lockedOrder, 'midtrans_payload')) {
                    $lockedOrder->midtrans_payload = $payload;
                }
                $lockedOrder->save();

                // update/ensure payment
                $payment = $lockedOrder->payment;
                $paymentStatus = match ($mapped) {
                    'paid' => PaymentStatus::PAID->value,
                    'pending' => PaymentStatus::PENDING->value,
                    'failed' => PaymentStatus::FAILED->value,
                    'expired' => PaymentStatus::EXPIRED->value,
                    'refunded' => PaymentStatus::REFUNDED->value,
                    default => PaymentStatus::PENDING->value,
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

                // kalau belum paid, stop
                if (!$isPaid || $mapped !== 'paid') {
                    return;
                }

                /**
                 * ==========================================================
                 * REFERRAL COMMISSION -> MASUK KE WALLET KOMISI (IDR_COMMISSION)
                 * ==========================================================
                 */
                $refTx = ReferralTransaction::query()
                    ->where('order_id', (int) $lockedOrder->id)
                    ->lockForUpdate()
                    ->first();

                if ($refTx && $refTx->status !== 'valid') {

                    $settings = ReferralSetting::current();

                    if (!$settings || !($settings->enabled ?? false)) {
                        $refTx->status = 'invalid';
                        $refTx->occurred_at = now();
                        $refTx->save();
                    } else {
                        $orderAmount = (int) round((float) ($lockedOrder->amount ?? 0));

                        $commission = 0;
                        if (($settings->commission_type ?? 'percent') === 'fixed') {
                            $commission = (int) ($settings->commission_value ?? 0);
                        } else {
                            $pct = (int) ($settings->commission_value ?? 0);
                            $commission = (int) floor($orderAmount * $pct / 100);
                        }
                        $commission = max(0, $commission);

                        $refTx->status = 'valid';
                        $refTx->order_amount = $orderAmount;
                        $refTx->commission_amount = $commission;
                        $refTx->occurred_at = now();
                        $refTx->save();

                        if ($commission > 0) {
                            $ledger->creditReferralCommissionToCommissionWallet(
                                referrerUserId: (int) $refTx->referrer_id,
                                commissionAmount: (int) $commission,
                                idempotencyKey: 'REF_COMMISSION:' . (int) $refTx->id,
                                note: 'Referral commission -> IDR_COMMISSION (Midtrans Paid)',
                                referenceType: 'referral_transaction',
                                referenceId: (int) $refTx->id
                            );

                            Log::info('REFERRAL COMMISSION CREDITED TO COMMISSION WALLET', [
                                'order_db_id' => (int) $lockedOrder->id,
                                'referral_tx_id' => (int) $refTx->id,
                                'referrer_id' => (int) $refTx->referrer_id,
                                'commission' => (int) $commission,
                                'wallet_currency' => 'IDR_COMMISSION',
                                'midtrans_order_id' => $orderId,
                                'transaction_status' => $transactionStatus,
                                'payment_type' => $paymentType,
                                'transaction_id' => $transactionId,
                                'settlement_time' => $settlementTime,
                            ]);
                        }
                    }
                }

                /**
                 * FULFILL
                 */
                $res = $fulfillment->fulfillPaidOrder($lockedOrder->fresh());

                if (!($res['ok'] ?? false)) {
                    Log::error('FULFILLMENT FAILED AFTER PAID', [
                        'order_db_id' => $lockedOrder->id,
                        'invoice_number' => $lockedOrder->invoice_number,
                        'message' => $res['message'] ?? null,
                    ]);
                    return;
                }

                $lockedOrder->status = OrderStatus::FULFILLED->value;
                $lockedOrder->save();

                // send invoice email after commit
                $this->dispatchInvoiceEmailAfterCommit(
                    (int) $lockedOrder->id,
                    'midtrans_paid',
                    (string) $lockedOrder->invoice_number
                );

                Log::info('MIDTRANS ORDER PAID -> FULFILLED', [
                    'midtrans_order_id' => $orderId,
                    'order_db_id' => $lockedOrder->id,
                ]);
            });

            return response()->json(['success' => true, 'message' => 'OK (order)'], 200);

        } catch (\Throwable $e) {
            Log::error('MIDTRANS ORDER WEBHOOK ERROR', [
                'order_id' => $orderId,
                'err' => $e->getMessage(),
            ]);
            return response()->json(['success' => true, 'ignored' => true, 'message' => 'Order internal error (ignored)'], 200);
        }
    }
}