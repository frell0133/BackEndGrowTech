<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Models\LedgerEntry;
use App\Services\LedgerService;
use App\Support\RuntimeCache;
use Illuminate\Http\Request;

class UserWalletController extends Controller
{
    private const SUMMARY_TTL = 8;
    private const LEDGER_TTL = 8;

    public function summary(Request $request, LedgerService $ledgerService)
    {
        $userId = (int) $request->user()->id;
        $cacheKey = sprintf('wallet:summary:user:%d', $userId);

        $payload = RuntimeCache::remember($cacheKey, self::SUMMARY_TTL, function () use ($ledgerService, $userId) {
            $wallet = $ledgerService->getOrCreateUserWallet($userId);

            $lastEntries = LedgerEntry::with('transaction:id,type')
                ->where('wallet_id', $wallet->id)
                ->orderByDesc('id')
                ->limit(10)
                ->get()
                ->map(function ($entry) {
                    return [
                        'id' => $entry->id,
                        'tx_id' => $entry->ledger_transaction_id,
                        'type' => $entry->transaction?->type,
                        'direction' => $entry->direction,
                        'amount' => $entry->amount,
                        'balance_after' => $entry->balance_after,
                        'created_at' => $entry->created_at,
                    ];
                })
                ->values()
                ->all();

            return [
                'wallet' => [
                    'id' => $wallet->id,
                    'balance' => (int) $wallet->balance,
                    'currency' => $wallet->currency,
                    'status' => $wallet->status,
                ],
                'last_entries' => $lastEntries,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $payload,
        ]);
    }

    public function ledger(Request $request, LedgerService $ledgerService)
    {
        $userId = (int) $request->user()->id;
        $perPage = max(1, min((int) $request->query('per_page', 20), 100));
        $page = max(1, (int) $request->query('page', 1));

        $cacheKey = sprintf('wallet:ledger:user:%d:page:%d:per_page:%d', $userId, $page, $perPage);

        $payload = RuntimeCache::remember($cacheKey, self::LEDGER_TTL, function () use ($ledgerService, $userId, $perPage) {
            $wallet = $ledgerService->getOrCreateUserWallet($userId);

            return LedgerEntry::with('transaction:id,type')
                ->where('wallet_id', $wallet->id)
                ->orderByDesc('id')
                ->paginate($perPage)
                ->toArray();
        });

        return response()->json([
            'success' => true,
            'data' => $payload,
        ]);
    }
}
