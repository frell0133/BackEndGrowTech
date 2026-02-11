<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductStock extends Model
{
  protected $fillable = [
    'product_id','stock_data','fingerprint','status',
    'taken_by','taken_at',
    'reserved_order_id','reserved_at',
    'delivered_at',
  ];

  protected $casts = [
    'taken_at' => 'datetime',
    'reserved_at' => 'datetime',
    'delivered_at' => 'datetime',
  ];

  public function product(): BelongsTo
  {
    return $this->belongsTo(Product::class);
  }

  public function logs(): HasMany
  {
    return $this->hasMany(ProductStockLog::class);
  }
}

