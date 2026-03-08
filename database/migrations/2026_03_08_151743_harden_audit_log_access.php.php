<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissionId = DB::table('admin_permissions')->where('key', 'view_audit_logs')->value('id');

        if ($permissionId) {
            DB::table('admin_permissions')
                ->where('id', $permissionId)
                ->update(['is_protected' => true]);

            $nonSuperRoleIds = DB::table('admin_roles')
                ->where(function ($q) {
                    $q->whereNull('is_super')->orWhere('is_super', false);
                })
                ->pluck('id');

            if ($nonSuperRoleIds->isNotEmpty()) {
                DB::table('admin_role_permissions')
                    ->where('admin_permission_id', $permissionId)
                    ->whereIn('admin_role_id', $nonSuperRoleIds->all())
                    ->delete();
            }
        }
    }

    public function down(): void
    {
        DB::table('admin_permissions')
            ->where('key', 'view_audit_logs')
            ->update(['is_protected' => false]);
    }
};