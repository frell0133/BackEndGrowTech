<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\WalletTopup;
use App\Services\LedgerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminWalletController extends Controller
{
    /**
     * POST /api/v1/admin/wallet/topup
     * Manual topup oleh admin:
     * - HANYA credit wallet user
     * - TIDAK ada debit wallet admin
     * - Audit disimpan di note (tanpa ubah DB)
     */
    public function topup(Request $request, LedgerService $ledgerService)
    {
        $admin = $request->user();

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'min:1'],
            'amount' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $userId = (int) $data['user_id'];
        $amount = (int) $data['amount'];
        $idempotencyKey = $data['idempotency_key'] ?? null;

        $auditNote = trim(
            '[ADMIN_TOPUP]'
            . ' actor_admin_id=' . ($admin?->id ?? 'unknown')
            . ' target_user_id=' . $userId
            . ' amount=' . $amount
            . ($data['note'] ? ' | ' . $data['note'] : '')
        );

        $tx = DB::transaction(function () use ($ledgerService, $userId, $amount, $idempotencyKey, $auditNote) {

            if ($idempotencyKey) {
                $existing = LedgerTransaction::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($existing) return $existing;
            }

            $wallet = $ledgerService->getOrCreateUserWallet($userId);

            // ✅ HAPUS reference_type & reference_id (biar gak column not found)
            $tx = LedgerTransaction::create([
                'type' => 'ADMIN_TOPUP',
                'status' => 'SUCCESS',
                'idempotency_key' => $idempotencyKey,
                'note' => $auditNote,
            ]);

            $balanceAfter = (int) $wallet->balance + $amount;

            LedgerEntry::create([
                'ledger_transaction_id' => $tx->id,
                'wallet_id' => $wallet->id,
                'direction' => 'credit',
                'amount' => $amount,
                'balance_after' => $balanceAfter,
            ]);

            $wallet->update(['balance' => $balanceAfter]);

            return $tx;
        });

        return response()->json([
            'success' => true,
            'data' => [
                'transaction_id' => $tx->id,
                'type' => $tx->type,
                'status' => $tx->status,
                'idempotency_key' => $tx->idempotency_key,
                'note' => $tx->note,
            ],
        ]);
    }
    
    /**
     * POST /api/v1/admin/wallet/adjust
     * NOTE: ini akan 500 kalau LedgerService belum punya method adjust()
     */
    public function adjust(Request $request, LedgerService $ledgerService)
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'min:1'],
            'direction' => ['required', 'in:credit,debit'],
            'amount' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $tx = $ledgerService->adjust(
            (int) $data['user_id'],
            (string) $data['direction'],
            (int) $data['amount'],
            $data['idempotency_key'] ?? null,
            $data['note'] ?? null
        );

        return response()->json([
            'success' => true,
            'data' => [
                'transaction_id' => $tx->id,
                'type' => $tx->type,
                'status' => $tx->status,
                'idempotency_key' => $tx->idempotency_key,
            ],
        ]);
    }

    /**
     * GET /api/v1/admin/wallet/ledger
     */
    public function ledger(Request $request)
    {
        $perPage = (int) ($request->query('per_page', 50));

        $entries = LedgerEntry::with(['transaction', 'wallet'])
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $entries,
        ]);
    }

    /**
     * GET /api/v1/admin/wallet/topups
     * List topup user (Midtrans) dari wallet_topups
     */
    public function topups(Request $request)
    {
        $perPage = (int) $request->query('per_page', 50);

        $q = WalletTopup::query()
            ->with(['user:id,name,email'])
            ->orderByDesc('id');

        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }
        if ($userId = $request->query('user_id')) {
            $q->where('user_id', (int) $userId);
        }
        if ($request->query('unposted') === '1') {
            $q->whereNull('posted_to_ledger_at');
        }

        return response()->json([
            'success' => true,
            'data' => $q->paginate($perPage),
        ]);
    }
}