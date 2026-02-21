<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'rating')) {
                $table->decimal('rating', 2, 1)->default(0)->after('price'); // 0.0 - 5.0
            }
            if (!Schema::hasColumn('products', 'rating_count')) {
                $table->unsignedInteger('rating_count')->default(0)->after('rating');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'rating_count')) $table->dropColumn('rating_count');
            if (Schema::hasColumn('products', 'rating')) $table->dropColumn('rating');
        });
    }
};
