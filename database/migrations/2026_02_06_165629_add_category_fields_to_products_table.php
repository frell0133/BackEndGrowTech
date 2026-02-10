<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'category_id')) {
                $table->foreignId('category_id')->nullable()->after('id')
                    ->constrained('categories')->nullOnDelete();
            }
            if (!Schema::hasColumn('products', 'subcategory_id')) {
                $table->foreignId('subcategory_id')->nullable()->after('category_id')
                    ->constrained('subcategories')->nullOnDelete();
            }

            if (!Schema::hasColumn('products', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('tier_pricing');
            }
            if (!Schema::hasColumn('products', 'is_published')) {
                $table->boolean('is_published')->default(false)->after('is_active');
            }
            if (!Schema::hasColumn('products', 'duration_days')) {
                $table->integer('duration_days')->nullable()->after('type'); // 7 / 30 / dst
            }

            $table->index(['category_id', 'subcategory_id', 'is_active', 'is_published']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // optional rollback (boleh kamu biarin kosong kalau gak butuh)
        });
    }
};
