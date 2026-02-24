<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Order extends Model
{
    protected $fillable = [
        'user_id',
        'product_id', // legacy (nullable)
        'invoice_number',
        'status',
        'qty', // legacy (nullable)
        'amount',
        'subtotal',
        'discount_total',
        'tax_percent',
        'tax_amount',
        'payment_gateway_code',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'tax_percent' => 'integer',
        'status' => OrderStatus::class,
        'invoice_emailed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // legacy (boleh tetap ada)
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(\App\Models\OrderItem::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    public function delivery(): HasOne
    {
        return $this->hasOne(Delivery::class);
    }

    public function vouchers(): BelongsToMany
    {
        return $this->belongsToMany(Voucher::class, 'order_vouchers')
            ->withPivot(['discount_amount'])
            ->withTimestamps();
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(\App\Models\Delivery::class);
    }
}
