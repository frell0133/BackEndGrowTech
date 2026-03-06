<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Models\User;
use App\Models\AdminRole;
use Illuminate\Http\Request;
use App\Models\AdminPermission;
use Illuminate\Support\Facades\DB;

class AdminAdminUserController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $q = $request->query('q');

        $admins = User::query()
            ->where('role', 'admin')
            ->with('adminRole.permissions')
            ->when($q, fn($qq) => $qq->where('email', 'like', "%{$q}%")->orWhere('name','like',"%{$q}%"))
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

        $user = User::findOrFail((int)$data['user_id']);
        $role = AdminRole::findOrFail((int)$data['admin_role_id']);

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

        $user = User::with('adminRole')->findOrFail((int)$data['user_id']);

        // Jangan sampai owner terakhir hilang
        if ($user->adminRole?->is_super) {
            $owners = User::where('role','admin')
                ->whereNotNull('admin_role_id')
                ->whereHas('adminRole', fn($q) => $q->where('is_super', true))
                ->count();

            if ($owners <= 1) {
                return $this->fail('Tidak boleh revoke Owner terakhir', 422);
            }
        }

        $user->admin_role_id = null;
        $user->role = 'user'; // biar langsung gak bisa akses /admin
        $user->save();

        return $this->ok(['revoked' => true]);
    }
    public function show(int $id)
    {
        $user = \App\Models\User::with(['adminRole.permissions'])->findOrFail($id);

        if (($user->role ?? null) !== 'admin') {
            return $this->fail('Target user bukan admin', 422);
        }

        return $this->ok([
            'id' => $user->id,
            'name' => $user->name,
            'full_name' => $user->full_name,
            'email' => $user->email,
            'role' => $user->role,
            'tier' => $user->tier,
            'admin_role' => $user->adminRole ? [
                'id' => $user->adminRole->id,
                'name' => $user->adminRole->name,
                'slug' => $user->adminRole->slug,
                'is_super' => (bool) $user->adminRole->is_super,
            ] : null,
            'permission_keys' => $user->adminPermissionKeys(), // ['*'] atau list
        ]);
    }

    /**
     * Apply preset role -> checklist otomatis ikut role tsb
     * POST /api/v1/admin/admin-users/{id}/apply-role
     * Body: { "admin_role_id": 3 }
     */
    public function applyRole(\Illuminate\Http\Request $request, int $id)
    {
        $data = $request->validate([
            'admin_role_id' => 'required|integer|exists:admin_roles,id',
        ]);

        $user = \App\Models\User::with('adminRole')->findOrFail($id);

        if (($user->role ?? null) !== 'admin') {
            return $this->fail('Target user bukan admin', 422);
        }

        // jangan ubah owner lewat endpoint ini
        if ($user->adminRole?->is_super) {
            return $this->fail('Tidak boleh mengubah role Owner lewat endpoint ini', 422);
        }

        $role = AdminRole::findOrFail((int) $data['admin_role_id']);

        $user->admin_role_id = $role->id;
        $user->save();

        $user->load('adminRole.permissions');

        return $this->ok([
            'user_id' => $user->id,
            'admin_role' => [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug,
                'is_super' => (bool) $role->is_super,
            ],
            'permission_keys' => $user->adminPermissionKeys(),
        ]);
    }

    /**
     * Save custom checklist -> auto buat/update role custom_user_{id}
     * POST /api/v1/admin/admin-users/{id}/permissions
     * Body: { "permission_keys": ["products.manage","categories.manage"] }
     */
    public function upsertPermissions(\Illuminate\Http\Request $request, int $id)
    {
        $data = $request->validate([
            'permission_keys' => 'required|array',
            'permission_keys.*' => 'string',
        ]);

        $user = \App\Models\User::with('adminRole')->findOrFail($id);

        if (($user->role ?? null) !== 'admin') {
            return $this->fail('Target user bukan admin', 422);
        }

        // jangan ubah owner lewat endpoint ini
        if ($user->adminRole?->is_super) {
            return $this->fail('Tidak boleh mengubah permission Owner lewat endpoint ini', 422);
        }

        // safety: jangan boleh kasih akses RBAC manage ke admin biasa
        $keys = array_values(array_diff($data['permission_keys'], ['rbac.manage', 'manage_admins']));

        return DB::transaction(function () use ($user, $keys) {

            $slug = 'custom_user_' . $user->id;

            $role = AdminRole::updateOrCreate(
                ['slug' => $slug],
                ['name' => "Custom Role for {$user->email}", 'is_super' => false]
            );

            $permIds = AdminPermission::whereIn('key', $keys)->pluck('id')->all();
            $role->permissions()->sync($permIds);

            $user->admin_role_id = $role->id;
            $user->save();

            $user->load('adminRole.permissions');

            return $this->ok([
                'user_id' => $user->id,
                'admin_role' => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'slug' => $role->slug,
                ],
                'permission_keys' => $user->adminPermissionKeys(),
            ]);
        });
    }
}