<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletTopup extends Model
{
    protected $fillable = [
        'user_id',
        'order_id',
        'amount',
        'currency',
        'status',
        'snap_token',
        'redirect_url',
        'external_id',
        'raw_callback',
        'posted_to_ledger_at',
    ];

    protected $casts = [
        'raw_callback' => 'array',
        'posted_to_ledger_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
