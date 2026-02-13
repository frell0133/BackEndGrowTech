<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Referral;
use App\Models\User;
use App\Models\ReferralTransaction;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminReferralController extends Controller
{
    use ApiResponse;

    /**
     * ============================
     * A) RELATION LIST (attach)
     * ============================
     * GET /api/v1/admin/referrals
     *
     * Query:
     * - q=search email/name/referral_code (user atau referrer)
     * - user_id=filter by referred user (yang pakai kode)
     * - referrer_id=filter by referrer (pemilik kode)
     * - date_from=YYYY-MM-DD (filter locked_at/created_at)
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

        // IMPORTANT: di tabel kamu field nya "referred_by" bukan "referrer_id"
        if ($referrerId) {
            $query->where('referred_by', (int) $referrerId);
        }

        // Filter tanggal: pakai locked_at kalau ada, fallback created_at
        if ($dateFrom) {
            $query->where(function ($qr) use ($dateFrom) {
                $qr->whereDate('locked_at', '>=', $dateFrom)
                   ->orWhereDate('created_at', '>=', $dateFrom);
            });
        }

        if ($dateTo) {
            $query->where(function ($qr) use ($dateTo) {
                $qr->whereDate('locked_at', '<=', $dateTo)
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

        $data = $query->latest('id')->paginate($perPage);

        return $this->ok($data);
    }

    /**
     * ============================
     * B) MONITORING (mockup)
     * ============================
     * GET /api/v1/admin/referrals/monitoring
     *
     * Query:
     * - q=search referrer name/email/referral_code
     * - per_page=20
     */
    public function monitoring(Request $request)
    {
        $perPage = (int) $request->query('per_page', 20);
        $q = trim((string) $request->query('q', ''));

        // agregasi per referrer dari referral_transactions
        $base = ReferralTransaction::query()
            ->select([
                'referrer_id',
                DB::raw('COUNT(*)::int as total_referral'),
                DB::raw("SUM(CASE WHEN status='valid' THEN 1 ELSE 0 END)::int as valid"),
                DB::raw("SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END)::int as pending"),
                DB::raw("SUM(CASE WHEN status='invalid' THEN 1 ELSE 0 END)::int as invalid"),
                DB::raw('COALESCE(SUM(commission_amount),0)::int as total_komisi'),
            ])
            ->groupBy('referrer_id');

        $query = DB::query()->fromSub($base, 'agg')
            ->join('users as u', 'u.id', '=', 'agg.referrer_id')
            ->select([
                'u.id',
                'u.name',
                'u.email',
                'u.referral_code',
                'agg.total_referral',
                'agg.valid',
                'agg.pending',
                'agg.invalid',
                'agg.total_komisi',
            ])
            ->orderByDesc('agg.total_referral');

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('u.name', 'ilike', "%{$q}%")
                  ->orWhere('u.email', 'ilike', "%{$q}%")
                  ->orWhere('u.referral_code', 'ilike', "%{$q}%");
            });
        }

        return $this->ok($query->paginate($perPage));
    }

    /**
     * ============================
     * C) DETAIL (mockup)
     * ============================
     * GET /api/v1/admin/referrals/{referrer_id}/detail
     *
     * Query:
     * - status=valid|pending|invalid
     * - q=search buyer name/email
     * - per_page=20
     */
    public function detail(Request $request, int $referrer_id)
    {
        $perPage = (int) $request->query('per_page', 20);
        $status = $request->query('status');
        $q = trim((string) $request->query('q', ''));

        $referrer = User::query()->find($referrer_id);
        if (!$referrer) return $this->fail('User not found', 404);

        $summary = ReferralTransaction::query()
            ->where('referrer_id', $referrer->id)
            ->select([
                DB::raw('COUNT(*)::int as total'),
                DB::raw("SUM(CASE WHEN status='valid' THEN 1 ELSE 0 END)::int as valid"),
                DB::raw("SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END)::int as pending"),
                DB::raw("SUM(CASE WHEN status='invalid' THEN 1 ELSE 0 END)::int as invalid"),
                DB::raw('COALESCE(SUM(commission_amount),0)::int as total_komisi'),
            ])->first();

        $tx = ReferralTransaction::query()
            ->where('referrer_id', $referrer->id)
            ->with(['user:id,name,email']);

        if ($status) $tx->where('status', $status);

        if ($q !== '') {
            $tx->whereHas('user', function ($u) use ($q) {
                $u->where('name', 'ilike', "%{$q}%")
                  ->orWhere('email', 'ilike', "%{$q}%");
            });
        }

        $items = $tx->latest('id')->paginate($perPage);

        // map agar sesuai mockup table: Nama, Email, Status, Komisi, Tanggal
        $itemsArr = $items->toArray();
        $itemsArr['data'] = collect($items->items())->map(function ($row) {
            return [
                'name' => $row->user?->name,
                'email' => $row->user?->email,
                'status' => $row->status,
                'komisi' => (int) $row->commission_amount,
                'tanggal' => optional($row->occurred_at ?: $row->created_at)->toDateString(),
            ];
        })->values();

        return $this->ok([
            'referrer' => [
                'id' => $referrer->id,
                'name' => $referrer->name,
                'email' => $referrer->email,
                'referral_code' => $referrer->referral_code,
            ],
            'summary' => [
                'total' => (int) ($summary->total ?? 0),
                'valid' => (int) ($summary->valid ?? 0),
                'pending' => (int) ($summary->pending ?? 0),
                'invalid' => (int) ($summary->invalid ?? 0),
                'total_komisi' => (int) ($summary->total_komisi ?? 0),
            ],
            'items' => $itemsArr,
        ]);
    }

    /**
     * POST /api/v1/admin/referrals/{user_id}/force-unlock
     * Reset referral untuk user yang sudah attach (bisa attach ulang).
     */
    public function forceUnlock(Request $request, string $user_id)
    {
        $user = User::query()->find($user_id);
        if (!$user) return $this->fail('User not found', 404);

        $ref = Referral::query()->where('user_id', $user->id)->first();

        if (!$ref) {
            return $this->ok([
                'message' => 'No referral found for this user (already unlocked).',
                'user_id' => $user->id,
            ]);
        }

        $ref->delete();

        return $this->ok([
            'message' => 'Referral unlocked (deleted). User can attach again.',
            'user_id' => $user->id,
        ]);
    }
}
