<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\WithdrawRequest;
use App\Services\AdminAuditLogger;
use App\Services\LedgerService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminWithdrawController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $status = $request->query('status');
        $userId = $request->query('user_id');
        $q = trim((string) $request->query('q', ''));
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');

        $query = WithdrawRequest::query()
            ->with(['user:id,name,email'])
            ->when($status, fn ($qq) => $qq->where('status', $status))
            ->when($userId, fn ($qq) => $qq->where('user_id', (int) $userId));

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

    public function show(string $id)
    {
        $wr = WithdrawRequest::query()
            ->with(['user:id,name,email'])
            ->where('id', (int) $id)
            ->first();

        if (!$wr) return $this->fail('Withdraw request tidak ditemukan', 404);

        return $this->ok(['withdraw' => $wr]);
    }

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

    public function approve(Request $request, string $id, LedgerService $ledger, AdminAuditLogger $audit)
    {
        $final = (int) $request->query('final', 0) === 1;

        return DB::transaction(function () use ($id, $ledger, $final, $request, $audit) {
            $wr = WithdrawRequest::query()
                ->with(['user:id,name,email'])
                ->where('id', (int) $id)
                ->lockForUpdate()
                ->first();

            if (!$wr) return $this->fail('Withdraw request tidak ditemukan', 404);

            if ($wr->status !== 'pending') {
                return $this->ok([
                    'message' => 'Withdraw sudah diproses sebelumnya',
                    'withdraw' => $wr->fresh(),
                ]);
            }

            $before = $this->withdrawSnapshot($wr);
            $amount = (int) $wr->amount;
            if ($amount <= 0) return $this->fail('Amount invalid', 422);

            $ledger->approveWithdrawCommissionToMain(
                userId: (int) $wr->user_id,
                amount: (int) $amount,
                idempotencyKey: 'WD_APPROVE:' . (int) $wr->id,
                note: 'Approve WD: IDR_COMMISSION -> IDR (saldo GrowTech)',
                referenceType: 'withdraw_request',
                referenceId: (int) $wr->id
            );

            $wr->approved_at = now();
            $wr->processed_at = now();

            if ($final) {
                $wr->status = 'paid';
                $wr->paid_at = now();
            } else {
                $wr->status = 'approved';
            }

            $wr->save();
            $wr->refresh()->load(['user:id,name,email']);

            $audit->log(
                request: $request,
                action: $final ? 'withdraw.approve_and_pay' : 'withdraw.approve',
                entity: 'withdraw_requests',
                entityId: $wr->id,
                meta: [
                    'module' => 'finance',
                    'summary' => $final
                        ? 'Approve withdraw dan langsung tandai paid'
                        : 'Approve withdraw komisi ke saldo utama',
                    'target' => [
                        'withdraw_id' => $wr->id,
                        'user_id' => $wr->user_id,
                        'user_email' => $wr->user?->email,
                        'amount' => (int) $wr->amount,
                    ],
                    'before' => $before,
                    'after' => $this->withdrawSnapshot($wr),
                ],
            );

            return $this->ok([
                'message' => $final
                    ? 'Withdraw approved & paid. Saldo GrowTech user bertambah dari komisi.'
                    : 'Withdraw approved. Saldo GrowTech user bertambah dari komisi.',
                'withdraw' => $wr,
            ]);
        });
    }

    public function reject(Request $request, string $id, AdminAuditLogger $audit)
    {
        $v = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        return DB::transaction(function () use ($id, $v, $request, $audit) {
            $wr = WithdrawRequest::query()
                ->with(['user:id,name,email'])
                ->where('id', (int) $id)
                ->lockForUpdate()
                ->first();

            if (!$wr) return $this->fail('Withdraw request tidak ditemukan', 404);

            if ($wr->status !== 'pending') {
                return $this->fail('Withdraw request bukan pending', 409);
            }

            $before = $this->withdrawSnapshot($wr);

            $wr->status = 'rejected';
            $wr->rejected_at = now();
            $wr->processed_at = now();
            $wr->reject_reason = $v['reason'] ?? null;
            $wr->save();
            $wr->refresh()->load(['user:id,name,email']);

            $audit->log(
                request: $request,
                action: 'withdraw.reject',
                entity: 'withdraw_requests',
                entityId: $wr->id,
                meta: [
                    'module' => 'finance',
                    'summary' => 'Reject withdraw request',
                    'target' => [
                        'withdraw_id' => $wr->id,
                        'user_id' => $wr->user_id,
                        'user_email' => $wr->user?->email,
                        'amount' => (int) $wr->amount,
                    ],
                    'before' => $before,
                    'after' => $this->withdrawSnapshot($wr),
                ],
            );

            return $this->ok([
                'message' => 'Withdraw rejected',
                'withdraw' => $wr,
            ]);
        });
    }

    public function markPaid(Request $request, string $id, AdminAuditLogger $audit)
    {
        return DB::transaction(function () use ($id, $request, $audit) {
            $wr = WithdrawRequest::query()
                ->with(['user:id,name,email'])
                ->where('id', (int) $id)
                ->lockForUpdate()
                ->first();

            if (!$wr) return $this->fail('Withdraw request tidak ditemukan', 404);

            if (!in_array($wr->status, ['approved'], true)) {
                return $this->fail('Hanya bisa mark paid dari approved', 409);
            }

            $before = $this->withdrawSnapshot($wr);

            $wr->status = 'paid';
            $wr->paid_at = now();
            $wr->processed_at = now();
            $wr->save();
            $wr->refresh()->load(['user:id,name,email']);

            $audit->log(
                request: $request,
                action: 'withdraw.mark_paid',
                entity: 'withdraw_requests',
                entityId: $wr->id,
                meta: [
                    'module' => 'finance',
                    'summary' => 'Mark withdraw sebagai paid',
                    'target' => [
                        'withdraw_id' => $wr->id,
                        'user_id' => $wr->user_id,
                        'user_email' => $wr->user?->email,
                        'amount' => (int) $wr->amount,
                    ],
                    'before' => $before,
                    'after' => $this->withdrawSnapshot($wr),
                ],
            );

            return $this->ok([
                'message' => 'Withdraw marked as paid',
                'withdraw' => $wr,
            ]);
        });
    }

    private function withdrawSnapshot(WithdrawRequest $wr): array
    {
        $wr->loadMissing(['user:id,name,email']);

        return [
            'id' => $wr->id,
            'user_id' => $wr->user_id,
            'user_name' => $wr->user?->name,
            'user_email' => $wr->user?->email,
            'amount' => (int) $wr->amount,
            'status' => $wr->status,
            'reject_reason' => $wr->reject_reason,
            'approved_at' => optional($wr->approved_at)?->toISOString(),
            'rejected_at' => optional($wr->rejected_at)?->toISOString(),
            'paid_at' => optional($wr->paid_at)?->toISOString(),
            'processed_at' => optional($wr->processed_at)?->toISOString(),
        ];
    }
}