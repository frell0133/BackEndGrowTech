<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\ReferralSetting;
use App\Models\ReferralTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReferralCommissionService
{
    public function invalidateOrderReferral(int $orderId, array $meta = []): void
    {
        DB::transaction(function () use ($orderId, $meta) {
            $rows = ReferralTransaction::query()
                ->where('order_id', $orderId)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->get();

            if ($rows->isEmpty()) {
                return;
            }

            foreach ($rows as $refTx) {
                $refTx->status = 'invalid';
                $refTx->occurred_at = now();
                $refTx->save();

                Log::info('REFERRAL INVALIDATED FOR ORDER', [
                    'order_id' => (int) $orderId,
                    'ref_tx_id' => (int) $refTx->id,
                    'meta' => $meta,
                ]);
            }
        });
    }

    public function cleanupStalePendingForUser(int $userId, array $meta = []): int
    {
        $invalidOrderStatuses = [
            OrderStatus::CANCELLED->value,
            OrderStatus::FAILED->value,
            OrderStatus::EXPIRED->value,
            OrderStatus::REFUNDED->value,
        ];

        $invalidPaymentStatuses = [
            PaymentStatus::FAILED->value,
            PaymentStatus::EXPIRED->value,
            PaymentStatus::REFUNDED->value,
        ];

        $query = ReferralTransaction::query()
            ->where('user_id', $userId)
            ->where('status', 'pending')
            ->where(function ($q) use ($invalidOrderStatuses, $invalidPaymentStatuses) {
                $q->whereNull('order_id')
                    ->orWhereHas('order', function ($oq) use ($invalidOrderStatuses, $invalidPaymentStatuses) {
                        $oq->whereIn('status', $invalidOrderStatuses)
                            ->orWhereHas('payment', function ($pq) use ($invalidPaymentStatuses) {
                                $pq->whereIn('status', $invalidPaymentStatuses);
                            });
                    });
            });

        $affected = (clone $query)->update([
            'status' => 'invalid',
            'occurred_at' => now(),
            'updated_at' => now(),
        ]);

        if ($affected > 0) {
            Log::info('REFERRAL STALE PENDING CLEANED', [
                'user_id' => (int) $userId,
                'affected' => (int) $affected,
                'meta' => $meta,
            ]);
        }

        return (int) $affected;
    }

    public function getUsageSummary(int $userId): array
    {
        $this->cleanupStalePendingForUser($userId, ['source' => 'usage_summary']);

        $settings = ReferralSetting::current();

        // Hanya referral yang benar-benar valid/paid yang dihitung sebagai usage final.
        // Pending tidak boleh langsung menghabiskan kuota penggunaan user.
        $usedByUser = ReferralTransaction::query()
            ->where('user_id', $userId)
            ->where('status', 'valid')
            ->count();

        $maxUsesPerUser = (int) ($settings->max_uses_per_user ?? 0);

        return [
            'used_by_user' => (int) $usedByUser,
            'max_uses_per_user' => $maxUsesPerUser,
            'remaining_uses' => $maxUsesPerUser > 0 ? max(0, $maxUsesPerUser - $usedByUser) : null,
            'limit_reached' => $maxUsesPerUser > 0 ? $usedByUser >= $maxUsesPerUser : false,
        ];
    }

    public function handleOrderPaid(Order $order, LedgerService $ledger, array $meta = []): void
    {
        DB::transaction(function () use ($order, $ledger, $meta) {
            $refTx = ReferralTransaction::query()
                ->where('order_id', (int) $order->id)
                ->lockForUpdate()
                ->first();

            if (!$refTx) {
                return;
            }

            if ($refTx->status === 'valid') {
                return;
            }

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
