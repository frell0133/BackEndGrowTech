<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiscountCampaignTarget extends Model
{
    protected $table = 'discount_campaign_targets';

    protected $fillable = [
        'campaign_id',
        'target_type', // 'subcategory' | 'product'
        'target_id',
    ];

    protected $casts = [
        'campaign_id' => 'integer',
        'target_id' => 'integer',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(DiscountCampaign::class, 'campaign_id');
    }
}
