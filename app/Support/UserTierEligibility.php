<?php

namespace App\Support;

use App\Models\DiscountCampaign;
use App\Models\User;
use App\Models\Voucher;

class UserTierEligibility
{
    public static function normalizeTier(?string $tier): string
    {
        $normalized = strtolower(trim((string) $tier));

        if ($normalized === '') {
            return User::TIER_MEMBER;
        }

        return in_array($normalized, User::allowedTiers(), true)
            ? $normalized
            : User::TIER_MEMBER;
    }

    public static function sanitizeTiers(array $tiers): array
    {
        $allowed = User::allowedTiers();
        $normalized = [];

        foreach ($tiers as $tier) {
            $safe = self::normalizeTier(is_string($tier) ? $tier : null);

            if (in_array($safe, $allowed, true) && !in_array($safe, $normalized, true)) {
                $normalized[] = $safe;
            }
        }

        return array_values($normalized);
    }

    public static function isReferralTierAllowed(?string $tier): bool
    {
        return self::normalizeTier($tier) === User::TIER_MEMBER;
    }

    public static function referralTierMessage(?string $tier): string
    {
        $safeTier = strtoupper(self::normalizeTier($tier));

        return "Referral tidak tersedia untuk user tier {$safeTier}.";
    }

    public static function voucherAllowed(Voucher $voucher, ?string $tier): bool
    {
        $rules = is_array($voucher->rules) ? $voucher->rules : [];

        return self::passesTierRules(
            self::normalizeTier($tier),
            self::sanitizeTiers((array) ($rules['allowed_tiers'] ?? [])),
            self::sanitizeTiers((array) ($rules['excluded_tiers'] ?? [])),
        );
    }

    public static function voucherMessage(Voucher $voucher, ?string $tier): string
    {
        $safeTier = strtoupper(self::normalizeTier($tier));

        return "Voucher ini tidak berlaku untuk user tier {$safeTier}.";
    }

    public static function discountCampaignAllowed(DiscountCampaign $campaign, ?string $tier): bool
    {
        $rules = is_array($campaign->tier_rules) ? $campaign->tier_rules : [];

        return self::passesTierRules(
            self::normalizeTier($tier),
            self::sanitizeTiers((array) ($rules['allowed_tiers'] ?? [])),
            self::sanitizeTiers((array) ($rules['excluded_tiers'] ?? [])),
        );
    }

    public static function tierSummaryFromRules(?array $rules): array
    {
        $safeRules = is_array($rules) ? $rules : [];

        return [
            'allowed_tiers' => self::sanitizeTiers((array) ($safeRules['allowed_tiers'] ?? [])),
            'excluded_tiers' => self::sanitizeTiers((array) ($safeRules['excluded_tiers'] ?? [])),
        ];
    }

    public static function passesTierRules(string $tier, array $allowedTiers = [], array $excludedTiers = []): bool
    {
        $safeTier = self::normalizeTier($tier);

        if (!empty($allowedTiers) && !in_array($safeTier, $allowedTiers, true)) {
            return false;
        }

        if (!empty($excludedTiers) && in_array($safeTier, $excludedTiers, true)) {
            return false;
        }

        return true;
    }
}
