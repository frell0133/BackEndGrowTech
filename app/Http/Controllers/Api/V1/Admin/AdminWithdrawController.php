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
     * GET /api/v1/admin/withdraws
     * Query:
     * - status=pending|approved|paid|rejected
     * - user_id=
     * - q=search name/email
     * - date_from=YYYY-MM-DD
     * - date_to=YYYY-MM-DD
     * - per_page=20
     */
    public function index(Request $request)
    {
        $status = $request->query('status');
        $userId = $request->query('user_id');
        $q = trim((string) $request->query('q', ''));
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');

        $query = WithdrawRequest::query()
            ->with(['user:id,name,email'])
            ->when($status, fn($qq) => $qq->where('status', $status))
            ->when($userId, fn($qq) => $qq->where('user_id', (int)$userId));

        if ($dateFrom) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        if ($q !== '') {
            $query->whereHas('user', function ($u) use ($q) {
                $u->where('name', 'ilike', "%{$q}%")
                  ->orWhere('email', 'ilike', "%{$q}%");
            });
        }

        $data = $query
            ->orderByDesc('id')
            ->paginate((int) $request->query('per_page', 20));

        return $this->ok($data);
    }

    /**
     * GET /api/v1/admin/withdraws/{id}
     * Detail 1 withdraw (optional, untuk modal/detail)
     */
    public function show(string $id)
    {
        $wr = WithdrawRequest::query()
            ->with(['user:id,name,email'])
            ->where('id', (int)$id)
            ->first();

        if (!$wr) return $this->fail('Withdraw request tidak ditemukan', 404);

        return $this->ok(['withdraw' => $wr]);
    }

    /**
     * GET /api/v1/admin/withdraws/summary
     * Count per status: pending/approved/paid/rejected
     */
    public function summary()
    {
        $rows = WithdrawRequest::query()
            ->select('status', DB::raw('COUNT(*)::int as total'))
            ->groupBy('status')
            ->get();

        $map = [
            'pending' => 0,
            'approved' => 0,
            'paid' => 0,
            'rejected' => 0,
        ];

        foreach ($rows as $r) {
            $map[$r->status] = (int) $r->total;
        }

        return $this->ok($map);
    }

    /**
     * POST /api/v1/admin/withdraws/{id}/approve
     * ✅ FLOW: IDR_COMMISSION -> IDR (saldo GrowTech user bertambah)
     *
     * Optional query:
     * - final=1  => langsung set status 'paid' (tanpa perlu markPaid)
     */
    public function approve(Request $request, string $id, LedgerService $ledger)
    {
        $final = (int) $request->query('final', 0) === 1;

        return DB::transaction(function () use ($id, $ledger, $final) {

            $wr = WithdrawRequest::query()
                ->where('id', (int)$id)
                ->lockForUpdate()
                ->first();

            if (!$wr) return $this->fail('Withdraw request tidak ditemukan', 404);

            // ✅ idempotent: kalau sudah diproses, kembalikan data terbaru
            if ($wr->status !== 'pending') {
                return $this->ok([
                    'message' => 'Withdraw sudah diproses sebelumnya',
                    'withdraw' => $wr->fresh(),
                ]);
            }

            $amount = (int) $wr->amount;
            if ($amount <= 0) return $this->fail('Amount invalid', 422);

            // ✅ idempotent di ledger: idempotencyKey harus konsisten per withdraw id
            $ledger->approveWithdrawCommissionToMain(
                userId: (int) $wr->user_id,
                amount: (int) $amount,
                idempotencyKey: 'WD_APPROVE:' . (int) $wr->id,
                note: 'Approve WD: IDR_COMMISSION -> IDR (saldo GrowTech)',
                referenceType: 'withdraw_request',
                referenceId: (int) $wr->id
            );

            // status after approve
            $wr->approved_at = now();
            $wr->processed_at = now();

            if ($final) {
                $wr->status = 'paid';
                $wr->paid_at = now();
            } else {
                $wr->status = 'approved';
            }

            $wr->save();

            return $this->ok([
                'message' => $final
                    ? 'Withdraw approved & paid. Saldo GrowTech user bertambah dari komisi.'
                    : 'Withdraw approved. Saldo GrowTech user bertambah dari komisi.',
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

        return DB::transaction(function () use ($id, $v) {
            $wr = WithdrawRequest::query()
                ->where('id', (int)$id)
                ->lockForUpdate()
                ->first();

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
                'withdraw' => $wr->fresh(),
            ]);
        });
    }

    /**
     * POST /api/v1/admin/withdraws/{id}/mark-paid
     * status: approved -> paid
     */
    public function markPaid(Request $request, string $id)
    {
        return DB::transaction(function () use ($id) {
            $wr = WithdrawRequest::query()
                ->where('id', (int)$id)
                ->lockForUpdate()
                ->first();

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
                'withdraw' => $wr->fresh(),
            ]);
        });
    }
}