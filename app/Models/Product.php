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
        'tier_profit',
        'duration_days',
        'is_active',
        'is_published',
        'price',
        'track_stock',
        'stock_min_alert',
        'rating',       
        'rating_count',   
        'purchases_count',
        'popularity_score',
    ];

    protected $casts = [
        'tier_pricing' => 'array',
        'tier_profit' => 'array',
        'is_active' => 'boolean',
        'is_published' => 'boolean',
        'duration_days' => 'integer',
        'price' => 'integer',
        'track_stock' => 'boolean',
        'stock_min_alert' => 'integer',
        'rating' => 'float',         
        'rating_count' => 'integer',  
        'purchases_count' => 'integer',
        'popularity_score' => 'float',
    ];

    public function licenses()
    {
        return $this->hasMany(\App\Models\License::class);
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

    public function favorites()
    {
        return $this->hasMany(\App\Models\Favorite::class);
    }

    public function productRatings(): HasMany
    {
        return $this->hasMany(\App\Models\ProductRating::class);
    }
    
}
