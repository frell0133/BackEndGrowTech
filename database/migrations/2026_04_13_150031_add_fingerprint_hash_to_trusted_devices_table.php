<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trusted_devices', function (Blueprint $table) {
            if (!Schema::hasColumn('trusted_devices', 'fingerprint_hash')) {
                $table->string('fingerprint_hash', 64)->nullable()->after('user_agent_hash');
                $table->index(['user_id', 'fingerprint_hash']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('trusted_devices', function (Blueprint $table) {
            if (Schema::hasColumn('trusted_devices', 'fingerprint_hash')) {
                $table->dropIndex(['user_id', 'fingerprint_hash']);
                $table->dropColumn('fingerprint_hash');
            }
        });
    }
};