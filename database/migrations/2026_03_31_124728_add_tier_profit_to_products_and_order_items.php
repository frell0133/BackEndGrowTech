<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'tier_profit')) {
                $table->jsonb('tier_profit')->nullable()->after('tier_pricing');
            }
        });

        Schema::table('order_items', function (Blueprint $table) {
            if (!Schema::hasColumn('order_items', 'unit_profit')) {
                $table->decimal('unit_profit', 14, 2)->nullable()->after('unit_price');
            }

            if (!Schema::hasColumn('order_items', 'line_profit')) {
                $table->decimal('line_profit', 14, 2)->nullable()->after('line_subtotal');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (Schema::hasColumn('order_items', 'line_profit')) {
                $table->dropColumn('line_profit');
            }

            if (Schema::hasColumn('order_items', 'unit_profit')) {
                $table->dropColumn('unit_profit');
            }
        });

        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'tier_profit')) {
                $table->dropColumn('tier_profit');
            }
        });
    }
};
