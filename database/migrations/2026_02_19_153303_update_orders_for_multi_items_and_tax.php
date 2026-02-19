<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // supaya Opsi B bisa: product_id nullable (order punya banyak item)
            // NOTE: butuh doctrine/dbal (di composer.json kamu sudah ada)
            $table->dropForeign(['product_id']);
            $table->foreignId('product_id')->nullable()->change();
            $table->unsignedInteger('qty')->nullable()->change();

            // re-add FK: kalau product dihapus, order tetap ada (product_id jadi null)
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();

            // tax snapshot di order (default 0)
            $table->unsignedSmallInteger('tax_percent')->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['product_id']);

            // balikin ke non-null (kalau kamu rollback, pastikan data aman)
            $table->foreignId('product_id')->nullable(false)->change();
            $table->unsignedInteger('qty')->nullable(false)->default(1)->change();

            $table->foreign('product_id')->references('id')->on('products')->restrictOnDelete();

            $table->dropColumn(['tax_percent', 'tax_amount']);
        });
    }
};
