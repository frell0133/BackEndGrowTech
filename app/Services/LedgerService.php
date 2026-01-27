<?php

namespace App\Services;

use App\Models\Wallet;
use App\Models\LedgerTransaction;
use App\Models\LedgerEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LedgerService
{
    // Helper: ambil / bikin wallet user
    public function getOrCreateUserWallet(int $userId): Wallet
    {
        return Wallet::firstOrCreate(
            ['user_id' => $userId, 'currency' => 'IDR'],
            ['balance' => 0, 'status' => 'ACTIVE']
        );
    }

    // Helper: ambil system wallet by code
    public function getSystemWallet(string $code): Wallet
    {
        $wallet = Wallet::where('code', $code)->first();
        if (!$wallet) {
            throw ValidationException::withMessages(['wallet' => "System wallet {$code} belum ada. Jalankan seeder."]);
        }
        return $wallet;
    }

    // Helper: apply perubahan saldo berdasarkan direction
    private function applyBalance(Wallet $wallet, string $direction, int $amount): array
    {
        $before = (int) $wallet->balance;

        if ($direction === 'DEBIT') {
            $after = $before - $amount;
        } else { // CREDIT
            $after = $before + $amount;
        }

        return [$before, $after];
    }

    // TOPUP: user CREDIT, system_cash DEBIT
    public function topup(int $userId, int $amount, ?string $idempotencyKey = null, ?string $note = null): LedgerTransaction
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Amount harus > 0']);
        }

        return DB::transaction(function () use ($userId, $amount, $idempotencyKey, $note) {
            if ($idempotencyKey) {
                $existing = LedgerTransaction::where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    return $existing; // idempotent: tidak dobel menambah saldo
                }
            }

            $userWallet = $this->getOrCreateUserWallet($userId);
            $cashWallet = $this->getSystemWallet('SYSTEM_CASH');

            // lock wallet untuk hindari race condition
            $userWallet = Wallet::whereKey($userWallet->id)->lockForUpdate()->first();
            $cashWallet = Wallet::whereKey($cashWallet->id)->lockForUpdate()->first();

            $tx = LedgerTransaction::create([
                'type' => 'TOPUP',
                'status' => 'SUCCESS',
                'idempotency_key' => $idempotencyKey,
                'note' => $note,
            ]);

            // Entry 1: user CREDIT
            [$ubefore, $uafter] = $this->applyBalance($userWallet, 'CREDIT', $amount);
            $userWallet->update(['balance' => $uafter]);

            LedgerEntry::create([
                'ledger_transaction_id' => $tx->id,
                'wallet_id' => $userWallet->id,
                'direction' => 'CREDIT',
                'amount' => $amount,
                'balance_before' => $ubefore,
                'balance_after' => $uafter,
            ]);

            // Entry 2: system cash DEBIT
            // (DEBIT = saldo berkurang sesuai aturan kita)
            [$cbefore, $cafter] = $this->applyBalance($cashWallet, 'DEBIT', $amount);
            $cashWallet->update(['balance' => $cafter]);

            LedgerEntry::create([
                'ledger_transaction_id' => $tx->id,
                'wallet_id' => $cashWallet->id,
                'direction' => 'DEBIT',
                'amount' => $amount,
                'balance_before' => $cbefore,
                'balance_after' => $cafter,
            ]);

            return $tx;
        });
    }

    // PURCHASE: user DEBIT, system revenue CREDIT
    public function purchase(int $userId, int $amount, ?string $note = null): LedgerTransaction
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Amount harus > 0']);
        }

        return DB::transaction(function () use ($userId, $amount, $note) {
            $userWallet = $this->getOrCreateUserWallet($userId);
            $revenueWallet = $this->getSystemWallet('SYSTEM_REVENUE');

            $userWallet = Wallet::whereKey($userWallet->id)->lockForUpdate()->first();
            $revenueWallet = Wallet::whereKey($revenueWallet->id)->lockForUpdate()->first();

            if ($userWallet->balance < $amount) {
                throw ValidationException::withMessages(['balance' => 'Saldo tidak cukup']);
            }

            $tx = LedgerTransaction::create([
                'type' => 'PURCHASE',
                'status' => 'SUCCESS',
                'note' => $note,
            ]);

            // user DEBIT
            [$ubefore, $uafter] = $this->applyBalance($userWallet, 'DEBIT', $amount);
            $userWallet->update(['balance' => $uafter]);

            LedgerEntry::create([
                'ledger_transaction_id' => $tx->id,
                'wallet_id' => $userWallet->id,
                'direction' => 'DEBIT',
                'amount' => $amount,
                'balance_before' => $ubefore,
                'balance_after' => $uafter,
            ]);

            // system revenue CREDIT
            [$rbefore, $rafter] = $this->applyBalance($revenueWallet, 'CREDIT', $amount);
            $revenueWallet->update(['balance' => $rafter]);

            LedgerEntry::create([
                'ledger_transaction_id' => $tx->id,
                'wallet_id' => $revenueWallet->id,
                'direction' => 'CREDIT',
                'amount' => $amount,
                'balance_before' => $rbefore,
                'balance_after' => $rafter,
            ]);

            return $tx;
        });
    }
}
