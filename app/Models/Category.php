<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
  protected $fillable = [
    'subcategory_id',
    'name',
    'slug',
    'type',
    'duration_days',
    'description',
    'tier_pricing',
    'is_active',
    'is_published',
    'track_stock',
    'stock_min_alert',
  ];

  protected $casts = [
    'tier_pricing' => 'array',
    'is_active' => 'boolean',
    'is_published' => 'boolean',
    'track_stock' => 'boolean',
  ];

  public function subcategory(): BelongsTo
  {
    return $this->belongsTo(SubCategory::class, 'subcategory_id');
  }

  public function stocks(): HasMany
  {
    return $this->hasMany(ProductStock::class);
  }
}

