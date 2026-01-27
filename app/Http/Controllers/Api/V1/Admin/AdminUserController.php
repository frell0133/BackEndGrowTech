<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Models\User;
use App\Models\Order;
use App\Models\Referral;
use App\Models\LedgerEntry;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Hash;

class AdminUserController extends Controller
{
    use ApiResponse;

    /**
     * GET /admin/users
     * Query:
     * - q: search name/email/referral_code
     * - role: admin|user
     * - from, to: created_at date range (YYYY-MM-DD)
     * - per_page: 1..100
     */
    public function index(Request $request)
    {
        $query = User::query();

        if ($search = $request->query('q')) {
            $query->where(function ($w) use ($search) {
                $w->where('name', 'ILIKE', "%{$search}%")
                ->orWhere('email', 'ILIKE', "%{$search}%")
                ->orWhere('referral_code', 'ILIKE', "%{$search}%");
            });
        }

        if ($role = $request->query('role')) {
            $query->where('role', $role);
        }

        if ($from = $request->query('from')) {
            $query->whereDate('created_at', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $query->whereDate('created_at', '<=', $to);
        }

        $perPage = max(1, min(100, (int) $request->query('per_page', 15)));

        return $this->ok(
            $query->orderByDesc('id')->paginate($perPage)
        );
    }

    /**
     * GET /admin/users/{id}
     */
    public function show(string $id)
    {
        $user = User::query()->find($id);
        if (!$user) return $this->fail('User tidak ditemukan', 404);

        return $this->ok($user);
    }

    /**
     * POST /admin/users
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required','string','max:120'],
            'full_name' => ['nullable','string','max:150'],
            'address' => ['nullable','string','max:1000'],
            'email' => ['required','email','max:190','unique:users,email'],
            'password' => ['required','string','min:8'],
            'role' => ['required', Rule::in(['user','admin'])],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'full_name' => $validated['full_name'] ?? null,
            'address' => $validated['address'] ?? null,
            'email' => strtolower($validated['email']),
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
        ]);

        // ✅ kalau mau debug, taruh SEBELUM return
        \Log::info('ADMIN CREATE USER', [
            'admin_id' => optional($request->user())->id,
            'email' => $user->email,
        ]);

        return $this->ok($user, ['message' => 'User berhasil dibuat']);
    }

    /**
     * PATCH /admin/users/{id}
     * update name/email/password/role
     */
    public function update(Request $request, string $id)
    {
        $user = User::query()->find($id);
        if (!$user) return $this->fail('User tidak ditemukan', 404);

        $validated = $request->validate([
            'name' => ['sometimes','string','max:120'],
            'full_name' => ['sometimes','nullable','string','max:150'],
            'address' => ['sometimes','nullable','string','max:1000'],
            'email' => ['sometimes','email','max:190', Rule::unique('users','email')->ignore($user->id)],
            'password' => ['sometimes','nullable','string','min:8'],
            'role' => ['sometimes', Rule::in(['user','admin'])],
        ]);

        if (array_key_exists('email', $validated)) {
            $validated['email'] = strtolower($validated['email']);
        }

        if (array_key_exists('password', $validated)) {
            if (!empty($validated['password'])) {
                $validated['password'] = Hash::make($validated['password']);
            } else {
                unset($validated['password']);
            }
        }

        $user->fill($validated)->save();

        return $this->ok($user, ['message' => 'User berhasil diupdate']);
    }

    /**
     * DELETE /admin/users/{id}
     */
    public function destroy(Request $request, string $id)
    {
        $user = User::query()->find($id);
        if (!$user) return $this->fail('User tidak ditemukan', 404);

        // Proteksi: admin tidak bisa hapus dirinya sendiri
        if ((string)$request->user()->id === (string)$user->id) {
            return $this->fail('Tidak bisa menghapus akun sendiri', 422);
        }

        // Kalau kamu pakai SoftDeletes => soft delete; kalau tidak => hard delete
        $user->delete();

        return $this->ok(['deleted' => true], ['message' => 'User berhasil dihapus']);
    }

    /**
     * GET /admin/users/{id}/ledger
     */
    public function ledger(Request $request, string $id)
    {
        $user = User::query()->find($id);
        if (!$user) return $this->fail('User tidak ditemukan', 404);

        $perPage = (int) $request->query('per_page', 20);
        $perPage = max(1, min(100, $perPage));

        $rows = LedgerEntry::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->paginate($perPage);

        return $this->ok($rows);
    }

    /**
     * GET /admin/users/{id}/orders
     */
    public function orders(Request $request, string $id)
    {
        $user = User::query()->find($id);
        if (!$user) return $this->fail('User tidak ditemukan', 404);

        $perPage = (int) $request->query('per_page', 20);
        $perPage = max(1, min(100, $perPage));

        $orders = Order::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->paginate($perPage);

        return $this->ok($orders);
    }

    /**
     * GET /admin/users/{id}/referral
     * Referral model kamu:
     * - referrals.user_id = user yang direfer
     * - referrals.referred_by = referrer (yang ngajak)
     */
    public function referral(string $id)
    {
        $user = User::query()->find($id);
        if (!$user) return $this->fail('User tidak ditemukan', 404);

        // User ini direfer oleh siapa?
        $referredBy = Referral::query()
            ->with(['referrer:id,name,email,referral_code'])
            ->where('user_id', $user->id)
            ->first();

        // User ini sudah merefer siapa saja? (user lain yang referred_by = user ini)
        $referredUsers = Referral::query()
            ->with(['user:id,name,email,referral_code'])
            ->where('referred_by', $user->id)
            ->orderByDesc('id')
            ->get();

        return $this->ok([
            'user' => $user->only(['id','name','email','role','referral_code','created_at']),
            'referred_by' => $referredBy ? $referredBy->referrer : null,
            'referred_users' => $referredUsers->map(function ($r) {
                return [
                    'referral_id' => $r->id,
                    'user' => $r->user,
                    'locked_at' => $r->locked_at,
                    'created_at' => $r->created_at,
                ];
            }),
        ]);
    }
}
