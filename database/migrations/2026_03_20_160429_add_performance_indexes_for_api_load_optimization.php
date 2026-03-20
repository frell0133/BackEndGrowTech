<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        DB::statement('CREATE INDEX IF NOT EXISTS idx_products_active_published_created_at ON products (is_active, is_published, created_at DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_products_subcategory_active_published_created_at ON products (subcategory_id, is_active, is_published, created_at DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_products_active_published_purchases_count ON products (is_active, is_published, purchases_count DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_products_active_published_popularity_score ON products (is_active, is_published, popularity_score DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_products_active_published_rating ON products (is_active, is_published, rating DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_products_category_active_published ON products (category_id, is_active, is_published)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_products_subcategory_active_published ON products (subcategory_id, is_active, is_published)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_products_name_trgm ON products USING gin (name gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_products_slug_trgm ON products USING gin (slug gin_trgm_ops)');

        DB::statement('CREATE INDEX IF NOT EXISTS idx_orders_status_created_at ON orders (status, created_at)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_orders_created_at ON orders (created_at)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_orders_user_created_at ON orders (user_id, created_at)');

        DB::statement('CREATE INDEX IF NOT EXISTS idx_site_settings_public_group_key ON site_settings (is_public, "group", key)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_favorites_product_id ON favorites (product_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_order_items_product_order ON order_items (product_id, order_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_products_active_published_created_at');
        DB::statement('DROP INDEX IF EXISTS idx_products_subcategory_active_published_created_at');
        DB::statement('DROP INDEX IF EXISTS idx_products_active_published_purchases_count');
        DB::statement('DROP INDEX IF EXISTS idx_products_active_published_popularity_score');
        DB::statement('DROP INDEX IF EXISTS idx_products_active_published_rating');
        DB::statement('DROP INDEX IF EXISTS idx_products_category_active_published');
        DB::statement('DROP INDEX IF EXISTS idx_products_subcategory_active_published');
        DB::statement('DROP INDEX IF EXISTS idx_products_name_trgm');
        DB::statement('DROP INDEX IF EXISTS idx_products_slug_trgm');

        DB::statement('DROP INDEX IF EXISTS idx_orders_status_created_at');
        DB::statement('DROP INDEX IF EXISTS idx_orders_created_at');
        DB::statement('DROP INDEX IF EXISTS idx_orders_user_created_at');

        DB::statement('DROP INDEX IF EXISTS idx_site_settings_public_group_key');
        DB::statement('DROP INDEX IF EXISTS idx_favorites_product_id');
        DB::statement('DROP INDEX IF EXISTS idx_order_items_product_order');
    }
};