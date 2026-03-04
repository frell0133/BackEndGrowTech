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

    // Wallet komisi/referral (saldo withdraw)
    public function getOrCreateUserCommissionWallet(int $userId): Wallet
    {
        return Wallet::firstOrCreate(
            ['user_id' => $userId, 'currency' => 'IDR_COMMISSION'],
            ['balance' => 0, 'status' => 'ACTIVE']
        );
    }

    // System wallet by code (kalau masih dipakai)
    public function getSystemWallet(string $code): Wallet
    {
        $wallet = Wallet::where('code', $code)->first();
        if (!$wallet) {
            throw ValidationException::withMessages(['wallet' => "System wallet {$code} belum ada. Jalankan seeder."]);
        }
        return $wallet;
    }

    // =========================
    // Balance helper (FLOAT SAFE)
    // =========================
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

    // =========================
    // TOPUP: wallet utama user CREDIT (opsional system cash DEBIT)
    // Signature disesuaikan dengan controller kamu
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

            // system cash DEBIT
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

    // =========================
    // PURCHASE: wallet utama user DEBIT
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

    // =========================
    // ADMIN TOPUP: wallet utama CREDIT only
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

    // =========================
    // TRANSFER WALLET -> WALLET (umum)
    // type harus enum valid: TOPUP/PURCHASE/WITHDRAW/REFERRAL/ADJUST/REFUND
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

            if ((float)$from->balance < (float)$amount) {
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

            [$before, $after] = $this->applyBalance($wallet, 'CREDIT', $commissionAmount);
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
    // APPROVE WITHDRAW: pindahkan IDR_COMMISSION -> IDR (wallet belanja)
    // =========================
    public function approveWithdrawCommissionToMain(
        int $userId,
        int $amount,
        string $idempotencyKey,
        ?string $note = null,
        ?string $referenceType = 'withdraw_request',
        ?int $referenceId = null
    ): LedgerTransaction {
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

    public function getOrCreateSystemWallet(string $currency = 'IDR')
    {
        // Sesuaikan field & model jika di project kamu nama kolomnya beda.
        // Umumnya ada Wallet model dengan owner_type/owner_id atau is_system flag.
        return \App\Models\Wallet::firstOrCreate([
            'owner_type' => 'system',
            'owner_id' => 0,
            'currency' => $currency,
        ], [
            'balance' => 0,
        ]);
    }

    /**
     * Approve WD: pindahkan saldo dari wallet utama user (IDR) ke wallet system (GROWTECH)
     */
    public function approveWithdrawToSystemWallet(
        int $userId,
        int $amount,
        string $idempotencyKey,
        string $note,
        string $referenceType,
        int $referenceId,
        string $currency = 'IDR'
    ): void {
        if ($amount <= 0) return;

        // ✅ idempotent
        $exists = \App\Models\LedgerEntry::query()
            ->where('idempotency_key', $idempotencyKey)
            ->exists();
        if ($exists) return;

        $userWallet = $this->getOrCreateUserWallet($userId);
        $systemWallet = $this->getOrCreateSystemWallet($currency);

        // ✅ pastikan saldo cukup (double safety)
        if ((float)$userWallet->balance < (float)$amount) {
            throw new \RuntimeException('Saldo user tidak cukup untuk approve WD');
        }

        // debit user
        $userWallet->balance = (float)$userWallet->balance - (float)$amount;
        $userWallet->save();

        // credit system
        $systemWallet->balance = (float)$systemWallet->balance + (float)$amount;
        $systemWallet->save();

        // catat ledger (2 entry atau 1 entry sesuai schema kamu)
        \App\Models\LedgerEntry::create([
            'wallet_id' => $userWallet->id,
            'direction' => 'debit',
            'amount' => (float)$amount,
            'note' => $note,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'idempotency_key' => $idempotencyKey,
            'meta' => ['to' => 'system', 'currency' => $currency],
        ]);

        \App\Models\LedgerEntry::create([
            'wallet_id' => $systemWallet->id,
            'direction' => 'credit',
            'amount' => (float)$amount,
            'note' => $note,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'idempotency_key' => $idempotencyKey . ':SYS',
            'meta' => ['from_user_id' => $userId, 'currency' => $currency],
        ]);
    }
}