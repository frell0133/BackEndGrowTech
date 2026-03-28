<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS idx_favorites_user_created_at ON favorites (user_id, created_at DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_favorites_product_rating ON favorites (product_id, rating)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_categories_active_sort_name ON categories (is_active, sort_order, name)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_subcategories_active_sort_name ON subcategories (is_active, sort_order, name)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_subcategories_category_active_sort_name ON subcategories (category_id, is_active, sort_order, name)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_favorites_user_created_at');
        DB::statement('DROP INDEX IF EXISTS idx_favorites_product_rating');
        DB::statement('DROP INDEX IF EXISTS idx_categories_active_sort_name');
        DB::statement('DROP INDEX IF EXISTS idx_subcategories_active_sort_name');
        DB::statement('DROP INDEX IF EXISTS idx_subcategories_category_active_sort_name');
    }
};
