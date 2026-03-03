<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\WithdrawRequest;
use App\Services\LedgerService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminWithdrawController extends Controller
{
    use ApiResponse;

    /**
     * GET /api/v1/admin/withdraws?status=pending
     */
    public function index(Request $request)
    {
        $status = $request->query('status');
        $userId = $request->query('user_id');

        $q = WithdrawRequest::query()
            ->with(['user:id,name,email'])
            ->when($status, fn($qq) => $qq->where('status', $status))
            ->when($userId, fn($qq) => $qq->where('user_id', (int)$userId))
            ->orderByDesc('id')
            ->paginate((int) $request->query('per_page', 20));

        return $this->ok($q);
    }

    /**
     * POST /api/v1/admin/withdraws/{id}/approve
     * ✅ auto pindah saldo user -> wallet sistem GROWTECH
     */
    public function approve(Request $request, string $id, LedgerService $ledger)
    {
        return DB::transaction(function () use ($id, $ledger) {
            $wr = WithdrawRequest::query()
                ->where('id', (int)$id)
                ->lockForUpdate()
                ->first();

            if (!$wr) return $this->fail('Withdraw request tidak ditemukan', 404);

            if ($wr->status !== 'pending') {
                return $this->fail('Withdraw request bukan pending', 409);
            }

            $amount = (int) round((float)$wr->amount);
            if ($amount <= 0) return $this->fail('Amount invalid', 422);

            $userWallet = $ledger->getOrCreateUserWallet((int) $wr->user_id);
            $growtechWallet = $ledger->getSystemWallet('GROWTECH');

            $idem = 'WD_APPROVE:' . $wr->id;

            // ✅ WAJIB type enum yang valid: pakai 'WITHDRAW'
            $ledger->transferWalletToWallet(
                fromWalletId: (int) $userWallet->id,
                toWalletId: (int) $growtechWallet->id,
                amount: (int) $amount,
                type: 'WITHDRAW',
                idempotencyKey: $idem,
                note: 'Approve withdraw -> masuk GROWTECH',
                referenceType: 'withdraw_request',
                referenceId: (int) $wr->id
            );

            $wr->status = 'approved';
            $wr->approved_at = now();
            $wr->processed_at = now();
            $wr->save();

            return $this->ok([
                'message' => 'Withdraw approved. Saldo user masuk ke GROWTECH.',
                'withdraw' => $wr->fresh(),
            ]);
        });
    }

    /**
     * POST /api/v1/admin/withdraws/{id}/reject
     */
    public function reject(Request $request, string $id)
    {
        $v = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $wr = WithdrawRequest::query()->where('id', (int)$id)->lockForUpdate()->first();
        if (!$wr) return $this->fail('Withdraw request tidak ditemukan', 404);

        if ($wr->status !== 'pending') {
            return $this->fail('Withdraw request bukan pending', 409);
        }

        $wr->status = 'rejected';
        $wr->rejected_at = now();
        $wr->processed_at = now();
        $wr->reject_reason = $v['reason'] ?? null;
        $wr->save();

        return $this->ok([
            'message' => 'Withdraw rejected',
            'withdraw' => $wr,
        ]);
    }

    /**
     * POST /api/v1/admin/withdraws/{id}/mark-paid
     * (opsional kalau kamu mau status paid setelah transfer real ke bank)
     */
    public function markPaid(Request $request, string $id)
    {
        $wr = WithdrawRequest::query()->where('id', (int)$id)->lockForUpdate()->first();
        if (!$wr) return $this->fail('Withdraw request tidak ditemukan', 404);

        if (!in_array($wr->status, ['approved'], true)) {
            return $this->fail('Hanya bisa mark paid dari approved', 409);
        }

        $wr->status = 'paid';
        $wr->paid_at = now();
        $wr->processed_at = now();
        $wr->save();

        return $this->ok([
            'message' => 'Withdraw marked as paid',
            'withdraw' => $wr,
        ]);
    }
}