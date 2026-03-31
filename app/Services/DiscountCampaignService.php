<?php

namespace App\Services;

use App\Models\DiscountCampaign;
use App\Models\User;
use App\Support\UserTierEligibility;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DiscountCampaignService
{
    public function compute(int $userId, array $items, float $orderSubtotal): array
    {
        $now = Carbon::now();
        $userTier = (string) (User::query()->whereKey($userId)->value('tier') ?? User::TIER_MEMBER);

        $campaigns = DiscountCampaign::query()
            ->with('targets:id,campaign_id,target_type,target_id')
            ->where('enabled', true)
            ->where(function ($q) use ($now) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            })
            ->where(function ($q) use ($orderSubtotal) {
                $q->whereNull('min_order_amount')->orWhere('min_order_amount', '<=', (int) round($orderSubtotal));
            })
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->get();

        $applicable = [];

        foreach ($campaigns as $c) {
            if (!UserTierEligibility::discountCampaignAllowed($c, $userTier)) {
                continue;
            }

            $targets = $c->targets;
            $matchProduct = $targets->where('target_type', 'product')->pluck('target_id')->map(fn ($v) => (int) $v)->all();
            $matchSub = $targets->where('target_type', 'subcategory')->pluck('target_id')->map(fn ($v) => (int) $v)->all();
            $matchCat = $targets->where('target_type', 'category')->pluck('target_id')->map(fn ($v) => (int) $v)->all();

            $isGlobal = $targets->isEmpty();
            $matchedSubtotal = 0.0;

            foreach ($items as $it) {
                $pid = (int) $it['product_id'];
                $sid = isset($it['subcategory_id']) ? (int) $it['subcategory_id'] : null;
                $cid = isset($it['category_id']) ? (int) $it['category_id'] : null;

                $hit = $isGlobal
                    || in_array($pid, $matchProduct, true)
                    || ($sid !== null && in_array($sid, $matchSub, true))
                    || ($cid !== null && in_array($cid, $matchCat, true));

                if ($hit) {
                    $matchedSubtotal += (float) $it['line_subtotal'];
                }
            }

            if ($matchedSubtotal <= 0) {
                continue;
            }

            $discount = 0.0;
            if ($c->discount_type === 'percent') {
                $discount = $matchedSubtotal * ((float) $c->discount_value / 100.0);
            } else {
                $discount = min((float) $c->discount_value, $matchedSubtotal);
            }

            if ($c->max_discount_amount !== null) {
                $discount = min($discount, (float) $c->max_discount_amount);
            }

            if ($c->usage_limit_total !== null) {
                $usedTotal = DB::table('order_discount_campaigns as odc')
                    ->join('orders as o', 'o.id', '=', 'odc.order_id')
                    ->where('odc.campaign_id', $c->id)
                    ->whereIn('o.status', ['paid', 'fulfilled'])
                    ->count();

                if ($usedTotal >= (int) $c->usage_limit_total) {
                    continue;
                }
            }

            if ($c->usage_limit_per_user !== null) {
                $usedUser = DB::table('order_discount_campaigns as odc')
                    ->join('orders as o', 'o.id', '=', 'odc.order_id')
                    ->where('odc.campaign_id', $c->id)
                    ->where('o.user_id', $userId)
                    ->whereIn('o.status', ['paid', 'fulfilled'])
                    ->count();

                if ($usedUser >= (int) $c->usage_limit_per_user) {
                    continue;
                }
            }

            $applicable[] = [
                'id' => (int) $c->id,
                'name' => (string) $c->name,
                'stack_policy' => (string) $c->stack_policy,
                'discount_amount' => (float) $discount,
            ];
        }

        if (empty($applicable)) {
            return ['total_discount' => 0.0, 'applied' => []];
        }

        $exclusive = array_values(array_filter($applicable, fn ($a) => $a['stack_policy'] === 'exclusive'));
        if (!empty($exclusive)) {
            usort($exclusive, fn ($a, $b) => $b['discount_amount'] <=> $a['discount_amount']);
            $picked = $exclusive[0];
            return [
                'total_discount' => (float) $picked['discount_amount'],
                'applied' => [$picked],
            ];
        }

        $total = 0.0;
        foreach ($applicable as $a) {
            $total += (float) $a['discount_amount'];
        }

        return [
            'total_discount' => (float) $total,
            'applied' => $applicable,
        ];
    }
}
