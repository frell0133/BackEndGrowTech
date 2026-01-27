<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LedgerEntry extends Model
{
    protected $fillable = [
        'ledger_transaction_id', 'wallet_id', 'direction',
        'amount', 'balance_before', 'balance_after',
    ];

    public function transaction()
    {
        return $this->belongsTo(LedgerTransaction::class, 'ledger_transaction_id');
    }

    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }
}
