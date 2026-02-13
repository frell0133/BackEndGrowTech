<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Voucher extends Model
{
    protected $fillable = [
        'code',
        'type',
        'value',
        'quota',
        'min_purchase',
        'expires_at',
        'rules',
        'is_active',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'min_purchase' => 'decimal:2',
        'expires_at' => 'datetime',
        'rules' => 'array',
        'is_active' => 'boolean',
    ];

    public function orders(): BelongsToMany
    {
        return $this->belongsToMany(Order::class, 'order_vouchers')
            ->withPivot(['discount_amount'])
            ->withTimestamps();
    }
    
    protected static function booted(): void
    {
        static::creating(function ($voucher) {
            if (!empty($voucher->code)) return;

            do {
                $voucher->code = 'PROMO-' . Str::upper(Str::random(8)); // contoh: PROMO-8KDJ2LQA
            } while (static::where('code', $voucher->code)->exists());
        });
    }
}
