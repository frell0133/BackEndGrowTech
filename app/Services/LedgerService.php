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

    // ✅ Helper: apply perubahan saldo berdasarkan direction (FLOAT SAFE)
    private function applyBalance(Wallet $wallet, string $direction, int $amount): array
    {
        $before = (float) $wallet->balance;

        if ($direction === 'DEBIT') {
            $after = $before - (float) $amount;
        } else { // CREDIT
            $after = $before + (float) $amount;
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
                    return $existing; // idempotent
                }
            }

            $userWallet = $this->getOrCreateUserWallet($userId);
            $cashWallet = $this->getSystemWallet('SYSTEM_CASH');

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

            if ((float)$userWallet->balance < (float)$amount) {
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

    // ADMIN TOPUP: user CREDIT ONLY
    public function adminTopup(int $userId, int $amount, ?string $idempotencyKey = null, ?string $note = null): LedgerTransaction
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Amount harus > 0']);
        }

        return DB::transaction(function () use ($userId, $amount, $idempotencyKey, $note) {

            if ($idempotencyKey) {
                $existing = LedgerTransaction::where('idempotency_key', $idempotencyKey)->first();
                if ($existing) return $existing;
            }

            $userWallet = $this->getOrCreateUserWallet($userId);
            $userWallet = Wallet::whereKey($userWallet->id)->lockForUpdate()->first();

            $tx = LedgerTransaction::create([
                'type' => 'TOPUP',
                'status' => 'SUCCESS',
                'idempotency_key' => $idempotencyKey,
                'note' => $note,
            ]);

            [$before, $after] = $this->applyBalance($userWallet, 'CREDIT', $amount);
            $userWallet->update(['balance' => $after]);

            LedgerEntry::create([
                'ledger_transaction_id' => $tx->id,
                'wallet_id' => $userWallet->id,
                'direction' => 'CREDIT',
                'amount' => $amount,
                'balance_before' => $before,
                'balance_after' => $after,
            ]);

            return $tx;
        });
    }

    // ✅ TRANSFER WALLET -> WALLET
    public function transferWalletToWallet(
        int $fromWalletId,
        int $toWalletId,
        int $amount,
        string $type,
        ?string $idempotencyKey = null,
        ?string $note = null,
        ?string $referenceType = null,
        ?int $referenceId = null
    ): LedgerTransaction {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Amount harus > 0']);
        }

        return DB::transaction(function () use (
            $fromWalletId, $toWalletId, $amount, $type,
            $idempotencyKey, $note, $referenceType, $referenceId
        ) {
            if ($idempotencyKey) {
                $existing = LedgerTransaction::where('idempotency_key', $idempotencyKey)->first();
                if ($existing) return $existing;
            }

            $from = Wallet::whereKey($fromWalletId)->lockForUpdate()->firstOrFail();
            $to   = Wallet::whereKey($toWalletId)->lockForUpdate()->firstOrFail();

            if ((float)$from->balance < (float)$amount) {
                throw ValidationException::withMessages(['balance' => 'Saldo tidak cukup']);
            }

            // IMPORTANT: $type harus enum valid: TOPUP/PURCHASE/WITHDRAW/REFERRAL/ADJUST/REFUND
            $tx = LedgerTransaction::create([
                'type' => $type,
                'status' => 'SUCCESS',
                'idempotency_key' => $idempotencyKey,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'note' => $note,
            ]);

            // from DEBIT
            [$fbefore, $fafter] = $this->applyBalance($from, 'DEBIT', $amount);
            $from->update(['balance' => $fafter]);

            LedgerEntry::create([
                'ledger_transaction_id' => $tx->id,
                'wallet_id' => $from->id,
                'direction' => 'DEBIT',
                'amount' => $amount,
                'balance_before' => $fbefore,
                'balance_after' => $fafter,
            ]);

            // to CREDIT
            [$tbefore, $tafter] = $this->applyBalance($to, 'CREDIT', $amount);
            $to->update(['balance' => $tafter]);

            LedgerEntry::create([
                'ledger_transaction_id' => $tx->id,
                'wallet_id' => $to->id,
                'direction' => 'CREDIT',
                'amount' => $amount,
                'balance_before' => $tbefore,
                'balance_after' => $tafter,
            ]);

            return $tx;
        });
    }
}