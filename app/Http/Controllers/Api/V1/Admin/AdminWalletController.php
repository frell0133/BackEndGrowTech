<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\LedgerEntry;
use App\Models\WalletTopup;
use App\Services\LedgerService;
use Illuminate\Http\Request;

class AdminWalletController extends Controller
{
    /**
     * POST /api/v1/admin/wallet/topup
     * Manual topup oleh admin (rescue case)
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

        // audit masuk note (tanpa ubah DB)
        $auditNote = trim(
            '[ADMIN_TOPUP] actor_admin_id=' . ($admin?->id ?? 'unknown')
            . ' target_user_id=' . $userId
            . ' amount=' . $amount
            . ($data['note'] ? ' | ' . $data['note'] : '')
        );

        $tx = DB::transaction(function () use ($ledgerService, $userId, $amount, $idempotencyKey, $auditNote) {

            // idempotency guard (optional)
            if ($idempotencyKey) {
                $existing = LedgerTransaction::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($existing) return $existing;
            }

            $wallet = $ledgerService->getOrCreateUserWallet($userId);

            // buat transaction
            $tx = LedgerTransaction::create([
                'type' => 'ADMIN_TOPUP',
                'status' => 'SUCCESS',
                'idempotency_key' => $idempotencyKey,
                'reference_type' => 'user_wallet',
                'reference_id' => $wallet->id,
                'note' => $auditNote,
            ]);

            // hanya 1 entry: CREDIT user
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
     * Adjust balance (debit/credit) oleh admin
     * direction: credit|debit
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

        // kalau LedgerService kamu belum punya method adjust(),
        // paling aman: map debit/credit ke method yang sudah ada.
        // Aku asumsikan LedgerService kamu punya adjust().
        // Kalau belum, bilang—nanti aku sesuaikan dengan service kamu.

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
     * Semua ledger semua user
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
     * List semua topup user (Midtrans) dari tabel wallet_topups
     * Untuk monitor pending/failed, dll.
     */
    public function topups(Request $request)
    {
        $perPage = (int) $request->query('per_page', 50);

        $q = WalletTopup::query()
            ->with(['user:id,name,email'])
            ->orderByDesc('id');

        // filter opsional
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }
        if ($userId = $request->query('user_id')) {
            $q->where('user_id', (int) $userId);
        }
        if ($request->query('unposted') === '1') {
            $q->whereNull('posted_to_ledger_at');
        }
        if ($request->query('date_from')) {
            $q->whereDate('created_at', '>=', $request->query('date_from'));
        }
        if ($request->query('date_to')) {
            $q->whereDate('created_at', '<=', $request->query('date_to'));
        }

        return response()->json([
            'success' => true,
            'data' => $q->paginate($perPage),
        ]);
    }
}