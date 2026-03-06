<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Models\AdminRole;
use App\Models\AdminPermission;
use Illuminate\Http\Request;

class AdminRoleController extends Controller
{
    use ApiResponse;

    public function index()
    {
        $roles = AdminRole::with('permissions')->orderByDesc('is_super')->orderBy('name')->get();
        return $this->ok($roles);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'slug' => 'required|string|max:120|alpha_dash|unique:admin_roles,slug',
            'is_super' => 'nullable|boolean',
            'permission_keys' => 'nullable|array',
            'permission_keys.*' => 'string',
        ]);

        $role = AdminRole::create([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'is_super' => (bool) ($data['is_super'] ?? false),
        ]);

        if (!$role->is_super) {
            $keys = $data['permission_keys'] ?? [];
            $permIds = AdminPermission::whereIn('key', $keys)->pluck('id')->all();
            $role->permissions()->sync($permIds);
        }

        $role->load('permissions');
        return $this->ok($role, 201);
    }

    public function update(Request $request, int $id)
    {
        $role = AdminRole::with('permissions')->findOrFail($id);

        $data = $request->validate([
            'name' => 'sometimes|string|max:120',
            'slug' => 'sometimes|string|max:120|alpha_dash|unique:admin_roles,slug,' . $role->id,
            'is_super' => 'sometimes|boolean',
            'permission_keys' => 'nullable|array',
            'permission_keys.*' => 'string',
        ]);

        $role->fill($data);
        $role->save();

        // kalau super => ignore permissions
        if ($role->is_super) {
            $role->permissions()->sync([]);
        } else if (array_key_exists('permission_keys', $data)) {
            $keys = $data['permission_keys'] ?? [];
            $permIds = AdminPermission::whereIn('key', $keys)->pluck('id')->all();
            $role->permissions()->sync($permIds);
        }

        $role->load('permissions');
        return $this->ok($role);
    }

    public function destroy(int $id)
    {
        $role = AdminRole::findOrFail($id);

        if ($role->is_super) {
            return $this->fail('Tidak boleh hapus role super admin', 422);
        }

        if ($role->users()->count() > 0) {
            return $this->fail('Role masih dipakai oleh user', 422);
        }

        $role->delete();
        return $this->ok(['deleted' => true]);
    }
}