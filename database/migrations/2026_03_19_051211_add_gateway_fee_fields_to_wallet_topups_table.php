<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_topups', function (Blueprint $table) {
            if (!Schema::hasColumn('wallet_topups', 'gateway_fee_percent')) {
                $table->decimal('gateway_fee_percent', 6, 3)
                    ->default(0)
                    ->after('amount');
            }

            if (!Schema::hasColumn('wallet_topups', 'gateway_fee_amount')) {
                $table->decimal('gateway_fee_amount', 14, 2)
                    ->default(0)
                    ->after('gateway_fee_percent');
            }
        });
    }

    public function down(): void
    {
        Schema::table('wallet_topups', function (Blueprint $table) {
            if (Schema::hasColumn('wallet_topups', 'gateway_fee_amount')) {
                $table->dropColumn('gateway_fee_amount');
            }

            if (Schema::hasColumn('wallet_topups', 'gateway_fee_percent')) {
                $table->dropColumn('gateway_fee_percent');
            }
        });
    }
};  