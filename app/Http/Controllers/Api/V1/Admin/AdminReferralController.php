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
     * - referrer_id=filter by referrer (pemilik kode)  -> field DB: referred_by
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

        return $this->ok($query->latest('id')->paginate($perPage));
    }

    /**
     * ============================
     * B) MONITORING (rekap per referrer)
     * ============================
     * GET /api/v1/admin/referrals/monitoring
     *
     * Query:
     * - q=search referrer name/email/referral_code
     * - per_page=20
     *
     * Return:
     * - total_orders_used (jumlah transaksi referral)
     * - total_users_used (jumlah buyer unik)
     * - valid/pending/invalid
     * - total_komisi
     * - total_discount
     */
    public function monitoring(Request $request)
    {
        $perPage = (int) $request->query('per_page', 20);
        $q = trim((string) $request->query('q', ''));

        // 1) agregasi transaksi referral
        $txAgg = ReferralTransaction::query()
            ->select([
                'referrer_id',
                DB::raw('COUNT(*)::int as total_orders_used'),
                DB::raw('COUNT(DISTINCT user_id)::int as total_users_used'),
                DB::raw("SUM(CASE WHEN status='valid' THEN 1 ELSE 0 END)::int as valid"),
                DB::raw("SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END)::int as pending"),
                DB::raw("SUM(CASE WHEN status='invalid' THEN 1 ELSE 0 END)::int as invalid"),
                DB::raw('COALESCE(SUM(commission_amount),0)::int as total_komisi'),
                DB::raw('COALESCE(SUM(discount_amount),0)::int as total_discount'),
            ])
            ->groupBy('referrer_id');

        // 2) basis daftar referrer dari referrals (attach)
        $referrerBase = Referral::query()
            ->whereNotNull('referred_by')
            ->select('referred_by as referrer_id')
            ->distinct();

        // 3) join users + left join txAgg agar yang belum punya transaksi tetap tampil (nilai 0)
        $query = DB::query()
            ->fromSub($referrerBase, 'rb')
            ->join('users as u', 'u.id', '=', 'rb.referrer_id')
            ->leftJoinSub($txAgg, 'agg', function ($join) {
                $join->on('agg.referrer_id', '=', 'u.id');
            })
            ->select([
                'u.id',
                'u.name',
                'u.email',
                'u.referral_code',
                DB::raw('COALESCE(agg.total_orders_used,0)::int as total_orders_used'),
                DB::raw('COALESCE(agg.total_users_used,0)::int as total_users_used'),
                DB::raw('COALESCE(agg.valid,0)::int as valid'),
                DB::raw('COALESCE(agg.pending,0)::int as pending'),
                DB::raw('COALESCE(agg.invalid,0)::int as invalid'),
                DB::raw('COALESCE(agg.total_komisi,0)::int as total_komisi'),
                DB::raw('COALESCE(agg.total_discount,0)::int as total_discount'),
            ])
            ->orderByDesc('total_users_used')
            ->orderByDesc('total_orders_used')
            ->orderByDesc('u.id');

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
     * C) DETAIL (untuk 1 referrer)
     * ============================
     * GET /api/v1/admin/referrals/{referrer_id}/detail
     *
     * Query:
     * - status=valid|pending|invalid
     * - q=search buyer name/email
     * - per_page=20
     *
     * Return:
     * - summary
     * - items: list transaksi referral (buyer, invoice, status, discount, komisi, tanggal)
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
                DB::raw('COUNT(*)::int as total_orders_used'),
                DB::raw('COUNT(DISTINCT user_id)::int as total_users_used'),
                DB::raw("SUM(CASE WHEN status='valid' THEN 1 ELSE 0 END)::int as valid"),
                DB::raw("SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END)::int as pending"),
                DB::raw("SUM(CASE WHEN status='invalid' THEN 1 ELSE 0 END)::int as invalid"),
                DB::raw('COALESCE(SUM(commission_amount),0)::int as total_komisi'),
                DB::raw('COALESCE(SUM(discount_amount),0)::int as total_discount'),
            ])->first();

        $tx = ReferralTransaction::query()
            ->where('referrer_id', $referrer->id)
            ->with([
                'user:id,name,email,referral_code',
                'order:id,invoice_number,amount,discount_total,created_at',
            ]);

        if ($status) $tx->where('status', $status);

        if ($q !== '') {
            $tx->whereHas('user', function ($u) use ($q) {
                $u->where('name', 'ilike', "%{$q}%")
                  ->orWhere('email', 'ilike', "%{$q}%")
                  ->orWhere('referral_code', 'ilike', "%{$q}%");
            });
        }

        $items = $tx->latest('id')->paginate($perPage);

        // Map agar FE enak: buyer + invoice + status + discount + komisi + tanggal
        $itemsArr = $items->toArray();
        $itemsArr['data'] = collect($items->items())->map(function ($row) {
            $tanggal = optional($row->occurred_at ?: $row->created_at)->toDateString();

            return [
                'buyer' => [
                    'id' => (int) $row->user_id,
                    'name' => $row->user?->name,
                    'email' => $row->user?->email,
                ],
                'order' => [
                    'id' => (int) $row->order_id,
                    'invoice_number' => $row->order?->invoice_number,
                    'amount' => (int) ($row->order?->amount ?? 0),
                    'discount_total' => (int) ($row->order?->discount_total ?? 0),
                ],
                'status' => $row->status,
                'discount_amount' => (int) $row->discount_amount,
                'commission_amount' => (int) $row->commission_amount,
                'tanggal' => $tanggal,
            ];
        })->values();

        return $this->ok([
            'referrer' => [
                'id' => (int) $referrer->id,
                'name' => $referrer->name,
                'email' => $referrer->email,
                'referral_code' => $referrer->referral_code,
            ],
            'summary' => [
                'total_orders_used' => (int) ($summary->total_orders_used ?? 0),
                'total_users_used' => (int) ($summary->total_users_used ?? 0),
                'valid' => (int) ($summary->valid ?? 0),
                'pending' => (int) ($summary->pending ?? 0),
                'invalid' => (int) ($summary->invalid ?? 0),
                'total_komisi' => (int) ($summary->total_komisi ?? 0),
                'total_discount' => (int) ($summary->total_discount ?? 0),
            ],
            'items' => $itemsArr,
        ]);
    }

    /**
     * ============================
     * D) HISTORY TRANSAKSI REFERRAL (GLOBAL)
     * ============================
     * GET /api/v1/admin/referrals/history
     *
     * Query:
     * - status=pending|valid|invalid
     * - referrer_id=
     * - user_id= (buyer)
     * - q=search buyer/referrer
     * - date_from=YYYY-MM-DD
     * - date_to=YYYY-MM-DD
     * - per_page=20
     */
    public function history(Request $request)
    {
        $perPage = (int) $request->query('per_page', 20);
        $status = $request->query('status');
        $referrerId = $request->query('referrer_id');
        $userId = $request->query('user_id');
        $q = trim((string) $request->query('q', ''));
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');

        $tx = ReferralTransaction::query()
            ->with([
                'user:id,name,email,referral_code',
                'referrer:id,name,email,referral_code',
                'order:id,invoice_number,amount,discount_total,created_at',
            ]);

        if ($status) $tx->where('status', $status);
        if ($referrerId) $tx->where('referrer_id', (int) $referrerId);
        if ($userId) $tx->where('user_id', (int) $userId);

        if ($dateFrom) $tx->whereDate('created_at', '>=', $dateFrom);
        if ($dateTo) $tx->whereDate('created_at', '<=', $dateTo);

        if ($q !== '') {
            $tx->where(function ($w) use ($q) {
                $w->whereHas('user', function ($u) use ($q) {
                    $u->where('name', 'ilike', "%{$q}%")
                      ->orWhere('email', 'ilike', "%{$q}%")
                      ->orWhere('referral_code', 'ilike', "%{$q}%");
                })->orWhereHas('referrer', function ($r) use ($q) {
                    $r->where('name', 'ilike', "%{$q}%")
                      ->orWhere('email', 'ilike', "%{$q}%")
                      ->orWhere('referral_code', 'ilike', "%{$q}%");
                });
            });
        }

        return $this->ok($tx->latest('id')->paginate($perPage));
    }

    /**
     * ============================
     * E) USAGE STATS (per referrer)
     * ============================
     * GET /api/v1/admin/referrals/usage-stats
     *
     * Return:
     * - total_users_used (distinct buyer)
     * - total_orders_used (count tx)
     * - valid/pending/invalid
     * - total_komisi
     */
    public function usageStats(Request $request)
    {
        $perPage = (int) $request->query('per_page', 20);
        $q = trim((string) $request->query('q', ''));

        $agg = ReferralTransaction::query()
            ->select([
                'referrer_id',
                DB::raw('COUNT(*)::int as total_orders_used'),
                DB::raw('COUNT(DISTINCT user_id)::int as total_users_used'),
                DB::raw("SUM(CASE WHEN status='valid' THEN 1 ELSE 0 END)::int as valid"),
                DB::raw("SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END)::int as pending"),
                DB::raw("SUM(CASE WHEN status='invalid' THEN 1 ELSE 0 END)::int as invalid"),
                DB::raw('COALESCE(SUM(commission_amount),0)::int as total_komisi'),
            ])
            ->groupBy('referrer_id');

        $query = DB::query()
            ->fromSub($agg, 'a')
            ->join('users as u', 'u.id', '=', 'a.referrer_id')
            ->select([
                'u.id','u.name','u.email','u.referral_code',
                'a.total_orders_used','a.total_users_used',
                'a.valid','a.pending','a.invalid',
                'a.total_komisi',
            ])
            ->orderByDesc('a.total_users_used')
            ->orderByDesc('a.total_orders_used');

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