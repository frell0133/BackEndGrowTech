<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    protected $fillable = [
        'subcategory_id',
        'category_id',
        'name',
        'slug',
        'type',
        'description',
        'tier_pricing',
        'duration_days',
        'is_active',
        'is_published',
        'price',
    ];

    protected $casts = [
        'tier_pricing' => 'array',
        'is_active' => 'boolean',
        'is_published' => 'boolean',
        'duration_days' => 'integer',
        'price' => 'integer',
    ];

    public function licenses(): HasMany
    {
        return $this->hasMany(License::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(SubCategory::class, 'subcategory_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }
}
