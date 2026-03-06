<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class AdminMeController extends Controller
{
    use ApiResponse;

    public function me(Request $request)
    {
        $u = $request->user();
        $u->load(['adminRole.permissions']);

        return $this->ok([
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'role' => $u->role,
            'tier' => $u->tier,
            'admin_role' => $u->adminRole ? [
                'id' => $u->adminRole->id,
                'name' => $u->adminRole->name,
                'slug' => $u->adminRole->slug,
                'is_super' => (bool) $u->adminRole->is_super,
            ] : null,
            'permissions' => $u->adminPermissionKeys(),
        ]);
    }
}