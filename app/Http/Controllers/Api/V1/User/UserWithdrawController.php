<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Models\WithdrawRequest;
use App\Models\ReferralSetting;
use App\Services\LedgerService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class UserWithdrawController extends Controller
{
    use ApiResponse;

    /**
     * POST /api/v1/withdraws
     * body: { "amount": 1000, "payout_details": { ...optional... } }
     */
    public function store(Request $request, LedgerService $ledger)
    {
        $user = $request->user();
        if (!$user) return $this->fail('Unauthenticated', 401);

        $v = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'payout_details' => ['nullable', 'array'],
        ]);

        $amount = (int) $v['amount'];

        $settings = ReferralSetting::current();
        $minWd = (int) ($settings->min_withdrawal ?? 0);
        if ($minWd > 0 && $amount < $minWd) {
            return $this->fail("Minimal withdraw adalah {$minWd}", 422);
        }

        $wallet = $ledger->getOrCreateUserWallet((int) $user->id);
        if ((float)$wallet->balance < (float)$amount) {
            return $this->fail('Saldo tidak cukup', 422);
        }

        $wr = WithdrawRequest::create([
            'user_id' => (int) $user->id,
            'amount' => (float) $amount,
            'status' => 'pending',
            'payout_details' => $v['payout_details'] ?? null,
        ]);

        return $this->ok([
            'message' => 'Withdraw request berhasil dibuat',
            'withdraw' => $wr,
        ]);
    }

    /**
     * GET /api/v1/withdraws
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) return $this->fail('Unauthenticated', 401);

        $q = WithdrawRequest::query()
            ->where('user_id', (int) $user->id)
            ->orderByDesc('id')
            ->paginate((int) $request->query('per_page', 20));

        return $this->ok($q);
    }

    /**
     * GET /api/v1/withdraws/{id}
     */
    public function show(string $id, Request $request)
    {
        $user = $request->user();
        if (!$user) return $this->fail('Unauthenticated', 401);

        $wr = WithdrawRequest::query()
            ->where('id', (int) $id)
            ->where('user_id', (int) $user->id)
            ->first();

        if (!$wr) return $this->fail('Withdraw request tidak ditemukan', 404);

        return $this->ok($wr);
    }
}