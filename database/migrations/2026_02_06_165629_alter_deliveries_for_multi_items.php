<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            // drop unique(order_id) bila ada
            try { $table->dropUnique(['order_id']); } catch (\Throwable $e) {}

            if (!Schema::hasColumn('deliveries', 'delivery_mode')) {
                $table->string('delivery_mode')->default('email_only')->index(); // one_time / email_only
            }
            if (!Schema::hasColumn('deliveries', 'emailed_at')) {
                $table->timestamp('emailed_at')->nullable()->index();
            }

            // pastikan tidak duplicate license dalam satu order
            try { $table->unique(['order_id', 'license_id']); } catch (\Throwable $e) {}
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            // optional rollback
        });
    }
};
