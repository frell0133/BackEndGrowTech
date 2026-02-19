<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            $table->unsignedInteger('qty')->default(1);

            // snapshot pricing
            $table->decimal('unit_price', 14, 2);
            $table->decimal('line_subtotal', 14, 2);

            // optional snapshot info biar invoice stabil walau product berubah
            $table->string('product_name')->nullable();
            $table->string('product_slug')->nullable();

            $table->timestamps();

            $table->index(['order_id']);
            $table->index(['product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
