<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Referral;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
}
