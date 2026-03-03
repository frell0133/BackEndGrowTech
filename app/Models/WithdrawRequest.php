<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WithdrawRequest extends Model
{
    protected $fillable = [
        'user_id',
        'amount',
        'status',
        'payout_details',
        'processed_at',

        'approved_at',
        'rejected_at',
        'paid_at',
        'reject_reason',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payout_details' => 'array',
        'processed_at' => 'datetime',

        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'paid_at' => 'datetime'
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
