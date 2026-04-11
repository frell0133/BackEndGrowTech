<?php

namespace App\Services;

use App\Models\Wallet;
use App\Models\LedgerTransaction;
use App\Models\LedgerEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LedgerService
{
    // =========================
    // Wallet helpers
    // =========================

    // Wallet utama untuk belanja
    public function getOrCreateUserWallet(int $userId): Wallet
    {
        return Wallet::firstOrCreate(
            ['user_id' => $userId, 'currency' => 'IDR'],
            ['balance' => 0, 'status' => 'ACTIVE']
        );
    }

    // Wallet komisi/referral (saldo WD)
    public function getOrCreateUserCommissionWallet(int $userId): Wallet
    {
        return Wallet::firstOrCreate(
            ['user_id' => $userId, 'currency' => 'IDR_COMMISSION'],
            ['balance' => 0, 'status' => 'ACTIVE']
        );
    }

    // System wallet by code (SYSTEM_CASH, SYSTEM_REVENUE, SYSTEM_PAYOUT, dll)
    public function getSystemWallet(string $code, string $currency = 'IDR'): Wallet
    {
        return Wallet::firstOrCreate(
            ['code' => $code, 'currency' => $currency],
            ['balance' => 0, 'status' => 'ACTIVE']
        );
    }

    // =========================
    // Balance helper (INTEGER SAFE)
    // =========================
    private function applyBalanceInt(Wallet $wallet, string $direction, int $amount): array
    {
        $before = (int) $wallet->balance;

        if ($direction === 'DEBIT') {
            $after = $before - $amount;
        } else { // CREDIT
            $after = $before + $amount;
        }

        return [$before, $after];
    }

    // =========================
    // TOPUP: user wallet CREDIT + system cash DEBIT
    // =========================
    public function topup(int $userId, int $amount, ?string $idempotencyKey = null, ?string $note = null): LedgerTransaction
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
            $cashWallet = $this->getSystemWallet('SYSTEM_CASH');

            $userWallet = Wallet::whereKey($userWallet->id)->lockForUpdate()->first();
            $cashWallet = Wallet::whereKey($cashWallet->id)->lockForUpdate()->first();

            $tx = LedgerTransaction::create([
                'type' => 'TOPUP',
                'status' => 'SUCCESS',
                'idempotency_key' => $idempotencyKey,
                'note' => $note,
            ]);

            // user CREDIT
            [$ubefore, $uafter] = $this->applyBalanceInt($userWallet, 'CREDIT', $amount);
            $userWallet->update(['balance' => $uafter]);

            LedgerEntry::create([
                'ledger_transaction_id' => $tx->id,
                'wallet_id' => $userWallet->id,
                'direction' => 'CREDIT',
                'amount' => $amount,
                'balance_before' => $ubefore,
                'balance_after' => $uafter,
            ]);

            // system cash DEBIT
            [$cbefore, $cafter] = $this->applyBalanceInt($cashWallet, 'DEBIT', $amount);
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

    // =========================
    // PURCHASE: user wallet DEBIT + system revenue CREDIT
    // =========================
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

            if ((int)$userWallet->balance < $amount) {
                throw ValidationException::withMessages(['balance' => 'Saldo tidak cukup']);
            }

            $tx = LedgerTransaction::create([
                'type' => 'PURCHASE',
                'status' => 'SUCCESS',
                'note' => $note,
            ]);

            // user DEBIT
            [$ubefore, $uafter] = $this->applyBalanceInt($userWallet, 'DEBIT', $amount);
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
            [$rbefore, $rafter] = $this->applyBalanceInt($revenueWallet, 'CREDIT', $amount);
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

    // =========================
    // ADMIN TOPUP: user wallet CREDIT only
    // =========================
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

            [$before, $after] = $this->applyBalanceInt($userWallet, 'CREDIT', $amount);
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

    public function adjust(
        int $userId,
        string $direction,
        int $amount,
        ?string $idempotencyKey = null,
        ?string $note = null
    ): LedgerTransaction {
        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Amount harus > 0',
            ]);
        }

        $direction = strtolower(trim($direction));

        if (!in_array($direction, ['credit', 'debit'], true)) {
            throw ValidationException::withMessages([
                'direction' => 'Direction harus credit atau debit',
            ]);
        }

        return DB::transaction(function () use ($userId, $direction, $amount, $idempotencyKey, $note) {
            if ($idempotencyKey) {
                $existing = LedgerTransaction::where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    return $existing;
                }
            }

            $userWallet = $this->getOrCreateUserWallet($userId);
            $userWallet = Wallet::whereKey($userWallet->id)->lockForUpdate()->firstOrFail();

            if ($direction === 'debit' && (int) $userWallet->balance < $amount) {
                throw ValidationException::withMessages([
                    'amount' => 'Saldo user tidak cukup untuk debit',
                ]);
            }

            $tx = LedgerTransaction::create([
                'type' => 'ADJUST',
                'status' => 'SUCCESS',
                'idempotency_key' => $idempotencyKey,
                'note' => $note ?? 'Admin balance adjustment',
            ]);

            $ledgerDirection = $direction === 'debit' ? 'DEBIT' : 'CREDIT';

            [$before, $after] = $this->applyBalanceInt($userWallet, $ledgerDirection, $amount);

            $userWallet->update([
                'balance' => $after,
            ]);

            LedgerEntry::create([
                'ledger_transaction_id' => $tx->id,
                'wallet_id' => $userWallet->id,
                'direction' => $ledgerDirection,
                'amount' => $amount,
                'balance_before' => $before,
                'balance_after' => $after,
            ]);

            return $tx;
        });
    }

    // =========================
    // TRANSFER WALLET -> WALLET (generic)
    // =========================
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

            if ((int)$from->balance < $amount) {
                throw ValidationException::withMessages(['balance' => 'Saldo tidak cukup']);
            }

            $tx = LedgerTransaction::create([
                'type' => $type,
                'status' => 'SUCCESS',
                'idempotency_key' => $idempotencyKey,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'note' => $note,
            ]);

            // from DEBIT
            [$fbefore, $fafter] = $this->applyBalanceInt($from, 'DEBIT', $amount);
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
            [$tbefore, $tafter] = $this->applyBalanceInt($to, 'CREDIT', $amount);
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

    // =========================
    // CREDIT KOMISI REFERRAL -> WALLET KOMISI (IDR_COMMISSION)
    // =========================
    public function creditReferralCommissionToCommissionWallet(
        int $referrerUserId,
        int $commissionAmount,
        string $idempotencyKey,
        ?string $note = null,
        ?string $referenceType = 'referral_transaction',
        ?int $referenceId = null
    ): LedgerTransaction {
        if ($commissionAmount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Commission harus > 0']);
        }

        return DB::transaction(function () use (
            $referrerUserId, $commissionAmount, $idempotencyKey, $note, $referenceType, $referenceId
        ) {
            $existing = LedgerTransaction::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) return $existing;

            $wallet = $this->getOrCreateUserCommissionWallet($referrerUserId);
            $wallet = Wallet::whereKey($wallet->id)->lockForUpdate()->first();

            $tx = LedgerTransaction::create([
                'type' => 'REFERRAL',
                'status' => 'SUCCESS',
                'idempotency_key' => $idempotencyKey,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'note' => $note ?? 'Referral commission -> IDR_COMMISSION',
            ]);

            [$before, $after] = $this->applyBalanceInt($wallet, 'CREDIT', $commissionAmount);
            $wallet->update(['balance' => $after]);

            LedgerEntry::create([
                'ledger_transaction_id' => $tx->id,
                'wallet_id' => $wallet->id,
                'direction' => 'CREDIT',
                'amount' => $commissionAmount,
                'balance_before' => $before,
                'balance_after' => $after,
            ]);

            return $tx;
        });
    }

    // =========================
    // APPROVE WD KOMISI: pindahkan IDR_COMMISSION -> IDR
    // =========================
    public function approveWithdrawCommissionToMain(
        int $userId,
        int $amount,
        string $idempotencyKey,
        ?string $note = null,
        ?string $referenceType = 'withdraw_request',
        ?int $referenceId = null
    ): LedgerTransaction {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Amount harus > 0']);
        }

        $commissionWallet = $this->getOrCreateUserCommissionWallet($userId);
        $mainWallet = $this->getOrCreateUserWallet($userId);

        return $this->transferWalletToWallet(
            fromWalletId: (int) $commissionWallet->id,
            toWalletId: (int) $mainWallet->id,
            amount: (int) $amount,
            type: 'WITHDRAW',
            idempotencyKey: $idempotencyKey,
            note: $note ?? 'Approve withdraw: IDR_COMMISSION -> IDR',
            referenceType: $referenceType,
            referenceId: $referenceId
        );
    }
}