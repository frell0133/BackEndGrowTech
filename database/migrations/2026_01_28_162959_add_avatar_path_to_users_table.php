<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // ✅ hanya tambahkan avatar_path kalau belum ada
            if (!Schema::hasColumn('users', 'avatar_path')) {
                $table->text('avatar_path')->nullable()->after('avatar');
            }

            // ❌ JANGAN tambah avatar lagi, karena sudah ada
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'avatar_path')) {
                $table->dropColumn('avatar_path');
            }
        });
    }
};

