<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            if (!Schema::hasColumn('licenses', 'order_id')) {
                $table->foreignId('order_id')->nullable()
                    ->constrained('orders')
                    ->nullOnDelete()
                    ->index();
            }

            if (!Schema::hasColumn('licenses', 'sold_at')) {
                $table->timestamp('sold_at')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            if (Schema::hasColumn('licenses', 'order_id')) {
                $table->dropConstrainedForeignId('order_id');
            }
            if (Schema::hasColumn('licenses', 'sold_at')) {
                $table->dropColumn('sold_at');
            }
        });
    }
};