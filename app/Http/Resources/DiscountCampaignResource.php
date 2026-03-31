<?php

namespace App\Http\Resources;

use App\Support\UserTierEligibility;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DiscountCampaignResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $targets = $this->targets ?? collect();
        $subTarget = $targets->firstWhere('target_type', 'subcategory');

        $subcategory = null;
        $category = null;

        if ($subTarget && isset($this->resolved_subcategory)) {
            $subcategory = $this->resolved_subcategory;
            $category = $subcategory?->category;
        }

        $nominal = $this->discount_type === 'percent'
            ? ((int) $this->discount_value) . '%'
            : 'Rp ' . number_format((int) $this->discount_value, 0, ',', '.');

        $isActiveNow = (bool) $this->enabled
            && ($this->starts_at === null || $this->starts_at <= now())
            && ($this->ends_at === null || $this->ends_at >= now());

        $tierRules = UserTierEligibility::tierSummaryFromRules(is_array($this->tier_rules) ? $this->tier_rules : []);

        $tierSummary = 'Semua tier';
        if (!empty($tierRules['allowed_tiers'])) {
            $tierSummary = 'Hanya: ' . strtoupper(implode(', ', $tierRules['allowed_tiers']));
        } elseif (!empty($tierRules['excluded_tiers'])) {
            $tierSummary = 'Kecuali: ' . strtoupper(implode(', ', $tierRules['excluded_tiers']));
        }

        return [
            'id' => $this->id,
            'nama_discount' => $this->name,
            'nominal' => $nominal,
            'discount_type' => $this->discount_type,
            'discount_value' => (int) $this->discount_value,
            'kategori_produk' => $category?->name ?? '-',
            'sub_kategori' => $subcategory?->name ?? '-',
            'status' => $isActiveNow ? 'Aktif' : 'Nonaktif',
            'enabled' => (bool) $this->enabled,
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'min_order_amount' => $this->min_order_amount,
            'max_discount_amount' => $this->max_discount_amount,
            'priority' => (int) $this->priority,
            'stack_policy' => $this->stack_policy,
            'tier_rules' => $tierRules,
            'tier_summary' => $tierSummary,
            'targets' => $targets->map(fn ($t) => [
                'type' => $t->target_type,
                'id' => (int) $t->target_id,
            ])->values(),
        ];
    }
}
