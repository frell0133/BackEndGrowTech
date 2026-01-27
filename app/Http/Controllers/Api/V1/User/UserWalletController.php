<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Models\LedgerEntry;
use App\Services\LedgerService;
use Illuminate\Http\Request;

class UserWalletController extends Controller
{
    public function summary(Request $request, LedgerService $ledgerService)
    {
        $userId = (int) $request->user()->id;
        $wallet = $ledgerService->getOrCreateUserWallet($userId);

        $lastEntries = LedgerEntry::with('transaction')
            ->where('wallet_id', $wallet->id)
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(function ($e) {
                return [
                    'id' => $e->id,
                    'tx_id' => $e->ledger_transaction_id,
                    'type' => $e->transaction?->type,
                    'direction' => $e->direction,
                    'amount' => $e->amount,
                    'balance_after' => $e->balance_after,
                    'created_at' => $e->created_at,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'wallet' => [
                    'id' => $wallet->id,
                    'balance' => (int) $wallet->balance,
                    'currency' => $wallet->currency,
                    'status' => $wallet->status,
                ],
                'last_entries' => $lastEntries,
            ],
        ]);
    }

    public function ledger(Request $request, LedgerService $ledgerService)
    {
        $userId = (int) $request->user()->id;
        $wallet = $ledgerService->getOrCreateUserWallet($userId);

        $perPage = (int) ($request->query('per_page', 20));

        $entries = LedgerEntry::with('transaction')
            ->where('wallet_id', $wallet->id)
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $entries,
        ]);
    }
}
