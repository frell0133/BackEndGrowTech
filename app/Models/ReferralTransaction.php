<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferralTransaction extends Model
{
    protected $fillable = [
        'referrer_id','user_id','order_id',
        'status','order_amount','discount_amount','commission_amount',
        'occurred_at',
    ];

    protected $casts = [
        'referrer_id' => 'integer',
        'user_id' => 'integer',
        'order_id' => 'integer',
        'order_amount' => 'integer',
        'discount_amount' => 'integer',
        'commission_amount' => 'integer',
        'occurred_at' => 'datetime',
    ];

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}