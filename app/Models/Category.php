<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'redirect_link',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function subcategories(): HasMany
    {
        return $this->hasMany(SubCategory::class, 'category_id');
    }
    
    public function products(): HasMany
    {
        return $this->hasMany(\App\Models\Product::class, 'category_id');
    }
}
