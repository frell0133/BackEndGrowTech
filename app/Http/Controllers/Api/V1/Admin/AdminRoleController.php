<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminPermission;
use App\Models\AdminRole;
use App\Services\AdminAuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminRoleController extends Controller
{
    use ApiResponse;

    public function index()
    {
        $roles = AdminRole::with([
                'permissions' => fn ($q) => $q->orderBy('group')->orderBy('key'),
            ])
            ->orderByDesc('is_super')
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get();

        return $this->ok($roles);
    }

    public function store(Request $request, AdminAuditLogger $audit)
    {
        if ($request->hasAny(['is_super', 'is_system'])) {
            return $this->fail('Field is_super / is_system tidak boleh diubah dari endpoint ini', 422);
        }

        $data = $request->validate([
            'name' => 'required|string|max:120',
            'slug' => 'required|string|max:120|alpha_dash|unique:admin_roles,slug',
            'permission_keys' => 'nullable|array',
            'permission_keys.*' => 'string',
        ]);

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
            return $this->fail('Permission protected tidak boleh dimasukkan ke role biasa', 422, [
                'protected_permission_keys' => $protectedKeys,
            ]);
        }

        $role = DB::transaction(function () use ($request, $audit, $data, $keys) {
            $role = AdminRole::create([
                'name' => $data['name'],
                'slug' => $data['slug'],
                'is_super' => false,
                'is_system' => false,
            ]);

            if (!empty($keys)) {
                $permIds = AdminPermission::whereIn('key', $keys)->pluck('id')->all();
                $role->permissions()->sync($permIds);
            }

            $role->load(['permissions' => fn ($q) => $q->orderBy('group')->orderBy('key')]);

            $audit->log(
                request: $request,
                action: 'admin_role.create',
                entity: 'admin_roles',
                entityId: $role->id,
                meta: [
                    'module' => 'rbac',
                    'summary' => 'Membuat role admin baru',
                    'target' => [
                        'admin_role_id' => $role->id,
                        'slug' => $role->slug,
                    ],
                    'after' => $this->roleSnapshot($role),
                ],
            );

            return $role;
        });

        return $this->ok($role, 201);
    }

    public function update(Request $request, int $id, AdminAuditLogger $audit)
    {
        $role = AdminRole::with('permissions')->findOrFail($id);

        if ($role->is_super) {
            return $this->fail('Role owner/super admin tidak boleh diubah dari endpoint ini', 422);
        }

        if ($request->hasAny(['is_super', 'is_system', 'slug'])) {
            return $this->fail('Field is_super / is_system / slug tidak boleh diubah dari endpoint ini', 422);
        }

        $data = $request->validate([
            'name' => 'sometimes|string|max:120',
            'permission_keys' => 'nullable|array',
            'permission_keys.*' => 'string',
        ]);

        if (array_key_exists('permission_keys', $data)) {
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
                return $this->fail('Permission protected tidak boleh dimasukkan ke role biasa', 422, [
                    'protected_permission_keys' => $protectedKeys,
                ]);
            }
        }

        $before = $this->roleSnapshot($role);

        DB::transaction(function () use ($request, $audit, $role, $data, $before) {
            if (array_key_exists('name', $data)) {
                $role->name = $data['name'];
                $role->save();
            }

            if (array_key_exists('permission_keys', $data)) {
                $keys = array_values(array_unique($data['permission_keys'] ?? []));
                $permIds = AdminPermission::whereIn('key', $keys)->pluck('id')->all();
                $role->permissions()->sync($permIds);
            }

            $role->load(['permissions' => fn ($q) => $q->orderBy('group')->orderBy('key')]);

            $audit->log(
                request: $request,
                action: 'admin_role.update',
                entity: 'admin_roles',
                entityId: $role->id,
                meta: [
                    'module' => 'rbac',
                    'summary' => 'Mengubah role admin',
                    'target' => [
                        'admin_role_id' => $role->id,
                        'slug' => $role->slug,
                    ],
                    'before' => $before,
                    'after' => $this->roleSnapshot($role),
                ],
            );
        });

        $role->refresh()->load(['permissions' => fn ($q) => $q->orderBy('group')->orderBy('key')]);

        return $this->ok($role);
    }

    public function destroy(Request $request, int $id, AdminAuditLogger $audit)
    {
        $role = AdminRole::with('permissions')->findOrFail($id);

        if ($role->is_super) {
            return $this->fail('Tidak boleh hapus role owner/super admin', 422);
        }

        if ($role->is_system) {
            return $this->fail('Tidak boleh hapus role preset sistem', 422);
        }

        if ($role->users()->count() > 0) {
            return $this->fail('Role masih dipakai oleh user', 422);
        }

        $before = $this->roleSnapshot($role);

        DB::transaction(function () use ($request, $audit, $role, $before) {
            $role->permissions()->sync([]);

            $audit->log(
                request: $request,
                action: 'admin_role.delete',
                entity: 'admin_roles',
                entityId: $role->id,
                meta: [
                    'module' => 'rbac',
                    'summary' => 'Menghapus role admin',
                    'target' => [
                        'admin_role_id' => $role->id,
                        'slug' => $role->slug,
                    ],
                    'before' => $before,
                ],
            );

            $role->delete();
        });

        return $this->ok(['deleted' => true]);
    }

    private function roleSnapshot(AdminRole $role): array
    {
        $role->loadMissing('permissions');

        return [
            'id' => $role->id,
            'name' => $role->name,
            'slug' => $role->slug,
            'is_super' => (bool) $role->is_super,
            'is_system' => (bool) $role->is_system,
            'permission_keys' => $role->permissions->pluck('key')->values()->all(),
        ];
    }
}