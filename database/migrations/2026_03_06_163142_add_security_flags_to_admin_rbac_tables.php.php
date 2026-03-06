<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('admin_roles', 'is_system')) {
            Schema::table('admin_roles', function (Blueprint $table) {
                $table->boolean('is_system')->default(false)->after('is_super');
            });
        }

        if (!Schema::hasColumn('admin_permissions', 'is_protected')) {
            Schema::table('admin_permissions', function (Blueprint $table) {
                $table->boolean('is_protected')->default(false)->after('group');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('admin_roles', 'is_system')) {
            Schema::table('admin_roles', function (Blueprint $table) {
                $table->dropColumn('is_system');
            });
        }

        if (Schema::hasColumn('admin_permissions', 'is_protected')) {
            Schema::table('admin_permissions', function (Blueprint $table) {
                $table->dropColumn('is_protected');
            });
        }
    }
};