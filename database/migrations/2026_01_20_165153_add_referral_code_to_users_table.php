<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'referral_code')) {
                $table->string('referral_code', 12)->nullable()->unique()->after('role');
            }
        });

        // Backfill referral_code untuk user existing yang masih null.
        // Format: 7 char uppercase alfanumerik (mis. FRL8K2Q).
        // Kita pakai gen_random_bytes (pgcrypto). Kalau extension belum aktif, akan error.
        // Jika kamu belum punya pgcrypto, aktifkan:
        //   CREATE EXTENSION IF NOT EXISTS pgcrypto;
        DB::statement("CREATE EXTENSION IF NOT EXISTS pgcrypto;");

        DB::statement("
            UPDATE users
            SET referral_code = (
                SELECT string_agg(substr(chars, (floor(random()*length(chars))+1)::int, 1), '')
                FROM (
                    SELECT 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789' AS chars
                ) s,
                generate_series(1,7)
            )
            WHERE referral_code IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'referral_code')) {
                $table->dropUnique(['referral_code']);
                $table->dropColumn('referral_code');
            }
        });
    }
};
