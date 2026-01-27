<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = [
        'order_id',
        'gateway_code',
        'external_id',
        'amount',
        'status',
        'raw_callback',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'raw_callback' => 'array',
        'status' => PaymentStatus::class,
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function gateway(): BelongsTo
    {
        // connect by gateway_code => payment_gateways.code
        return $this->belongsTo(PaymentGateway::class, 'gateway_code', 'code');
    }
}
