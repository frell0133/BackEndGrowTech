<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Delivery extends Model
{
    protected $fillable = [
        'order_id','license_id',
        'delivery_mode','emailed_at',
        'revealed_at','reveal_count',
    ];

    protected $casts = [
        'revealed_at' => 'datetime',
        'emailed_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }
}
