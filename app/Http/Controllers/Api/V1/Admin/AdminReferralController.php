<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Referral;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class AdminReferralController extends Controller
{
    use ApiResponse;

    /**
     * GET /api/v1/admin/referrals
     *
     * Query:
     * - q=search email/name/referral_code (user atau referrer)
     * - user_id=filter by referred user
     * - referrer_id=filter by referrer
     * - date_from=YYYY-MM-DD (filter attached_at/created_at)
     * - date_to=YYYY-MM-DD
     * - per_page=20
     */
    public function index(Request $request)
    {
        $perPage = (int) $request->query('per_page', 20);
        $q = trim((string) $request->query('q', ''));
        $userId = $request->query('user_id');
        $referrerId = $request->query('referrer_id');
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');

        $query = Referral::query()
            ->with([
                'user:id,name,email,referral_code,created_at',
                'referrer:id,name,email,referral_code',
            ]);

        if ($userId) {
            $query->where('user_id', (int) $userId);
        }

        if ($referrerId) {
            $query->where('referrer_id', (int) $referrerId);
        }

        // kalau tabel referral kamu pakai attached_at, pakai itu. kalau tidak ada, pakai created_at.
        if ($dateFrom) {
            $query->where(function ($qr) use ($dateFrom) {
                $qr->whereDate('attached_at', '>=', $dateFrom)
                   ->orWhereDate('created_at', '>=', $dateFrom);
            });
        }

        if ($dateTo) {
            $query->where(function ($qr) use ($dateTo) {
                $qr->whereDate('attached_at', '<=', $dateTo)
                   ->orWhereDate('created_at', '<=', $dateTo);
            });
        }

        // Search user/referrer
        if ($q !== '') {
            $query->where(function ($qr) use ($q) {
                $qr->whereHas('user', function ($u) use ($q) {
                        $u->where('email', 'ilike', "%{$q}%")
                          ->orWhere('name', 'ilike', "%{$q}%")
                          ->orWhere('referral_code', 'ilike', "%{$q}%");
                    })
                   ->orWhereHas('referrer', function ($r) use ($q) {
                        $r->where('email', 'ilike', "%{$q}%")
                          ->orWhere('name', 'ilike', "%{$q}%")
                          ->orWhere('referral_code', 'ilike', "%{$q}%");
                   });
            });
        }

        $data = $query
            ->latest('id')
            ->paginate($perPage);

        return $this->ok($data);
    }

    /**
     * POST /api/v1/admin/referrals/{user_id}/force-unlock
     * Reset referral untuk user yang sudah attach (bisa attach ulang).
     *
     * Default behavior:
     * - delete row referral user tsb (paling bersih)
     * - alternatif: set referrer_id null + unlock flag
     */
    public function forceUnlock(Request $request, string $user_id)
    {
        $user = User::query()->find($user_id);
        if (!$user) {
            return $this->fail('User not found', 404);
        }

        $ref = Referral::query()->where('user_id', $user->id)->first();

        if (!$ref) {
            return $this->ok([
                'message' => 'No referral found for this user (already unlocked).',
                'user_id' => $user->id,
            ]);
        }

        // MODE PALING AMAN: hapus record referral supaya user bisa attach lagi
        $ref->delete();

        return $this->ok([
            'message' => 'Referral unlocked (deleted). User can attach again.',
            'user_id' => $user->id,
        ]);
    }
}
