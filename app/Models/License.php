<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class License extends Model
{
    public const STATUS_AVAILABLE = 'available';
    public const STATUS_TAKEN = 'taken';
    public const STATUS_RESERVED = 'reserved';
    public const STATUS_SOLD = 'sold';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_USED = 'used';
    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'product_id',
        'license_key',
        'data_other',
        'note',
        'fingerprint',
        'status',
        'taken_by',
        'taken_at',
        'reserved_order_id',
        'reserved_at',
        'delivered_at',
        'order_id',
        'sold_at',
    ];

    protected $casts = [
        'taken_at' => 'datetime',
        'reserved_at' => 'datetime',
        'delivered_at' => 'datetime',
        'sold_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function taker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'taken_by');
    }
}
