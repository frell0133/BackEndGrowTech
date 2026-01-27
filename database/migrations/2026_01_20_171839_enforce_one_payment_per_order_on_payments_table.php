<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bersihin data duplikat payment per order (kalau ada),
        // keep yang id terkecil untuk setiap order_id.
        DB::statement("
            DELETE FROM payments p
            USING payments p2
            WHERE p.id > p2.id
              AND p.order_id = p2.order_id
        ");

        Schema::table('payments', function (Blueprint $table) {
            // Enforce 1 order = 1 payment
            $table->unique(['order_id'], 'payments_order_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique('payments_order_id_unique');
        });
    }
};
