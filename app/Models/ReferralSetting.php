<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReferralSetting extends Model
{
    protected $fillable = [
        'enabled',

        'campaign_name',
        'starts_at',
        'ends_at',

        'discount_type','discount_value','discount_max_amount',
        'min_order_amount',

        'commission_type','commission_value',
        'max_commission_total_per_referrer',

        'max_uses_per_referrer','max_uses_per_user',
        'min_withdrawal',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public static function current(): self
    {
        return static::query()->first() ?? static::query()->create([
            'enabled' => true,
            'campaign_name' => null,
            'starts_at' => null,
            'ends_at' => null,
            'max_commission_total_per_referrer' => 0,
        ]);
    }

    public function isActiveNow(): bool
    {
        if (!$this->enabled) return false;

        $now = now();
        if ($this->starts_at && $now->lt($this->starts_at)) return false;
        if ($this->ends_at && $now->gt($this->ends_at)) return false;

        return true;
    }
}
