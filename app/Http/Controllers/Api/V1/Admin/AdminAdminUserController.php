<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminPermission;
use App\Models\AdminRole;
use App\Models\User;
use App\Services\AdminAuditLogger;
use App\Services\TrustedDeviceService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminAdminUserController extends Controller
{
    use ApiResponse;

    private function invalidateUserSessions(User $user): void
    {
        if (method_exists($user, 'tokens')) {
            $user->tokens()->delete();
        }

        app(TrustedDeviceService::class)->revokeAllForUser($user);
    }

    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        $admins = User::query()
            ->where('role', 'admin')
            ->with(['adminRole.permissions' => fn ($qq) => $qq->orderBy('group')->orderBy('key')])
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

    public function assign(Request $request, AdminAuditLogger $audit)
    {
        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'admin_role_id' => 'required|integer|exists:admin_roles,id',
        ]);

        $actor = $request->user();

        $user = User::with('adminRole.permissions')->findOrFail((int) $data['user_id']);
        $role = AdminRole::with('permissions')->findOrFail((int) $data['admin_role_id']);

        if ((bool) $role->is_super) {
            return $this->fail('Role owner/super admin tidak boleh di-assign lewat endpoint ini', 422);
        }

        if ($user->adminRole?->is_super) {
            return $this->fail('Akun owner/super admin tidak boleh diubah lewat endpoint ini', 422);
        }

        if ((int) $actor->id === (int) $user->id && $actor->adminRole?->is_super) {
            return $this->fail('Tidak boleh mengubah role akun sendiri lewat endpoint ini', 422);
        }

        $before = $this->adminUserSnapshot($user);

        DB::transaction(function () use ($request, $audit, $user, $role, $before) {
            $user->role = 'admin';
            $user->admin_role_id = $role->id;
            $user->save();

            $user->load('adminRole.permissions');
            $this->invalidateUserSessions($user);

            $audit->log(
                request: $request,
                action: 'admin.assign',
                entity: 'users',
                entityId: $user->id,
                meta: [
                    'module' => 'rbac',
                    'summary' => 'Assign admin role ke user',
                    'target' => [
                        'user_id' => $user->id,
                        'email' => $user->email,
                        'admin_role_id' => $role->id,
                        'admin_role_slug' => $role->slug,
                    ],
                    'before' => $before,
                    'after' => $this->adminUserSnapshot($user),
                ],
            );
        });

        return $this->ok($user->fresh()->load('adminRole.permissions'));
    }

    public function revoke(Request $request, AdminAuditLogger $audit)
    {
        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
        ]);

        $actor = $request->user();
        $user = User::with('adminRole.permissions')->findOrFail((int) $data['user_id']);

        if (($user->role ?? null) !== 'admin') {
            return $this->fail('Target user bukan admin', 422);
        }

        if ($user->adminRole?->is_super) {
            return $this->fail('Akun owner/super admin tidak boleh direvoke lewat endpoint ini', 422);
        }

        if ((int) $actor->id === (int) $user->id) {
            return $this->fail('Tidak boleh merevoke akun sendiri', 422);
        }

        $before = $this->adminUserSnapshot($user);

        DB::transaction(function () use ($request, $audit, $user, $before) {
            $user->admin_role_id = null;
            $user->role = 'user';
            $user->save();
            $this->invalidateUserSessions($user);

            $audit->log(
                request: $request,
                action: 'admin.revoke',
                entity: 'users',
                entityId: $user->id,
                meta: [
                    'module' => 'rbac',
                    'summary' => 'Revoke akses admin dari user',
                    'target' => [
                        'user_id' => $user->id,
                        'email' => $user->email,
                    ],
                    'before' => $before,
                    'after' => $this->adminUserSnapshot($user->fresh()),
                ],
            );
        });

        return $this->ok(['revoked' => true]);
    }

    public function show(int $id)
    {
        $user = User::with(['adminRole.permissions' => fn ($q) => $q->orderBy('group')->orderBy('key')])
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

    public function applyRole(Request $request, int $id, AdminAuditLogger $audit)
    {
        $data = $request->validate([
            'admin_role_id' => 'required|integer|exists:admin_roles,id',
        ]);

        $actor = $request->user();
        $user = User::with('adminRole.permissions')->findOrFail($id);

        if (($user->role ?? null) !== 'admin') {
            return $this->fail('Target user bukan admin', 422);
        }

        if ($user->adminRole?->is_super) {
            return $this->fail('Tidak boleh mengubah role owner/super admin lewat endpoint ini', 422);
        }

        if ((int) $actor->id === (int) $user->id && $actor->adminRole?->is_super) {
            return $this->fail('Tidak boleh mengubah role akun sendiri lewat endpoint ini', 422);
        }

        $role = AdminRole::with('permissions')->findOrFail((int) $data['admin_role_id']);

        if ((bool) $role->is_super) {
            return $this->fail('Role owner/super admin tidak boleh di-apply lewat endpoint ini', 422);
        }

        if (str_starts_with((string) $role->slug, 'custom_user_')) {
            return $this->fail('Custom role tidak boleh dipakai sebagai preset', 422);
        }

        $before = $this->adminUserSnapshot($user);

        DB::transaction(function () use ($request, $audit, $user, $role, $before) {
            $user->admin_role_id = $role->id;
            $user->save();
            $user->load('adminRole.permissions');
            $this->invalidateUserSessions($user);

            $audit->log(
                request: $request,
                action: 'admin.apply_role',
                entity: 'users',
                entityId: $user->id,
                meta: [
                    'module' => 'rbac',
                    'summary' => 'Apply preset role ke admin user',
                    'target' => [
                        'user_id' => $user->id,
                        'email' => $user->email,
                        'admin_role_id' => $role->id,
                        'admin_role_slug' => $role->slug,
                    ],
                    'before' => $before,
                    'after' => $this->adminUserSnapshot($user),
                ],
            );
        });

        $user = $user->fresh()->load('adminRole.permissions');
        $role = $user->adminRole;

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

    public function upsertPermissions(Request $request, int $id, AdminAuditLogger $audit)
    {
        $data = $request->validate([
            'permission_keys' => 'required|array',
            'permission_keys.*' => 'string',
        ]);

        $actor = $request->user();
        $user = User::with('adminRole.permissions')->findOrFail($id);

        if (($user->role ?? null) !== 'admin') {
            return $this->fail('Target user bukan admin', 422);
        }

        if ($user->adminRole?->is_super) {
            return $this->fail('Tidak boleh mengubah permission owner/super admin lewat endpoint ini', 422);
        }

        if ((int) $actor->id === (int) $user->id && $actor->adminRole?->is_super) {
            return $this->fail('Tidak boleh mengubah permission akun sendiri lewat endpoint ini', 422);
        }

        $keys = array_values(array_unique($data['permission_keys'] ?? []));

        $validKeys = AdminPermission::whereIn('key', $keys)->pluck('key')->all();
        $invalidKeys = array_values(array_diff($keys, $validKeys));
        if (!empty($invalidKeys)) {
            return $this->fail('Permission tidak valid', 422, [
                'invalid_permission_keys' => $invalidKeys,
            ]);
        }

        $protectedKeys = AdminPermission::whereIn('key', $keys)
            ->where('is_protected', true)
            ->pluck('key')
            ->all();

        if (!empty($protectedKeys)) {
            return $this->fail('Permission protected tidak boleh diberikan ke admin biasa', 422, [
                'protected_permission_keys' => array_values($protectedKeys),
            ]);
        }

        $before = $this->adminUserSnapshot($user);

        return DB::transaction(function () use ($request, $audit, $user, $keys, $before) {
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
            $this->invalidateUserSessions($user);

            $audit->log(
                request: $request,
                action: 'admin.permissions_upsert',
                entity: 'users',
                entityId: $user->id,
                meta: [
                    'module' => 'rbac',
                    'summary' => 'Update custom permission admin user',
                    'target' => [
                        'user_id' => $user->id,
                        'email' => $user->email,
                        'admin_role_id' => $role->id,
                        'admin_role_slug' => $role->slug,
                    ],
                    'before' => $before,
                    'after' => $this->adminUserSnapshot($user),
                ],
            );

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

    private function adminUserSnapshot(User $user): array
    {
        $user->loadMissing(['adminRole.permissions']);

        $role = $user->adminRole;
        $roleSlug = $role?->slug ?? null;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'full_name' => $user->full_name,
            'email' => $user->email,
            'role' => $user->role,
            'tier' => $user->tier,
            'access_mode' => ($roleSlug && str_starts_with($roleSlug, 'custom_user_')) ? 'custom' : 'preset',
            'admin_role' => $role ? [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug,
                'is_super' => (bool) $role->is_super,
                'is_system' => (bool) ($role->is_system ?? false),
            ] : null,
            'permission_keys' => $user->adminPermissionKeys(),
        ];
    }
}