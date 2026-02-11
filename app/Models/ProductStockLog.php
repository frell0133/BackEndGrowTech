<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductStockLog extends Model
{
  protected $fillable = ['product_stock_id','actor_id','action','meta'];
  protected $casts = ['meta' => 'array'];

  public function stock(): BelongsTo
  {
    return $this->belongsTo(ProductStock::class, 'product_stock_id');
  }
}

