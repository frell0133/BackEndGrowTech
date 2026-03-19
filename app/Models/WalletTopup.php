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
        'gateway_fee_percent',
        'gateway_fee_amount',
        'currency',
        'status',
        'gateway_code',
        'external_id',
        'snap_token',
        'redirect_url',
        'raw_callback',
        'posted_to_ledger_at',
        'paid_at',
        'invoice_emailed_at',
        'invoice_email_error',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'gateway_fee_percent' => 'decimal:3',
        'gateway_fee_amount' => 'decimal:2',
        'raw_callback' => 'array',
        'posted_to_ledger_at' => 'datetime',
        'paid_at' => 'datetime',
        'invoice_emailed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(PaymentGateway::class, 'gateway_code', 'code');
    }
}