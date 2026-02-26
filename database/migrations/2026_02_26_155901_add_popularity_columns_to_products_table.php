<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'purchases_count')) {
                $table->unsignedInteger('purchases_count')->default(0)->after('rating_count');
            }
            if (!Schema::hasColumn('products', 'popularity_score')) {
                $table->decimal('popularity_score', 10, 2)->default(0)->after('purchases_count');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'popularity_score')) $table->dropColumn('popularity_score');
            if (Schema::hasColumn('products', 'purchases_count')) $table->dropColumn('purchases_count');
        });
    }
};