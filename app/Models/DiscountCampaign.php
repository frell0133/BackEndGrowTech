<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class DiscountCampaign extends Model
{
    protected $table = 'discount_campaigns';

    protected $fillable = [
        'name',
        'slug',
        'enabled',
        'starts_at',
        'ends_at',
        'discount_type',
        'discount_value',
        'max_discount_amount',
        'min_order_amount',
        'priority',
        'stack_policy',
        'usage_limit_total',
        'usage_limit_per_user',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'discount_value' => 'integer',
        'max_discount_amount' => 'integer',
        'min_order_amount' => 'integer',
        'priority' => 'integer',
        'usage_limit_total' => 'integer',
        'usage_limit_per_user' => 'integer',
    ];

    public function targets(): HasMany
    {
        return $this->hasMany(DiscountCampaignTarget::class, 'campaign_id');
    }

    protected static function booted(): void
    {
        static::creating(function (self $m) {
            if (!$m->slug && $m->name) {
                $m->slug = Str::slug($m->name);
            }
        });

        static::updating(function (self $m) {
            if (!$m->slug && $m->name) {
                $m->slug = Str::slug($m->name);
            }
        });
    }
}
