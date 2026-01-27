<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pastikan kolom ada (kalau sebelumnya nullable, biarkan nullable untuk kompatibilitas)
        Schema::table('payments', function (Blueprint $table) {
            if (!Schema::hasColumn('payments', 'gateway_code')) {
                $table->string('gateway_code')->nullable()->index();
            }
            if (!Schema::hasColumn('payments', 'external_id')) {
                $table->string('external_id')->nullable()->index();
            }
        });

        // Hapus duplikat sebelum unique constraint (aman untuk Postgres).
        // Keep record dengan id terkecil untuk tiap (gateway_code, external_id).
        DB::statement("
            DELETE FROM payments p
            USING payments p2
            WHERE p.id > p2.id
              AND COALESCE(p.gateway_code, '') = COALESCE(p2.gateway_code, '')
              AND COALESCE(p.external_id, '') = COALESCE(p2.external_id, '')
        ");

        // Tambahkan unique (gateway_code, external_id)
        Schema::table('payments', function (Blueprint $table) {
            $table->unique(['gateway_code', 'external_id'], 'payments_gateway_external_unique');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique('payments_gateway_external_unique');
        });
    }
};
