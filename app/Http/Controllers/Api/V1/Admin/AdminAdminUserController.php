<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Models\User;
use App\Models\AdminRole;
use Illuminate\Http\Request;

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
}