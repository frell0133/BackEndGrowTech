<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('admin_role_id')
                ->nullable()
                ->after('tier')
                ->constrained('admin_roles')
                ->nullOnDelete();

            $table->index('admin_role_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['admin_role_id']);
            $table->dropConstrainedForeignId('admin_role_id');
        });
    }
};