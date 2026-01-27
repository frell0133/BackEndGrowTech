<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('withdraw_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('withdraw_requests', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->index();
            }
            if (!Schema::hasColumn('withdraw_requests', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable()->index();
            }
            if (!Schema::hasColumn('withdraw_requests', 'paid_at')) {
                $table->timestamp('paid_at')->nullable()->index();
            }
            if (!Schema::hasColumn('withdraw_requests', 'reject_reason')) {
                $table->text('reject_reason')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('withdraw_requests', function (Blueprint $table) {
            foreach (['approved_at','rejected_at','paid_at','reject_reason'] as $col) {
                if (Schema::hasColumn('withdraw_requests', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
