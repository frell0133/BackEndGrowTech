<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Referral;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\ReferralSetting;
use App\Models\ReferralTransaction;


class UserReferralController extends Controller
{
    use ApiResponse;

    public function dashboard(Request $request)
    {
        $user = $request->user();
        if (!$user) return $this->fail('Unauthenticated', 401);

        $ref = Referral::query()
            ->with(['referrer:id,name,email,referral_code'])
            ->where('user_id', $user->id)
            ->first();

        return $this->ok([
            'my_referral_code' => $user->referral_code,
            'relation' => $ref,
        ]);
    }

    public function attach(Request $request)
    {
        $user = $request->user();
        if (!$user) return $this->fail('Unauthenticated', 401);

        $data = $request->validate([
            'code' => ['required','string','max:50'],
        ]);

        $code = strtoupper(trim($data['code']));

        // cari pemilik kode
        $referrer = User::query()
            ->where('referral_code', $code)
            ->first();

        if (!$referrer) return $this->fail('Referral code tidak valid', 422);
        if ($referrer->id === $user->id) return $this->fail('Tidak bisa pakai referral code sendiri', 422);

        return DB::transaction(function () use ($user, $referrer) {

            // kalau sudah pernah attach & sudah locked -> tolak
            $existing = Referral::query()->where('user_id', $user->id)->lockForUpdate()->first();
            if ($existing && $existing->locked_at) {
                return $this->fail('Referral sudah terkunci (hanya bisa sekali)', 409);
            }

            // create / update
            $ref = Referral::updateOrCreate(
                ['user_id' => $user->id],
                ['referred_by' => $referrer->id, 'locked_at' => now()]
            );

            return $this->ok([
                'message' => 'Referral berhasil dipasang',
                'referral' => $ref,
                'referrer' => $referrer->only('id','name','email','referral_code'),
            ]);
        });
    }
    public function previewDiscount(Request $request)
    {
        $user = $request->user();
        if (!$user) return $this->fail('Unauthenticated', 401);

        $data = $request->validate([
            'amount' => ['required','integer','min:0'],
        ]);

        $amount = (int) $data['amount'];

        $settings = ReferralSetting::current();
        if (!$settings->isActiveNow()) {
            return $this->ok([
                'eligible' => false,
                'reason' => 'Referral campaign tidak aktif / sudah expired',
                'amount' => $amount,
                'discount_amount' => 0,
                'final_amount' => $amount,
                'settings' => $settings,
            ]);
        }

        // pastikan user sudah attach referral (locked)
        $relation = Referral::query()
            ->where('user_id', $user->id)
            ->first();

        if (!$relation || !$relation->locked_at) {
            return $this->ok([
                'eligible' => false,
                'reason' => 'User belum attach referral code',
                'amount' => $amount,
                'discount_amount' => 0,
                'final_amount' => $amount,
                'settings' => $settings,
            ]);
        }

        // minimal order
        if ($amount < (int) $settings->min_order_amount) {
            return $this->ok([
                'eligible' => false,
                'reason' => 'Minimal order belum terpenuhi',
                'amount' => $amount,
                'discount_amount' => 0,
                'final_amount' => $amount,
                'settings' => $settings,
            ]);
        }

        // limit penggunaan per user (berdasarkan referral_transactions valid/pending)
        $usedByUser = ReferralTransaction::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['pending','valid'])
            ->count();

        if ($settings->max_uses_per_user > 0 && $usedByUser >= (int) $settings->max_uses_per_user) {
            return $this->ok([
                'eligible' => false,
                'reason' => 'Limit penggunaan referral untuk user sudah habis',
                'amount' => $amount,
                'discount_amount' => 0,
                'final_amount' => $amount,
                'settings' => $settings,
            ]);
        }

        // hitung diskon
        $discount = 0;

        if ($settings->discount_type === 'percent') {
            $discount = (int) floor($amount * ((int) $settings->discount_value) / 100);
        } else { // fixed
            $discount = (int) $settings->discount_value;
        }

        // max diskon
        if ((int) $settings->discount_max_amount > 0) {
            $discount = min($discount, (int) $settings->discount_max_amount);
        }

        // diskon tidak boleh lebih dari amount
        $discount = min($discount, $amount);

        return $this->ok([
            'eligible' => true,
            'reason' => null,
            'amount' => $amount,
            'discount_amount' => $discount,
            'final_amount' => max(0, $amount - $discount),
            'referrer_id' => (int) $relation->referred_by,
            'settings' => $settings,
        ]);
    }
}
