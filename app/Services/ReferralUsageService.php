<?php

namespace App\Services;

use App\Models\ReferralTransaction;
use Illuminate\Support\Facades\Log;

class ReferralUsageService
{
    /**
     * Hitung pemakaian referral yang benar-benar masih mengonsumsi kuota user.
     * Hanya transaksi valid + pending yang masih punya order aktif yang dihitung.
     */
    public function countConsumableUsesForUser(int $userId): int
    {
        return ReferralTransaction::query()
            ->where('user_id', $userId)
            ->where(function ($q) {
                $q->where('status', 'valid')
                  ->orWhere(function ($pending) {
                      $pending->where('status', 'pending')
                          ->whereHas('order', function ($orderQ) {
                              $orderQ->whereNotIn('status', ['cancelled', 'failed', 'expired', 'refunded']);
                          });
                  });
            })
            ->count();
    }

    /**
     * Batalkan referral pending ketika order batal / gagal / expired / refunded.
     */
    public function invalidatePendingForOrder(int $orderId, string $reason = 'order_invalidated'): void
    {
        $updated = ReferralTransaction::query()
            ->where('order_id', $orderId)
            ->where('status', 'pending')
            ->update([
                'status' => 'invalid',
                'occurred_at' => now(),
                'updated_at' => now(),
            ]);

        if ($updated > 0) {
            Log::info('REFERRAL TRANSACTION INVALIDATED', [
                'order_id' => $orderId,
                'reason' => $reason,
                'updated_rows' => $updated,
            ]);
        }
    }
}
