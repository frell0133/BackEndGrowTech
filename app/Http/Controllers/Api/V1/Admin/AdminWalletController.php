<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\LedgerEntry;
use App\Models\WalletTopup;
use App\Services\LedgerService;
use Illuminate\Http\Request;

class AdminWalletController extends Controller
{
    public function topup(Request $request, LedgerService $ledgerService)
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'amount' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string'],
        ]);

        $tx = $ledgerService->topup(
            (int) $data['user_id'],
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

    // ✅ ENDPOINT BARU: GET /api/v1/admin/wallet/topups
    public function topups(Request $request)
    {
        $perPage = (int) $request->query('per_page', 50);

        $q = WalletTopup::query()
            ->with(['user:id,name,email']) // pastikan WalletTopup punya relasi user()
            ->orderByDesc('id');

        // filter opsional
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }
        if ($userId = $request->query('user_id')) {
            $q->where('user_id', (int) $userId);
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
}