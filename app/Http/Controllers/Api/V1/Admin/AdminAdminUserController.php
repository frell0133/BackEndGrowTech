<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Models\User;
use App\Models\AdminRole;
use App\Models\AdminPermission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminAdminUserController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        $admins = User::query()
            ->where('role', 'admin')
            ->with(['adminRole.permissions' => fn($qq) => $qq->orderBy('group')->orderBy('key')])
            ->when($q !== '', function ($qq) use ($q) {
                $qq->where(function ($x) use ($q) {
                    $x->where('email', 'like', "%{$q}%")
                        ->orWhere('name', 'like', "%{$q}%")
                        ->orWhere('full_name', 'like', "%{$q}%");
                });
            })
            ->orderByDesc('id')
            ->paginate((int) $request->query('per_page', 20));

        return $this->ok($admins);
    }

    public function assign(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'admin_role_id' => 'required|integer|exists:admin_roles,id',
        ]);

        $actor = $request->user();

        $user = User::with('adminRole')->findOrFail((int) $data['user_id']);
        $role = AdminRole::findOrFail((int) $data['admin_role_id']);

        // role super tidak boleh di-assign lewat endpoint umum
        if ((bool) $role->is_super) {
            return $this->fail('Role owner/super admin tidak boleh di-assign lewat endpoint ini', 422);
        }

        // jangan ubah akun owner/super admin lewat endpoint umum
        if ($user->adminRole?->is_super) {
            return $this->fail('Akun owner/super admin tidak boleh diubah lewat endpoint ini', 422);
        }

        // cegah self-downgrade / self-mutate yang berbahaya
        if ((int) $actor->id === (int) $user->id && $actor->adminRole?->is_super) {
            return $this->fail('Tidak boleh mengubah role akun sendiri lewat endpoint ini', 422);
        }

        $user->role = 'admin';
        $user->admin_role_id = $role->id;
        $user->save();

        $user->load('adminRole.permissions');

        return $this->ok($user);
    }

    public function revoke(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
        ]);

        $actor = $request->user();

        $user = User::with('adminRole')->findOrFail((int) $data['user_id']);

        if (($user->role ?? null) !== 'admin') {
            return $this->fail('Target user bukan admin', 422);
        }

        // owner/super admin tidak boleh direvoke lewat endpoint umum
        if ($user->adminRole?->is_super) {
            return $this->fail('Akun owner/super admin tidak boleh direvoke lewat endpoint ini', 422);
        }

        // cegah self revoke
        if ((int) $actor->id === (int) $user->id) {
            return $this->fail('Tidak boleh merevoke akun sendiri', 422);
        }

        $user->admin_role_id = null;
        $user->role = 'user';
        $user->save();

        return $this->ok(['revoked' => true]);
    }

    public function show(int $id)
    {
        $user = User::with(['adminRole.permissions' => fn($q) => $q->orderBy('group')->orderBy('key')])
            ->findOrFail($id);

        if (($user->role ?? null) !== 'admin') {
            return $this->fail('Target user bukan admin', 422);
        }

        $roleSlug = $user->adminRole?->slug ?? null;
        $accessMode = ($roleSlug && str_starts_with($roleSlug, 'custom_user_')) ? 'custom' : 'preset';

        return $this->ok([
            'id' => $user->id,
            'name' => $user->name,
            'full_name' => $user->full_name,
            'email' => $user->email,
            'role' => $user->role,
            'tier' => $user->tier,
            'access_mode' => $accessMode,
            'admin_role' => $user->adminRole ? [
                'id' => $user->adminRole->id,
                'name' => $user->adminRole->name,
                'slug' => $user->adminRole->slug,
                'is_super' => (bool) $user->adminRole->is_super,
                'is_system' => (bool) ($user->adminRole->is_system ?? false),
            ] : null,
            'permission_keys' => $user->adminPermissionKeys(),
        ]);
    }

    /**
     * Apply preset role -> checklist otomatis ikut role tsb
     * POST /api/v1/admin/admin-users/{id}/apply-role
     * Body: { "admin_role_id": 3 }
     */
    public function applyRole(Request $request, int $id)
    {
        $data = $request->validate([
            'admin_role_id' => 'required|integer|exists:admin_roles,id',
        ]);

        $actor = $request->user();

        $user = User::with('adminRole')->findOrFail($id);

        if (($user->role ?? null) !== 'admin') {
            return $this->fail('Target user bukan admin', 422);
        }

        // jangan ubah owner lewat endpoint ini
        if ($user->adminRole?->is_super) {
            return $this->fail('Tidak boleh mengubah role owner/super admin lewat endpoint ini', 422);
        }

        // cegah self mutate untuk super admin
        if ((int) $actor->id === (int) $user->id && $actor->adminRole?->is_super) {
            return $this->fail('Tidak boleh mengubah role akun sendiri lewat endpoint ini', 422);
        }

        $role = AdminRole::findOrFail((int) $data['admin_role_id']);

        // preset role tidak boleh role super
        if ((bool) $role->is_super) {
            return $this->fail('Role owner/super admin tidak boleh di-apply lewat endpoint ini', 422);
        }

        // preset role tidak boleh custom role user lain
        if (str_starts_with((string) $role->slug, 'custom_user_')) {
            return $this->fail('Custom role tidak boleh dipakai sebagai preset', 422);
        }

        $user->admin_role_id = $role->id;
        $user->save();

        $user->load('adminRole.permissions');

        return $this->ok([
            'user_id' => $user->id,
            'access_mode' => 'preset',
            'admin_role' => [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug,
                'is_super' => (bool) $role->is_super,
                'is_system' => (bool) ($role->is_system ?? false),
            ],
            'permission_keys' => $user->adminPermissionKeys(),
        ]);
    }

    /**
     * Save custom checklist -> auto buat/update role custom_user_{id}
     * POST /api/v1/admin/admin-users/{id}/permissions
     * Body: { "permission_keys": ["manage_products","manage_categories"] }
     */
    public function upsertPermissions(Request $request, int $id)
    {
        $data = $request->validate([
            'permission_keys' => 'required|array',
            'permission_keys.*' => 'string',
        ]);

        $actor = $request->user();

        $user = User::with('adminRole')->findOrFail($id);

        if (($user->role ?? null) !== 'admin') {
            return $this->fail('Target user bukan admin', 422);
        }

        // jangan ubah owner lewat endpoint ini
        if ($user->adminRole?->is_super) {
            return $this->fail('Tidak boleh mengubah permission owner/super admin lewat endpoint ini', 422);
        }

        // cegah self mutate untuk super admin
        if ((int) $actor->id === (int) $user->id && $actor->adminRole?->is_super) {
            return $this->fail('Tidak boleh mengubah permission akun sendiri lewat endpoint ini', 422);
        }

        $keys = array_values(array_unique($data['permission_keys'] ?? []));

        // blok permission sensitif owner-only
        $forbiddenKeys = ['rbac.manage', 'manage_admins'];
        $forbiddenUsed = array_values(array_intersect($keys, $forbiddenKeys));
        if (!empty($forbiddenUsed)) {
            return $this->fail('Permission owner-only tidak boleh diberikan ke admin biasa', 422, [
                'forbidden_permission_keys' => $forbiddenUsed,
            ]);
        }

        // validasi key harus benar-benar ada
        $validKeys = AdminPermission::whereIn('key', $keys)->pluck('key')->all();
        $invalidKeys = array_values(array_diff($keys, $validKeys));
        if (!empty($invalidKeys)) {
            return $this->fail('Permission tidak valid', 422, [
                'invalid_permission_keys' => $invalidKeys,
            ]);
        }

        return DB::transaction(function () use ($user, $keys) {
            $slug = 'custom_user_' . $user->id;

            $role = AdminRole::updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => "Custom Role for {$user->email}",
                    'is_super' => false,
                    'is_system' => false,
                ]
            );

            $permIds = AdminPermission::whereIn('key', $keys)->pluck('id')->all();
            $role->permissions()->sync($permIds);

            $user->admin_role_id = $role->id;
            $user->save();

            $user->load('adminRole.permissions');

            return $this->ok([
                'user_id' => $user->id,
                'access_mode' => 'custom',
                'admin_role' => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'slug' => $role->slug,
                    'is_super' => false,
                    'is_system' => false,
                ],
                'permission_keys' => $user->adminPermissionKeys(),
            ]);
        });
    }
}