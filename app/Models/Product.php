<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Category;
use App\Models\Subcategory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    protected $fillable = [
        'name',
        'type',
        'description',
        'tier_pricing',
    ];

    protected $casts = [
        'tier_pricing' => 'array',
        'is_active' => 'boolean',
        'is_published' => 'boolean',
    ];

    public function licenses(): HasMany
    {
        return $this->hasMany(License::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

        public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(Subcategory::class, 'subcategory_id');
    }
    
}
