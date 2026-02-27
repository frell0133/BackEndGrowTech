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

            // FE boleh kirim atau tidak (optional)
            'note' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
        ]);

        $userId = (int) $data['user_id'];
        $amount = (int) $data['amount'];

        // ✅ AUTO idempotency_key kalau FE tidak mengirim
        // Pilih salah satu strategi:

        // (A) unik per request (aman anti-double click selama FE retry memakai key yg sama)
        $idempotencyKey = $data['idempotency_key']
            ?? ('ADMIN-TOPUP-' . $userId . '-' . $amount . '-' . now()->format('YmdHis'));

        // (B) kalau kamu punya "topup_id" dan ingin 1 rescue per topup:
        // $idempotencyKey = $data['idempotency_key'] ?? ('ADMIN-RESCUE-TOPUP-' . (int)$data['topup_id']);

        // ✅ AUTO note audit
        $note = trim(
            '[ADMIN_TOPUP] actor_admin_id=' . ($admin?->id ?? 'unknown')
            . ' target_user_id=' . $userId
            . ' amount=' . $amount
            . ($data['note'] ? ' | ' . $data['note'] : '')
        );

        // ✅ pakai method yang single-credit (tanpa SYSTEM_CASH debit)
        $tx = $ledgerService->adminTopup(
            $userId,
            $amount,
            $idempotencyKey,
            $note
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