<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('admin_roles') || !Schema::hasTable('users')) {
            return;
        }

        $customRoles = DB::table('admin_roles')
            ->where('slug', 'like', 'custom_user_%')
            ->get();

        foreach ($customRoles as $role) {
            $userId = (int) str_replace('custom_user_', '', (string) $role->slug);
            $user = DB::table('users')->where('id', $userId)->first();

            $shouldDelete = !$user
                || !empty($user->deleted_at)
                || (string) ($user->role ?? '') !== 'admin';

            if ($shouldDelete) {
                DB::table('users')
                    ->where('admin_role_id', $role->id)
                    ->update(['admin_role_id' => null]);

                if (Schema::hasTable('admin_role_permissions')) {
                    DB::table('admin_role_permissions')
                        ->where('admin_role_id', $role->id)
                        ->delete();
                }

                DB::table('admin_roles')
                    ->where('id', $role->id)
                    ->delete();
            }
        }

        $adminUsersWithoutRole = DB::table('users')
            ->where('role', 'admin')
            ->whereNull('deleted_at')
            ->whereNull('admin_role_id')
            ->get(['id', 'email', 'name', 'full_name']);

        foreach ($adminUsersWithoutRole as $user) {
            $slug = 'custom_user_' . (int) $user->id;
            $nameSource = $user->email ?: ($user->full_name ?: ($user->name ?: ('User #' . $user->id)));
            $roleName = 'Custom Role for ' . $nameSource;

            $existingRole = DB::table('admin_roles')->where('slug', $slug)->first();

            if ($existingRole) {
                DB::table('admin_roles')
                    ->where('id', $existingRole->id)
                    ->update([
                        'name' => $roleName,
                        'is_super' => false,
                        'is_system' => false,
                        'updated_at' => now(),
                    ]);

                if (Schema::hasTable('admin_role_permissions')) {
                    DB::table('admin_role_permissions')
                        ->where('admin_role_id', $existingRole->id)
                        ->delete();
                }

                $roleId = $existingRole->id;
            } else {
                $roleId = DB::table('admin_roles')->insertGetId([
                    'name' => $roleName,
                    'slug' => $slug,
                    'is_super' => false,
                    'is_system' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('users')
                ->where('id', $user->id)
                ->update(['admin_role_id' => $roleId]);
        }
    }

    public function down(): void
    {
        // Data cleanup bersifat one-way agar tidak mengembalikan stale permission/role yang sudah tidak valid.
    }
};
