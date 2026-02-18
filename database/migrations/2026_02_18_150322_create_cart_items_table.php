<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('cart_id')
                ->constrained('carts')
                ->cascadeOnDelete();

            $table->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnDelete();

            $table->unsignedInteger('qty')->default(1);
            $table->timestamps();

            $table->unique(['cart_id', 'product_id'], 'cart_items_cart_product_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
    }
};
