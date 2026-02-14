<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ReferralSetting;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class AdminReferralSettingsController extends Controller
{
    use ApiResponse;

    public function show()
    {
        return $this->ok(ReferralSetting::current());
    }

    public function update(Request $request)
    {
        $s = ReferralSetting::current();

        $data = $request->validate([
            'enabled' => ['nullable','boolean'],

            'campaign_name' => ['nullable','string','max:100'],
            'starts_at' => ['nullable','date'],
            'ends_at' => ['nullable','date','after_or_equal:starts_at'],

            'discount_type' => ['nullable','in:percent,fixed'],
            'discount_value' => ['nullable','integer','min:0'],
            'discount_max_amount' => ['nullable','integer','min:0'],
            'min_order_amount' => ['nullable','integer','min:0'],

            'commission_type' => ['nullable','in:percent,fixed'],
            'commission_value' => ['nullable','integer','min:0'],
            'max_commission_total_per_referrer' => ['nullable','integer','min:0'],
            
            'max_uses_per_referrer' => ['nullable','integer','min:0'],
            'max_uses_per_user' => ['nullable','integer','min:0'],

            'min_withdrawal' => ['nullable','integer','min:0'],
        ]);

        $s->fill($data);
        $s->save();

        return $this->ok($s);
    }
}
