<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReferralSetting extends Model
{
    protected $fillable = [
        'enabled',
        'discount_type','discount_value','discount_max_amount',
        'min_order_amount',
        'commission_type','commission_value',
        'max_uses_per_referrer','max_uses_per_user',
        'min_withdrawal',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public static function current(): self
    {
        return static::query()->first() ?? static::query()->create([]);
    }
}
