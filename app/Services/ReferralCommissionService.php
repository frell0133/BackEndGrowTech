<?php

namespace App\Services;

use App\Models\Order;
use App\Models\ReferralSetting;
use App\Models\ReferralTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReferralCommissionService
{
    /**
     * Dipanggil setiap kali order sudah PAID (midtrans/wallet).
     * - set referral_tx -> valid
     * - credit komisi ke wallet IDR_COMMISSION
     * Idempotent via status valid + idempotency_key ledger.
     */
    public function handleOrderPaid(Order $order, LedgerService $ledger, array $meta = []): void
    {
        DB::transaction(function () use ($order, $ledger, $meta) {

            $refTx = ReferralTransaction::query()
                ->where('order_id', (int) $order->id)
                ->lockForUpdate()
                ->first();

            // order tidak pakai referral
            if (!$refTx) return;

            // sudah diproses
            if ($refTx->status === 'valid') return;

            $settings = ReferralSetting::current();
            if (!$settings || !$settings->isActiveNow()) {
                $refTx->status = 'invalid';
                $refTx->occurred_at = now();
                $refTx->save();
                return;
            }

            $orderAmount = (int) round((float) ($order->amount ?? 0));

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
                    note: 'Referral commission -> IDR_COMMISSION (Paid)',
                    referenceType: 'referral_transaction',
                    referenceId: (int) $refTx->id
                );
            }

            Log::info('REFERRAL COMMISSION HANDLED (PAID)', [
                'order_id' => (int) $order->id,
                'ref_tx_id' => (int) $refTx->id,
                'referrer_id' => (int) $refTx->referrer_id,
                'commission' => (int) $commission,
                'meta' => $meta,
            ]);
        });
    }
}